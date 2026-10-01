<?php
// Usage: php tools/cluster_eval.php <thresholdA> <thresholdB>
// Re-clusters the stored articles in memory at two thresholds and prints merges that only B makes.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

$a = (float) ($argv[1] ?? 0.6);
$b = (float) ($argv[2] ?? 0.5);
$rows = q('SELECT a.id, a.title, s.name FROM articles a JOIN sources s ON s.id = a.source_id ORDER BY a.published_at')->fetchAll();

$run = static function (float $threshold) use ($rows): array {
    $c = new Clusterer($threshold);
    $ref = new ReflectionProperty(Clusterer::class, 'loaded');
    $ref->setValue($c, true); // start empty instead of loading stored clusters
    $out = [];
    foreach ($rows as $r) {
        $out[$r['id']] = $c->assign((int) $r['id'], Clusterer::tokens($r['title']));
    }
    return $out;
};
$ca = $run($a);
$cb = $run($b);
$titles = array_column($rows, null, 'id');

$groups = static function (array $assign): array {
    $g = [];
    foreach ($assign as $id => $cluster) {
        $g[$cluster][] = $id;
    }
    return array_filter($g, fn ($ids) => count($ids) > 1);
};
printf("threshold %.2f: %d multi-article clusters | threshold %.2f: %d\n\n", $a, count($groups($ca)), $b, count($groups($cb)));

$shown = 0;
foreach ($groups($cb) as $ids) {
    $aClusters = array_unique(array_map(fn ($id) => $ca[$id], $ids));
    if (count($aClusters) > 1 && $shown++ < 25) {
        echo "--- merged only at {$b}\n";
        foreach (array_slice($ids, 0, 6) as $id) {
            echo "  [{$titles[$id]['name']}] {$titles[$id]['title']}\n";
        }
    }
}
