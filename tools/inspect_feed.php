<?php
// Usage: php tools/inspect_feed.php <url> [--raw]
// Shows HTTP status, the first raw item and the parsed result for a feed or page URL.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

$url = $argv[1] ?? exit("Usage: php tools/inspect_feed.php <url> [--raw]\n");
$r = Http::fetch($url);
echo "HTTP {$r['status']} {$r['error']}\nFinal URL: {$r['final_url']}\nContent-Type: " . ($r['headers']['content-type'] ?? '-') . "\n\n";

if (!FeedParser::looksLikeFeed($r['body'])) {
    echo "Not a feed. Discovery result:\n";
    print_r(SourceDiscovery::discover($url));
    exit;
}
if (in_array('--raw', $argv, true) && preg_match('#<(item|entry)[\s>].*?</\1>#s', $r['body'], $m)) {
    echo "First raw item:\n" . mb_substr($m[0], 0, 3000) . "\n\n";
}
$feed = FeedParser::parse($r['body'], $r['final_url']);
$feed['items'] = array_slice($feed['items'], 0, 2);
print_r($feed);
