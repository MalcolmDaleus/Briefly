<?php
declare(strict_types=1);

final class Pages
{
    public static function home(): void
    {
        if (Auth::user()) {
            self::feed();
            return;
        }
        view('home', [
            'title' => config('app_name'),
            'bodyClass' => 'page-home',
            'canRegister' => Auth::registrationOpen(),
        ]);
    }

    public static function feed(): void
    {
        Auth::requireUser();
        $geos = [];
        foreach (Sources::GEO as $key => $info) {
            $geos[$key] = $info[1];
        }
        view('feed', [
            'title' => config('app_name'),
            'bodyClass' => 'page-feed',
            'categories' => Categories::LABELS,
            'geos' => $geos,
            'languages' => Sources::LANGUAGES,
            'withinOptions' => [
                '1h' => '1 hour',
                '3h' => '3 hours',
                '12h' => '12 hours',
                '24h' => '24 hours',
                '3d' => '3 days',
                '7d' => '7 days',
            ],
            'batchSize' => (int) config('feed_batch_size', 15),
        ]);
    }

    public static function saved(): void
    {
        $user = Auth::requireUser();
        view('saved', [
            'title' => 'Saved · ' . config('app_name'),
            'bodyClass' => 'page-saved',
            'articles' => Feed::saved((int) $user['id']),
        ]);
    }

    public static function seen(): void
    {
        $user = Auth::requireUser();
        view('seen', [
            'title' => 'Seen · ' . config('app_name'),
            'bodyClass' => 'page-seen',
            'articles' => Feed::seen((int) $user['id']),
        ]);
    }

    public static function login(): void
    {
        if (Auth::user()) {
            redirect('/');
        }
        $error = null;
        if (is_post()) {
            if (Auth::attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
                redirect('/');
            }
            $error = 'That email and password combination did not work.';
        }
        view('auth', [
            'title' => 'Log in',
            'mode' => 'login',
            'error' => $error,
            'bodyClass' => 'page-auth',
            'old' => $_POST,
            'canRegister' => Auth::registrationOpen(),
        ]);
    }

    public static function register(): void
    {
        if (Auth::user()) {
            redirect('/');
        }
        if (!Auth::registrationOpen()) {
            redirect('/login');
        }
        $error = null;
        if (is_post()) {
            $error = Auth::register(
                (string) ($_POST['first_name'] ?? ''),
                (string) ($_POST['last_name'] ?? ''),
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['password'] ?? '')
            );
            if ($error === null) {
                redirect('/');
            }
        }
        $first = (int) q('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
        view('auth', [
            'title' => 'Create account',
            'mode' => 'register',
            'error' => $error,
            'first' => $first,
            'bodyClass' => 'page-auth',
            'old' => $_POST,
            'canRegister' => true,
        ]);
    }

    public static function logout(): void
    {
        Auth::logout();
        redirect('/');
    }

    public static function manifest(): void
    {
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode([
            'name' => config('app_name'),
            'short_name' => config('app_name'),
            'start_url' => url('/'),
            'scope' => url('/'),
            'display' => 'standalone',
            'background_color' => '#0d0d12',
            'theme_color' => '#0d0d12',
            'icons' => [
                ['src' => url('assets/icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
                ['src' => url('assets/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => url('assets/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ],
            // Android: share any page to Briefly to add its site as a source
            'share_target' => [
                'action' => url('admin/sources/new'),
                'method' => 'GET',
                'params' => ['title' => 'title', 'text' => 'text', 'url' => 'url'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
