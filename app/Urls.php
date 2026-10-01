<?php
declare(strict_types=1);

final class Urls
{
    private const TRACKING = '/^(utm_.*|fbclid|gclid|dclid|msclkid|mc_cid|mc_eid|_ga|_gl|ref|ref_src|referrer|source|cmpid|cmp|ncid|ocid|smid|smtyp|sh|ito|intcmp|int_source|at_.*|ns_.*|rss|rssfeed|feed|taid|traffic_source|partner|xtor|__twitter_impression|s_cid|cid|mbid|guccounter|seccion|origen|share|output)$/i';

    /** Normalized form used for deduplication: no scheme, no www, no tracking params, no fragment. */
    public static function normalize(string $url): string
    {
        $p = parse_url(trim($url));
        if (!$p || empty($p['host'])) {
            return mb_strtolower(trim($url));
        }
        $host = preg_replace('/^(www\d?|m|amp|mobile)\./', '', strtolower($p['host']));
        $path = preg_replace('#/+#', '/', $p['path'] ?? '/');
        $path = preg_replace('#/(amp|index\.html?)$#i', '', $path);
        $path = $path === '/' ? '' : rtrim($path, '/');
        $query = self::filterQuery($p['query'] ?? '', true);
        return $host . $path . ($query !== '' ? '?' . $query : '');
    }

    public static function hash(string $url): string
    {
        return sha1(self::normalize($url));
    }

    /** Readable canonical URL: keeps scheme/host, drops tracking params and fragment. */
    public static function clean(string $url): string
    {
        $p = parse_url(trim($url));
        if (!$p || empty($p['host'])) {
            return trim($url);
        }
        $scheme = strtolower($p['scheme'] ?? 'https');
        $port = isset($p['port']) ? ':' . $p['port'] : '';
        $query = self::filterQuery($p['query'] ?? '', false);
        return $scheme . '://' . strtolower($p['host']) . $port . ($p['path'] ?? '/') . ($query !== '' ? '?' . $query : '');
    }

    public static function absolute(?string $url, ?string $base): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
            return $url;
        }
        if (!$base) {
            return null;
        }
        $b = parse_url($base);
        if (!$b || empty($b['host'])) {
            return null;
        }
        $origin = ($b['scheme'] ?? 'https') . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        return $origin . $dir . $url;
    }

    public static function domain(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);
        return $host ? preg_replace('/^www\d?\./', '', strtolower($host)) : null;
    }

    private static function filterQuery(string $query, bool $sort): string
    {
        if ($query === '') {
            return '';
        }
        $keep = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = urldecode(explode('=', $pair, 2)[0]);
            if (!preg_match(self::TRACKING, $name)) {
                $keep[] = $pair;
            }
        }
        if ($sort) {
            sort($keep);
        }
        return implode('&', $keep);
    }
}
