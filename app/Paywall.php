<?php
declare(strict_types=1);

/**
 * Best-effort paywall detection. Soft/metered walls are often invisible in RSS
 * and only partly visible in HTML. We never try to bypass a wall — only label it.
 *
 * HTML checks are intentionally strict: many free sites load Piano/Tinypass/consent
 * scripts that used to cause false positives.
 */
final class Paywall
{
    public const UNKNOWN = 'unknown';
    public const FREE = 'free';
    public const LIKELY = 'likely';
    public const PAYWALLED = 'paywalled';

    /** Strong labels often used in feed categories / titles. */
    private const FEED_STRONG = [
        'subscriber only', 'subscribers only', 'subscriber-only', 'subscribers-only',
        'members only', 'members-only', 'member only', 'premium only',
        'paid content', 'para suscriptores', 'solo suscriptores',
        'exclusivo suscriptores', 'contenido premium', 'premium content',
        'exclusive for subscribers', 'for subscribers only',
    ];

    private const FEED_SOFT = [
        'premium', 'subscriber', 'subscribers', 'suscriptor', 'suscriptores',
        'membership', 'members', 'metered', 'paywall',
    ];

    /** Clear user-facing copy that a wall is up. */
    private const HTML_STRONG = [
        'subscribe to continue reading',
        'subscription to continue reading',
        'you\'ve reached your article limit',
        'you\'ve reached your free article limit',
        'meter expired',
        'suscríbete para continuar leyendo',
        'suscribete para continuar leyendo',
        'suscríbete para seguir leyendo',
        'suscribete para seguir leyendo',
        'contenido exclusivo para suscriptores',
        'este contenido es solo para suscriptores',
        'para seguir leyendo, suscríbete',
    ];

    /**
     * Schema.org paywall flags. Alone they false-positive often (templates, AMP,
     * related stories). Count only when paired with a structural or vendor marker.
     */
    private const HTML_SCHEMA = [
        'isaccessibleforfree":false',
        'isaccessibleforfree": false',
        '"isaccessibleforfree":false',
        'cssselector":".paywall',
        'cssselector":"#paywall',
        'classname":"paywall',
    ];

    /**
     * Structural DOM markers that usually mean a wall is present in the markup.
     * One of these alone is not enough (templates often ship unused paywall HTML).
     */
    private const HTML_STRUCTURAL = [
        'id="paywall"', 'id=\'paywall\'',
        'paywall-overlay', 'subscribe-wall', 'subscription-wall',
        'registration-wall', 'gated-content', 'subscriber-only',
        'premium-article', 'article-paywall', 'fortress-paywall',
    ];

    /**
     * Vendor scripts many free sites still load. Never enough on their own —
     * they must appear with structural markers or strong copy.
     */
    private const HTML_VENDOR = [
        'tinypass', 'tp-modal', 'tp-backdrop',
        'piano-offer', 'piano-template', 'data-piano-',
        'fc-ab-root',
    ];

    /**
     * Inspect RSS/Atom fields only. Returns PAYWALLED, LIKELY, or UNKNOWN
     * (never FREE — absence of labels does not mean free).
     */
    public static function fromFeed(?string $title, ?string $excerpt, array $categories = [], array $tags = []): array
    {
        $parts = array_merge(
            [$title ?? '', $excerpt ?? ''],
            $categories,
            $tags
        );
        $hay = fold(implode(' · ', $parts));
        foreach (self::FEED_STRONG as $needle) {
            if (str_contains($hay, $needle)) {
                return ['status' => self::PAYWALLED, 'reason' => 'Feed labels this as subscriber/premium content'];
            }
        }
        // Soft tokens only count as category/tag hits, not loose title words like "premium bond".
        $labelHay = fold(implode(' · ', array_merge($categories, $tags)));
        foreach (self::FEED_SOFT as $needle) {
            if ($labelHay !== '' && preg_match('/(?:^|[^a-z0-9])' . preg_quote($needle, '/') . '(?:[^a-z0-9]|$)/', $labelHay)) {
                return ['status' => self::LIKELY, 'reason' => 'Feed category/tag suggests premium content'];
            }
        }
        return ['status' => self::UNKNOWN, 'reason' => null];
    }

    /** Fetch the article HTML and classify. Does not bypass anything. */
    public static function checkUrl(string $url): array
    {
        if (!is_http_url($url)) {
            return ['status' => self::UNKNOWN, 'reason' => 'Invalid URL', 'http_status' => 0];
        }
        $res = Http::fetch($url, [
            'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
        ], min(10, (int) config('fetch_timeout', 12)));

        $status = (int) ($res['status'] ?? 0);
        // 402 / 451 are real access walls. 401 / 403 are usually bot / CDN blocks → unknown.
        if ($status === 402 || $status === 451) {
            return [
                'status' => self::PAYWALLED,
                'reason' => "Publisher returned HTTP {$status} (access restricted)",
                'http_status' => $status,
            ];
        }
        if ($status < 200 || $status >= 400 || ($res['body'] ?? '') === '') {
            return [
                'status' => self::UNKNOWN,
                'reason' => $res['error'] ?: "Could not fetch article (HTTP {$status})",
                'http_status' => $status,
            ];
        }

        $html = strtolower((string) $res['body']);
        if (strlen($html) > 500000) {
            $html = substr($html, 0, 500000);
        }
        $html = fold($html);

        foreach (self::HTML_STRONG as $needle) {
            $n = fold($needle);
            if (str_contains($html, $n)) {
                return [
                    'status' => self::PAYWALLED,
                    'reason' => 'Matched: ' . $needle,
                    'http_status' => $status,
                ];
            }
        }

        $structural = [];
        foreach (self::HTML_STRUCTURAL as $needle) {
            if (str_contains($html, fold($needle))) {
                $structural[] = $needle;
            }
        }
        $vendor = [];
        foreach (self::HTML_VENDOR as $needle) {
            if (str_contains($html, fold($needle))) {
                $vendor[] = $needle;
            }
        }
        $schema = [];
        foreach (self::HTML_SCHEMA as $needle) {
            if (str_contains($html, fold($needle))) {
                $schema[] = $needle;
            }
        }

        // Schema flag only counts with structural or vendor evidence (cuts template FPs).
        if ($schema !== [] && ($structural !== [] || $vendor !== [])) {
            return [
                'status' => self::PAYWALLED,
                'reason' => 'Matched: ' . $schema[0] . ' + ' . ($structural[0] ?? $vendor[0]),
                'http_status' => $status,
            ];
        }

        // 2+ structural markers, or structural + vendor script together.
        if (count($structural) >= 2) {
            return [
                'status' => self::LIKELY,
                'reason' => 'Matched page markers: ' . implode(', ', array_slice($structural, 0, 3)),
                'http_status' => $status,
            ];
        }
        if (count($structural) >= 1 && count($vendor) >= 1) {
            return [
                'status' => self::LIKELY,
                'reason' => 'Matched: ' . $structural[0] . ' + ' . $vendor[0],
                'http_status' => $status,
            ];
        }

        return [
            'status' => self::FREE,
            'reason' => null,
            'http_status' => $status,
        ];
    }

    /** Persist a status on the article row. */
    public static function store(int $articleId, string $status, ?string $reason = null): void
    {
        if (!in_array($status, [self::UNKNOWN, self::FREE, self::LIKELY, self::PAYWALLED], true)) {
            return;
        }
        q(
            'UPDATE articles SET paywall_status = ?, paywall_reason = ?, paywall_checked_at = ?, updated_at = ? WHERE id = ?',
            [$status, $reason, now_utc(), now_utc(), $articleId]
        );
    }

    public static function markSourceSafe(int $sourceId): void
    {
        q('UPDATE sources SET paywall_safe = 1, updated_at = ? WHERE id = ?', [now_utc(), $sourceId]);
        // Clear prior false positives from this source so they return to the pool.
        q(
            "UPDATE articles SET paywall_status = 'free', paywall_reason = 'Source marked safe', paywall_checked_at = ?, updated_at = ?
             WHERE source_id = ? AND paywall_status IN ('paywalled', 'likely')",
            [now_utc(), now_utc(), $sourceId]
        );
    }
}
