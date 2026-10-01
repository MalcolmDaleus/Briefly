<?php
declare(strict_types=1);

/**
 * Turns any URL (a feed, a homepage, or an article page) into a candidate source:
 * finds the feed, test-parses it and guesses name/language/geography for review.
 */
final class SourceDiscovery
{
    private const COMMON_PATHS = ['/feed', '/rss', '/feed/', '/rss.xml', '/feed.xml', '/index.xml', '/atom.xml', '/rss/', '/feeds/rss'];

    public static function discover(string $input): array
    {
        $url = trim($input);
        if ($url === '') {
            return ['ok' => false, 'error' => 'Enter a URL.'];
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        if (!is_http_url($url)) {
            return ['ok' => false, 'error' => 'That does not look like a web address.'];
        }

        $page = Http::fetch($url);
        if ($page['status'] === 200 && FeedParser::looksLikeFeed($page['body'])) {
            return self::fromFeed($page['final_url'] ?: $url, $page['body'], null);
        }
        if ($page['status'] !== 200) {
            return ['ok' => false, 'error' => "Could not load {$url} (HTTP {$page['status']}" . ($page['error'] ? ", {$page['error']}" : '') . ').'];
        }

        $html = $page['body'];
        $base = $page['final_url'] ?: $url;
        $meta = self::pageMeta($html, $base);

        $candidates = self::alternateLinks($html, $base);
        $origin = self::origin($base);
        foreach (self::COMMON_PATHS as $path) {
            $candidates[] = $origin . $path;
        }
        $candidates = array_values(array_unique($candidates));

        foreach (array_chunk($candidates, 6) as $chunk) {
            $results = Http::fetchMany(array_map(fn ($u) => ['url' => $u], $chunk), 10);
            foreach ($chunk as $i => $candidate) {
                $r = $results[$i];
                if ($r['status'] === 200 && FeedParser::looksLikeFeed($r['body'])) {
                    $found = self::fromFeed($r['final_url'] ?: $candidate, $r['body'], $meta);
                    if ($found['ok'] && $found['item_count'] > 0) {
                        return $found;
                    }
                }
            }
        }
        return ['ok' => false, 'error' => 'No RSS/Atom feed found on that page. Try pasting the feed URL directly.', 'page' => $meta];
    }

    private static function fromFeed(string $feedUrl, string $body, ?array $meta): array
    {
        try {
            $feed = FeedParser::parse($body, $feedUrl);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Found a feed but could not read it: ' . $e->getMessage()];
        }
        $homepage = $meta['url'] ?? (is_http_url($feed['link']) ? $feed['link'] : self::origin($feedUrl));
        $homepage = self::origin($homepage) === rtrim($homepage, '/') ? self::origin($homepage) : $homepage;
        $domain = Urls::domain($homepage) ?? Urls::domain($feedUrl);
        $language = self::language($meta['language'] ?? null) ?? self::language($feed['language']) ?? self::guessLanguage($feed['items']);
        [$geo, $country] = self::guessGeo($domain, $language);

        return [
            'ok' => true,
            'feed_url' => $feedUrl,
            'homepage_url' => $homepage,
            'domain' => $domain,
            'name' => $meta['site_name'] ?? self::cleanName($feed['title']) ?? $domain,
            'language' => $language ?? 'en',
            'geo_group' => $geo,
            'country' => $country,
            'feed_type' => $feed['format'] === 'atom' ? 'atom' : ($feed['format'] === 'rdf' ? 'rdf' : 'rss'),
            'item_count' => count($feed['items']),
            'profile' => Retrieval::profile($feed),
            'existing' => Sources::findByFeed($feedUrl),
        ];
    }

    private static function alternateLinks(string $html, string $base): array
    {
        $links = [];
        if (preg_match_all('/<link\b[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (!preg_match('/rel\s*=\s*["\']?alternate/i', $tag) || !preg_match('#type\s*=\s*["\']?application/(rss|atom)\+xml#i', $tag)) {
                    continue;
                }
                if (preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $m)) {
                    $href = Urls::absolute(html_entity_decode($m[1]), $base);
                    if ($href && !preg_match('#/comments?/|comments/feed|/feed/?\?.*comments#i', $href)) {
                        $links[] = $href;
                    }
                }
            }
        }
        return $links;
    }

    private static function pageMeta(string $html, string $base): array
    {
        $meta = ['url' => self::origin($base)];
        if (preg_match('/<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)
            || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:site_name["\']/i', $html, $m)) {
            $meta['site_name'] = clean_text($m[1], 80);
        } elseif (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $meta['site_name'] = self::cleanName(clean_text($m[1], 120));
        }
        if (preg_match('/<html[^>]+lang=["\']?([a-zA-Z-]+)/i', $html, $m)) {
            $meta['language'] = $m[1];
        }
        return $meta;
    }

    private static function cleanName(?string $title): ?string
    {
        if (!$title) {
            return null;
        }
        // "Portada | El Diario" / "Home - Site" / "Site: latest news" → keep the most name-like part
        $parts = preg_split('/\s+[|\-–—:·]\s+/u', $title) ?: [$title];
        usort($parts, fn ($a, $b) => mb_strlen($a) <=> mb_strlen($b));
        foreach ($parts as $p) {
            if (!preg_match('/^(home|homepage|portada|inicio|latest|news|noticias|rss|feed)$/i', trim($p))) {
                return trim($p);
            }
        }
        return trim($title);
    }

    private static function origin(string $url): string
    {
        $p = parse_url($url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    private static function language(?string $lang): ?string
    {
        $code = strtolower(substr((string) $lang, 0, 2));
        return isset(Sources::LANGUAGES[$code]) ? $code : null;
    }

    private static function guessLanguage(array $items): ?string
    {
        return guess_language(implode(' ', array_map(fn ($i) => (string) $i['title'], array_slice($items, 0, 15))));
    }

    /** @return array{0: string, 1: ?string} [geo_group, country] */
    private static function guessGeo(?string $domain, ?string $language): array
    {
        $tld = $domain ? substr((string) strrchr($domain, '.'), 1) : '';
        return match (true) {
            $domain && preg_match('/alicante|alacant|elche|benidorm|torrevieja/', $domain) === 1 => ['es-alc', 'ES'],
            $domain && preg_match('/valencia|castellon|levante|comunitat/', $domain) === 1 => ['es-vc', 'ES'],
            $domain && preg_match('/nyc|newyork|brooklyn|queens|bronx|gothamist/', $domain) === 1 => ['us-ny', 'US'],
            $tld === 'es' => ['es', 'ES'],
            $tld === 'eu' => ['eu', 'EU'],
            $tld === 'uk' => ['uk', 'GB'],
            in_array($tld, ['de', 'fr', 'it', 'nl', 'be', 'pt', 'ie', 'pl', 'se', 'dk', 'fi', 'no', 'at', 'ch', 'gr', 'cz', 'hu', 'ro', 'bg', 'hr', 'si', 'sk', 'lt', 'lv', 'ee', 'ua'], true) => ['europe', strtoupper($tld === 'uk' ? 'GB' : $tld)],
            $tld === 'us' || ($tld === 'com' && $language === 'en') || $tld === 'org' => ['us', 'US'],
            $language === 'es' => ['es', 'ES'],
            default => ['intl', null],
        };
    }
}
