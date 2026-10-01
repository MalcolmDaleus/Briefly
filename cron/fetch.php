<?php
// Background retrieval. Schedule every 15 minutes:
//   php /path/to/site/cron/fetch.php
// Options: --force  fetch every active source (still respecting the 15-minute minimum and backoff)
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

$stats = Retrieval::topUp('cron', in_array('--force', $argv, true));
echo json_encode($stats, JSON_UNESCAPED_SLASHES) . PHP_EOL;
