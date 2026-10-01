<?php
// Usage: php tools/recluster.php
// Recomputes title tokens and story clusters for all stored articles (after tuning Clusterer or cluster_threshold).
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

$rows = q('SELECT id, title FROM articles ORDER BY published_at')->fetchAll();
$clusterer = new Clusterer();
(new ReflectionProperty(Clusterer::class, 'loaded'))->setValue($clusterer, true);
$update = db()->prepare('UPDATE articles SET title_tokens = ?, cluster_id = ? WHERE id = ?');
Db::transaction(function () use ($rows, $clusterer, $update) {
    foreach ($rows as $r) {
        $tokens = Clusterer::tokens($r['title']);
        $update->execute([implode(' ', $tokens), $clusterer->assign((int) $r['id'], $tokens), $r['id']]);
    }
});
$multi = (int) q('SELECT COUNT(*) FROM (SELECT cluster_id FROM articles GROUP BY cluster_id HAVING COUNT(*) > 1)')->fetchColumn();
echo count($rows) . " articles re-clustered into {$multi} multi-article stories.\n";
