<?php
declare(strict_types=1);

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = DATA_DIR . '/sessions';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $lifetime = (int) config('session_days', 30) * 86400;
        session_save_path($dir);
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.use_strict_mode', '1');
        session_name('briefly');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => base_path() === '' ? '/' : base_path() . '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['user_id'] ?? null;
            if ($id) {
                $row = q('SELECT id, first_name, last_name, email, theme, is_admin, feed_filters FROM users WHERE id = ?', [$id])->fetch();
                self::$user = $row ?: null;
            }
        }
        return self::$user;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if ($user) {
            return $user;
        }
        if (str_starts_with(request_path(), '/api/')) {
            json_out(['error' => 'Not logged in'], 401);
        }
        redirect('/');
    }

    public static function requireAdmin(): array
    {
        $user = self::requireUser();
        if (!$user['is_admin']) {
            http_response_code(403);
            view('message', ['title' => 'Admins only', 'message' => 'You need an admin account for this page.']);
            exit;
        }
        return $user;
    }

    public static function registrationOpen(): bool
    {
        return (bool) config('allow_registration', false)
            || (int) q('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    }

    public static function attempt(string $email, string $password): bool
    {
        $row = q('SELECT id, password_hash FROM users WHERE email = ?', [trim($email)])->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            usleep(400000);
            return false;
        }
        if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
            q('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), now_utc(), $row['id']]);
        }
        self::loginId((int) $row['id']);
        return true;
    }

    /** @return string|null error message */
    public static function register(string $first, string $last, string $email, string $password): ?string
    {
        $first = trim($first);
        $last = trim($last);
        $email = trim($email);
        if ($first === '' || $last === '') {
            return 'Please enter your first and last name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Please enter a valid email address.';
        }
        if (mb_strlen($password) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (q('SELECT 1 FROM users WHERE email = ?', [$email])->fetchColumn()) {
            return 'An account with that email already exists.';
        }
        $isFirst = (int) q('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
        $now = now_utc();
        q(
            'INSERT INTO users (first_name, last_name, email, password_hash, theme, is_admin, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$first, $last, $email, password_hash($password, PASSWORD_DEFAULT), 'system', $isFirst ? 1 : 0, $now, $now]
        );
        self::loginId((int) db()->lastInsertId());
        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
    }

    private static function loginId(int $id): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $id;
        self::$loaded = false;
    }
}
