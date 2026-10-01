<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

$path = request_path();

// Web cron fallback (no session needed): /tasks/fetch?key=...
if ($path === '/tasks/fetch') {
    $key = (string) ($_GET['key'] ?? '');
    if ($key === '' || !hash_equals((string) config('cron_key'), $key)) {
        json_out(['error' => 'Forbidden'], 403);
    }
    json_out(Retrieval::topUp('cron'));
}

Auth::startSession();
if (is_post()) {
    csrf_check();
}

$routes = [
    'GET /' => [Pages::class, 'home'],
    'GET /saved' => [Pages::class, 'saved'],
    'GET /seen' => [Pages::class, 'seen'],
    'GET /login' => [Pages::class, 'login'],
    'POST /login' => [Pages::class, 'login'],
    'GET /register' => [Pages::class, 'register'],
    'POST /register' => [Pages::class, 'register'],
    'POST /logout' => [Pages::class, 'logout'],
    'GET /manifest.webmanifest' => [Pages::class, 'manifest'],

    'GET /api/feed' => [Api::class, 'feed'],
    'POST /api/seen' => [Api::class, 'seen'],
    'POST /api/save' => [Api::class, 'save'],
    'POST /api/theme' => [Api::class, 'theme'],

    'GET /admin' => [Admin::class, 'sources'],
    'GET /admin/sources' => [Admin::class, 'sources'],
    'GET /admin/sources/new' => [Admin::class, 'newSource'],
    'POST /admin/sources/new' => [Admin::class, 'createSource'],
    'POST /admin/sources/bulk' => [Admin::class, 'bulkAdd'],
    'POST /admin/sources/import-seed' => [Admin::class, 'importSeed'],
    'POST /admin/retrieve' => [Admin::class, 'retrieve'],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$handler = $routes[$method . ' ' . $path] ?? null;
$args = [];
if (!$handler && preg_match('#^/admin/sources/(\d+)(?:/(update|status|active|fetch))?$#', $path, $m)) {
    $action = $m[2] ?? '';
    $handler = match (true) {
        $method === 'GET' && $action === '' => [Admin::class, 'source'],
        $method === 'POST' && $action !== '' => [Admin::class, 'source' . ucfirst($action)],
        default => null,
    };
    $args = [(int) $m[1]];
}

if (!$handler) {
    not_found();
}
[$class, $fn] = $handler;
$class::$fn(...$args);
