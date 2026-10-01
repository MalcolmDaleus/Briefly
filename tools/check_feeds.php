<?php
// Usage: php tools/check_feeds.php [filter]
// Fetches every seed feed (optionally only names/URLs containing [filter]) and reports what it provides.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

$filter = $argv[1] ?? '';
$seed = array_values(array_filter(
    require APP_DIR . '/sources_seed.php',
    fn ($s) => $filter === '' || stripos($s['name'] . $s['feed'], $filter) !== false
));

$ok = 0;
foreach (array_chunk($seed, 8) as $chunk) {
    $results = Http::fetchMany(array_map(fn ($s) => ['url' => $s['feed']], $chunk));
    foreach ($chunk as $i => $s) {
        $r = $results[$i];
        $line = str_pad(mb_substr($s['name'], 0, 28), 30);
        if ($r['status'] !== 200) {
            echo "FAIL {$line} HTTP {$r['status']} {$r['error']}\n";
            continue;
        }
        try {
            $feed = FeedParser::parse($r['body'], $r['final_url']);
        } catch (Throwable $e) {
            echo "FAIL {$line} " . $e->getMessage() . "\n";
            continue;
        }
        $items = $feed['items'];
        $n = count($items);
        $count = fn (string $f) => count(array_filter($items, fn ($it) => !empty($it[$f])));
        $newest = max(array_map(fn ($it) => $it['published_ts'] ?? 0, $items) ?: [0]);
        $age = $newest ? round((time() - $newest) / 3600, 1) . 'h' : '-';
        $status = $n === 0 ? 'EMPTY' : 'ok  ';
        if ($n > 0) {
            $ok++;
        }
        printf(
            "%s %s %s items=%d newest=%s img=%d exc=%d auth=%d cat=%d date=%d\n",
            $status, $line, $feed['format'], $n, $age, $count('image_url'), $count('excerpt'), $count('author'), $count('categories'), $count('published_ts')
        );
    }
}
echo "\n{$ok}/" . count($seed) . " feeds OK\n";
