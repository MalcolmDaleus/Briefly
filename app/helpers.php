<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Config, data directory, small state file
// ---------------------------------------------------------------------------

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    $htaccess = DATA_DIR . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }
    $local = DATA_DIR . '/config.php';
    if (!is_file($local)) {
        $key = bin2hex(random_bytes(16));
        file_put_contents($local, "<?php\n// Local overrides for app/config.php. Kept across deployments.\nreturn [\n    'cron_key' => '{$key}',\n];\n");
    }
}

function config(string $key, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require APP_DIR . '/config.php';
        $local = require DATA_DIR . '/config.php';
        if (is_array($local)) {
            $config = array_replace($config, $local);
        }
    }
    return $config[$key] ?? $default;
}

function state_get(string $key, mixed $default = null): mixed
{
    $file = DATA_DIR . '/state.json';
    if (!is_file($file)) {
        return $default;
    }
    $data = json_decode((string) file_get_contents($file), true);
    return is_array($data) && array_key_exists($key, $data) ? $data[$key] : $default;
}

function state_set(string $key, mixed $value): void
{
    $file = DATA_DIR . '/state.json';
    $fp = fopen($file, 'c+');
    if (!$fp) {
        return;
    }
    flock($fp, LOCK_EX);
    $data = json_decode((string) stream_get_contents($fp), true);
    $data = is_array($data) ? $data : [];
    $data[$key] = $value;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
}

// ---------------------------------------------------------------------------
// Database shortcuts
// ---------------------------------------------------------------------------

function db(): PDO
{
    return Db::pdo();
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Current UTC time in the storage format used everywhere ('Y-m-d H:i:s'). */
function now_utc(int $offsetSeconds = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offsetSeconds);
}

function iso_utc(?string $dbTime): ?string
{
    return $dbTime ? str_replace(' ', 'T', $dbTime) . 'Z' : null;
}

// ---------------------------------------------------------------------------
// Text helpers
// ---------------------------------------------------------------------------

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Lowercase and strip accents so "Política" and "politica" compare equal. */
function fold(string $s): string
{
    static $map = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'œ' => 'oe', 'æ' => 'ae',
        '’' => "'", '‘' => "'", '“' => '"', '”' => '"',
    ];
    return strtr(mb_strtolower($s), $map);
}

/** Folded text reduced to space-separated words, padded so ' word ' matches whole words. */
function search_text(string ...$parts): string
{
    $s = fold(implode(' ', $parts));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
    return ' ' . trim($s) . ' ';
}

/** 'en' / 'es' when the text clearly reads as one of them (by common function words), else null. */
function guess_language(string $text): ?string
{
    $t = search_text($text);
    $es = preg_match_all('/(?<= )(el|la|los|las|de|del|que|y|en|por|para|con|una|un|se|su|al|es|sus|tras|sobre)(?= )/', $t);
    $en = preg_match_all('/(?<= )(the|of|and|to|in|for|with|on|is|at|by|from|after|over|its|as|are|was|be)(?= )/', $t);
    if ($es + $en < 3) {
        return null;
    }
    if ($es >= 2 * $en + 2) {
        return 'es';
    }
    if ($en >= 2 * $es + 2) {
        return 'en';
    }
    return null;
}

function clean_text(?string $s, int $max = 0): ?string
{
    if ($s === null || $s === '') {
        return null;
    }
    $s = preg_replace('#<(script|style|figure|figcaption)\b[^>]*>.*?</\1>#is', ' ', $s) ?? $s;
    $s = preg_replace('#<(br|/p|/div|/li|/h\d)\b[^>]*>#i', ' ', $s) ?? $s;
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (str_contains($s, '&') && preg_match('/&(#\d+|#x[0-9a-f]+|[a-z]+);/i', $s)) {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = trim($s);
    // Common feed boilerplate
    $s = preg_replace('/\s*The post .{1,300} appeared first on .{1,120}\.?$/u', '', $s) ?? $s;
    $s = preg_replace('/\s*(Continue reading|Read more|Leer más|Seguir leyendo|Sigue leyendo)\.*\s*(»|…|\.\.\.)?\s*$/iu', '', $s) ?? $s;
    $s = trim($s);
    if ($s === '') {
        return null;
    }
    if ($max > 0 && mb_strlen($s) > $max) {
        $cut = mb_substr($s, 0, $max);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }
        $s = rtrim($cut, " ,.;:-–—") . '…';
    }
    return $s;
}

function is_http_url(?string $url): bool
{
    return is_string($url) && preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $url) === 1;
}

function time_ago(?string $dbTime): string
{
    if (!$dbTime) {
        return '';
    }
    $diff = time() - strtotime($dbTime . ' UTC');
    return match (true) {
        $diff < 60 => 'just now',
        $diff < 3600 => floor($diff / 60) . 'm ago',
        $diff < 86400 => floor($diff / 3600) . 'h ago',
        default => floor($diff / 86400) . 'd ago',
    };
}

// ---------------------------------------------------------------------------
// HTTP / routing helpers
// ---------------------------------------------------------------------------

/** Base path of the app ('' at a domain root, '/Briefly' in a subfolder). */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $base = rtrim($dir, '/');
    }
    return $base;
}

function url(string $path = '/', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

function absolute_url(string $path = '/'): string
{
    $https = is_https();
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . url($path);
}

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . $path;
    $v = is_file($file) ? filemtime($file) : 0;
    return url('assets/' . $path) . '?v=' . $v;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = base_path();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    $path = '/' . trim($path, '/');
    return $path === '/index.php' ? '/' : $path;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function redirect(string $path, array $query = []): never
{
    header('Location: ' . url($path, $query));
    exit;
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function request_json(): array
{
    $data = json_decode((string) file_get_contents('php://input'), true);
    return is_array($data) ? $data : $_POST;
}

function not_found(): never
{
    http_response_code(404);
    view('message', ['title' => 'Not found', 'message' => 'That page does not exist.']);
    exit;
}

/** Hand the response to the client, then keep running (for quiet background work). */
function finish_request(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } elseif (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

// ---------------------------------------------------------------------------
// CSRF + views
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(20));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        if (str_starts_with(request_path(), '/api/')) {
            json_out(['error' => 'Invalid or expired session token. Reload the page.'], 403);
        }
        http_response_code(403);
        view('message', ['title' => 'Session expired', 'message' => 'Please go back, reload the page and try again.']);
        exit;
    }
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

/** Render app/views/{name}.php inside the layout (unless $layout is false). */
function view(string $name, array $data = [], bool $layout = true): void
{
    $data['user'] ??= Auth::user();
    extract($data, EXTR_SKIP);
    ob_start();
    require APP_DIR . '/views/' . $name . '.php';
    $content = ob_get_clean();
    if ($layout) {
        require APP_DIR . '/views/layout.php';
    } else {
        echo $content;
    }
}
