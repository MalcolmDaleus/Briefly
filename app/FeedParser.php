<?php
declare(strict_types=1);

/**
 * Parses RSS 2.0, RSS 1.0 (RDF) and Atom into a uniform structure.
 * Every item field is optional except title/url; missing fields are null/empty.
 */
final class FeedParser
{
    private const ATOM = 'http://www.w3.org/2005/Atom';
    private const RSS1 = 'http://purl.org/rss/1.0/';
    private const RDF = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
    private const DC = 'http://purl.org/dc/elements/1.1/';
    private const MEDIA = 'http://search.yahoo.com/mrss/';
    private const CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    private const EXCERPT_MAX = 320;

    public static function looksLikeFeed(string $body): bool
    {
        $head = strtolower(substr(ltrim($body, "\xEF\xBB\xBF \t\r\n"), 0, 1500));
        return (bool) preg_match('/<(rss|feed|rdf:rdf)[\s>]/', $head);
    }

    /**
     * @return array{format: string, title: ?string, link: ?string, language: ?string, items: list<array>}
     */
    public static function parse(string $body, string $feedUrl): array
    {
        $body = ltrim($body, "\xEF\xBB\xBF \t\r\n");
        if ($body === '') {
            throw new RuntimeException('Empty response');
        }
        $doc = self::load($body);
        $root = $doc->documentElement;
        if (!$root) {
            throw new RuntimeException('No XML root element');
        }

        $name = strtolower($root->localName);
        if ($name === 'rss') {
            $channel = self::kid($root, 'channel', null);
            if (!$channel) {
                throw new RuntimeException('RSS feed without <channel>');
            }
            return self::parseRss($channel, self::kids($channel, 'item', null), null, 'rss', $feedUrl);
        }
        if ($name === 'rdf') {
            $channel = self::kid($root, 'channel', self::RSS1);
            return self::parseRss($channel, self::kids($root, 'item', self::RSS1), self::RSS1, 'rdf', $feedUrl);
        }
        if ($name === 'feed') {
            return self::parseAtom($root, $feedUrl);
        }
        throw new RuntimeException("Not a feed (root element <{$root->nodeName}>)");
    }

    private static function load(string $body): DOMDocument
    {
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc = new DOMDocument();
        $ok = $doc->loadXML($body, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        if (!$ok) {
            // Second chance for slightly broken feeds (stray entities, bad characters)
            $fixed = preg_replace('/&(?!(?:#\d+|#x[0-9a-fA-F]+|amp|lt|gt|quot|apos);)/', '&amp;', $body) ?? $body;
            $fixed = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $fixed) ?? $fixed;
            $doc = new DOMDocument();
            $doc->recover = true;
            $ok = $doc->loadXML($fixed, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        }
        $error = libxml_get_last_error();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || !$doc->documentElement) {
            throw new RuntimeException('Invalid XML' . ($error ? ': ' . trim($error->message) : ''));
        }
        return $doc;
    }

    // -----------------------------------------------------------------------

    private static function parseRss(?DOMElement $channel, array $items, ?string $ns, string $format, string $feedUrl): array
    {
        $feedLink = $channel ? self::text(self::kid($channel, 'link', $ns)) : null;
        $base = is_http_url($feedLink) ? $feedLink : $feedUrl;
        $out = [
            'format' => $format,
            'title' => $channel ? clean_text(self::text(self::kid($channel, 'title', $ns))) : null,
            'link' => $feedLink,
            'language' => $channel ? (self::text(self::kid($channel, 'language', $ns)) ?? self::text(self::kid($channel, 'language', self::DC))) : null,
            'items' => [],
        ];

        foreach ($items as $item) {
            $guidEl = self::kid($item, 'guid', $ns);
            $guid = self::text($guidEl);
            $link = self::text(self::kid($item, 'link', $ns));
            if (!$link && $guid && is_http_url($guid) && strtolower($guidEl?->getAttribute('isPermaLink') ?? '') !== 'false') {
                $link = $guid;
            }
            if (!$link && ($atomLink = self::kid($item, 'link', self::ATOM))) {
                $link = $atomLink->getAttribute('href');
            }
            if ($format === 'rdf' && !$guid) {
                $guid = $item->getAttributeNS(self::RDF, 'about') ?: null;
            }

            $descriptionHtml = self::text(self::kid($item, 'description', $ns)) ?? self::text(self::kid($item, 'description', self::DC));
            $contentHtml = self::text(self::kid($item, 'encoded', self::CONTENT));

            $authors = array_map(fn ($el) => self::text($el), self::kids($item, 'creator', self::DC));
            if (!$authors && ($a = self::text(self::kid($item, 'author', $ns)))) {
                // RSS 2.0 style: "email@example.com (Jane Doe)"
                $authors = [preg_match('/\(([^)]+)\)/', $a, $m) ? $m[1] : $a];
            }

            $date = self::text(self::kid($item, 'pubDate', $ns))
                ?? self::text(self::kid($item, 'date', self::DC))
                ?? self::text(self::kid($item, 'updated', self::ATOM));

            $categories = [];
            foreach (self::kids($item, 'category', $ns) as $c) {
                $categories[] = self::text($c);
            }
            foreach (self::kids($item, 'subject', self::DC) as $c) {
                $categories[] = self::text($c);
            }

            $out['items'][] = self::finishItem([
                'title' => self::text(self::kid($item, 'title', $ns)),
                'url' => Urls::absolute($link, $base),
                'external_id' => $guid,
                'description_html' => $descriptionHtml,
                'content_html' => $contentHtml,
                'author' => $authors,
                'date' => $date,
                'categories' => $categories,
                'tags' => self::mediaKeywords($item),
                'image' => self::findImage($item, $descriptionHtml, $contentHtml, $base, $ns),
                'language' => self::text(self::kid($item, 'language', self::DC)),
                'elements' => self::elementNames($item),
            ]);
        }
        return $out;
    }

    private static function parseAtom(DOMElement $feed, string $feedUrl): array
    {
        $feedLink = self::atomLink($feed, $feedUrl);
        $base = $feedLink ?? $feedUrl;
        $out = [
            'format' => 'atom',
            'title' => clean_text(self::text(self::kid($feed, 'title', self::ATOM))),
            'link' => $feedLink,
            'language' => $feed->getAttribute('xml:lang') ?: null,
            'items' => [],
        ];
        foreach (self::kids($feed, 'entry', self::ATOM) as $entry) {
            $summary = self::text(self::kid($entry, 'summary', self::ATOM));
            $content = self::text(self::kid($entry, 'content', self::ATOM));
            $authors = [];
            foreach (self::kids($entry, 'author', self::ATOM) as $a) {
                $authors[] = self::text(self::kid($a, 'name', self::ATOM));
            }
            foreach (self::kids($entry, 'creator', self::DC) as $a) {
                $authors[] = self::text($a);
            }
            $categories = [];
            foreach (self::kids($entry, 'category', self::ATOM) as $c) {
                $categories[] = $c->getAttribute('label') ?: $c->getAttribute('term');
            }
            $out['items'][] = self::finishItem([
                'title' => self::text(self::kid($entry, 'title', self::ATOM)),
                'url' => self::atomLink($entry, $base),
                'external_id' => self::text(self::kid($entry, 'id', self::ATOM)),
                'description_html' => $summary,
                'content_html' => $content,
                'author' => $authors,
                'date' => self::text(self::kid($entry, 'published', self::ATOM)) ?? self::text(self::kid($entry, 'updated', self::ATOM)),
                'categories' => $categories,
                'tags' => self::mediaKeywords($entry),
                'image' => self::findImage($entry, $summary, $content, $base, self::ATOM),
                'language' => $entry->getAttribute('xml:lang') ?: null,
                'elements' => self::elementNames($entry),
            ]);
        }
        return $out;
    }

    private static function finishItem(array $raw): array
    {
        $title = clean_text($raw['title'], 400);
        $excerpt = clean_text($raw['description_html'], self::EXCERPT_MAX)
            ?? clean_text($raw['content_html'], self::EXCERPT_MAX);
        if ($excerpt !== null && $title !== null && fold($excerpt) === fold($title)) {
            $excerpt = null;
        }
        $authors = array_values(array_unique(array_filter(
            array_map(fn ($a) => clean_text($a, 120), $raw['author']),
            fn ($a) => $a !== null && !ctype_digit($a) && !filter_var($a, FILTER_VALIDATE_EMAIL)
        )));
        $categories = array_values(array_unique(array_filter(array_map(fn ($c) => clean_text($c, 80), $raw['categories']))));
        $url = $raw['url'];

        return [
            'title' => $title,
            'url' => is_http_url($url) ? $url : null,
            'external_id' => $raw['external_id'] !== null ? mb_substr($raw['external_id'], 0, 300) : null,
            'excerpt' => $excerpt,
            'image_url' => $raw['image'],
            'author' => $authors ? implode(', ', array_slice($authors, 0, 3)) : null,
            'published_ts' => self::parseDate($raw['date']),
            'categories' => array_slice($categories, 0, 12),
            'tags' => array_slice($raw['tags'], 0, 20),
            'language' => $raw['language'],
            'elements' => $raw['elements'],
        ];
    }

    // -----------------------------------------------------------------------

    private static function findImage(DOMElement $item, ?string $descHtml, ?string $contentHtml, string $base, ?string $ns): ?string
    {
        $best = null;
        $bestWidth = -1;
        $consider = static function (?string $url, int $width = 0) use (&$best, &$bestWidth, $base): void {
            $url = Urls::absolute(html_entity_decode((string) $url), $base);
            if (!is_http_url($url) || preg_match('/(pixel|spacer|blank|tracking|1x1|feedburner\.com\/~)/i', $url)) {
                return;
            }
            if ($width > $bestWidth) {
                $best = $url;
                $bestWidth = $width;
            }
        };

        $mediaContents = self::kids($item, 'content', self::MEDIA);
        foreach (self::kids($item, 'group', self::MEDIA) as $group) {
            $mediaContents = array_merge($mediaContents, self::kids($group, 'content', self::MEDIA));
            foreach (self::kids($group, 'thumbnail', self::MEDIA) as $t) {
                $consider($t->getAttribute('url'), (int) $t->getAttribute('width'));
            }
        }
        foreach ($mediaContents as $m) {
            $type = strtolower($m->getAttribute('type'));
            $medium = strtolower($m->getAttribute('medium'));
            $url = $m->getAttribute('url');
            $isImage = $medium === 'image' || str_starts_with($type, 'image/')
                || ($medium === '' && $type === '' && preg_match('/\.(jpe?g|png|webp|gif|avif)(\?|$)/i', $url));
            if ($isImage) {
                $consider($url, (int) $m->getAttribute('width') ?: 1);
            }
            foreach (self::kids($m, 'thumbnail', self::MEDIA) as $t) {
                $consider($t->getAttribute('url'), (int) $t->getAttribute('width'));
            }
        }
        foreach (self::kids($item, 'thumbnail', self::MEDIA) as $t) {
            $consider($t->getAttribute('url'), (int) $t->getAttribute('width'));
        }
        if ($best) {
            return $best;
        }

        // Non-standard <image>URL</image> or <image><url>URL</url></image> inside items
        if ($ns !== self::ATOM && ($img = self::kid($item, 'image', $ns))) {
            $consider(self::text(self::kid($img, 'url', $ns)) ?? self::text($img));
            if ($best) {
                return $best;
            }
        }

        foreach (self::kids($item, 'enclosure', $ns === self::ATOM ? null : $ns) as $enc) {
            if (str_starts_with(strtolower($enc->getAttribute('type')), 'image/') || preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $enc->getAttribute('url'))) {
                $consider($enc->getAttribute('url'));
            }
        }
        if ($ns === self::ATOM) {
            foreach (self::kids($item, 'link', self::ATOM) as $l) {
                if ($l->getAttribute('rel') === 'enclosure' && str_starts_with($l->getAttribute('type'), 'image/')) {
                    $consider($l->getAttribute('href'));
                }
            }
        }
        if ($best) {
            return $best;
        }

        foreach ([$contentHtml, $descHtml] as $html) {
            if ($html && preg_match('/<img[^>]+?(?:data-src|src)\s*=\s*["\']([^"\']+)["\']/i', $html, $m)) {
                $consider($m[1]);
                if ($best) {
                    return $best;
                }
            }
        }
        return null;
    }

    private static function mediaKeywords(DOMElement $item): array
    {
        $tags = [];
        foreach (self::kids($item, 'keywords', self::MEDIA) as $k) {
            foreach (explode(',', (string) self::text($k)) as $t) {
                $t = clean_text($t, 60);
                if ($t) {
                    $tags[] = $t;
                }
            }
        }
        return array_values(array_unique($tags));
    }

    private static function atomLink(DOMElement $el, string $base): ?string
    {
        $fallback = null;
        foreach (self::kids($el, 'link', self::ATOM) as $link) {
            $rel = $link->getAttribute('rel') ?: 'alternate';
            $href = Urls::absolute($link->getAttribute('href'), $base);
            if ($rel === 'alternate' && (!$link->getAttribute('type') || str_contains($link->getAttribute('type'), 'html'))) {
                return $href;
            }
            if ($rel !== 'self' && $rel !== 'enclosure' && !$fallback) {
                $fallback = $href;
            }
        }
        return $fallback;
    }

    private static function parseDate(?string $date): ?int
    {
        if (!$date) {
            return null;
        }
        $date = trim($date);
        // Spanish day/month names occasionally show up in RSS dates
        $es = [
            'lun' => 'Mon', 'mar' => 'Tue', 'mié' => 'Wed', 'mie' => 'Wed', 'jue' => 'Thu', 'vie' => 'Fri', 'sáb' => 'Sat', 'sab' => 'Sat', 'dom' => 'Sun',
            'ene' => 'Jan', 'abr' => 'Apr', 'ago' => 'Aug', 'dic' => 'Dec',
        ];
        $ts = strtotime($date);
        if ($ts === false) {
            $ts = strtotime(str_replace([' - ', ' a las ', ' at '], ' ', $date));
        }
        if ($ts === false) {
            $translated = preg_replace_callback('/\b(\p{L}{3})\p{L}*\b/u', function ($m) use ($es) {
                $k = mb_strtolower($m[1]);
                return $es[$k] ?? $m[0];
            }, $date);
            $ts = strtotime((string) $translated);
        }
        if ($ts === false || $ts < 946684800) {
            return null;
        }
        return min($ts, time()); // future-dated items are clamped to now
    }

    private static function elementNames(DOMElement $item): array
    {
        $names = [];
        foreach ($item->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $names[$child->nodeName] = true;
            }
        }
        return array_keys($names);
    }

    // -----------------------------------------------------------------------
    // Namespace-aware DOM helpers. $ns === null means "no namespace".

    /** @return list<DOMElement> */
    private static function kids(?DOMElement $el, string $local, ?string $ns): array
    {
        if (!$el) {
            return [];
        }
        $out = [];
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $local && ($child->namespaceURI ?: null) === $ns) {
                $out[] = $child;
            }
        }
        return $out;
    }

    private static function kid(?DOMElement $el, string $local, ?string $ns): ?DOMElement
    {
        return self::kids($el, $local, $ns)[0] ?? null;
    }

    private static function text(?DOMElement $el): ?string
    {
        if (!$el) {
            return null;
        }
        $t = trim($el->textContent);
        return $t === '' ? null : $t;
    }
}
