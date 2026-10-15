<?php
declare(strict_types=1);

/**
 * Keeps the article pool topped up economically, per source:
 *   available(source) = unseen articles from it published within `pool_fresh_hours`
 * A source is requested only when available(source) < `source_min_available`, or it hasn't
 * been checked for `source_max_age_hours`. Sources with nothing left go first, interleaved
 * across regions, in small parallel batches. Conditional requests (ETag / Last-Modified)
 * make unchanged feeds nearly free.
 */
final class Retrieval
{
    private const PROFILE_FIELDS = ['title', 'url', 'excerpt', 'image_url', 'author', 'published_ts', 'categories', 'tags', 'external_id'];

    public static function availableCount(): int
    {
        $since = now_utc(-(int) config('pool_fresh_hours', 24) * 3600);
        return (int) q(
            "SELECT COUNT(*) FROM articles a JOIN sources s ON s.id = a.source_id
             WHERE a.published_at >= ? AND s.status = 'approved' AND s.is_active = 1
               AND COALESCE(a.paywall_status, 'unknown') != 'paywalled'
               AND NOT EXISTS (SELECT 1 FROM user_articles ua WHERE ua.article_id = a.id AND ua.seen = 1)",
            [$since]
        )->fetchColumn();
    }

    /**
     * @param string $mode 'cron' (larger time budget, also prunes) or 'on-demand' (one small batch)
     * @param bool $force fetch due sources even without a deficit
     */
    public static function topUp(string $mode = 'cron', bool $force = false): array
    {
        $lock = fopen(DATA_DIR . '/fetch.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['skipped' => 'another retrieval is running'];
        }
        @set_time_limit($mode === 'cron' ? 300 : 60);
        $started = microtime(true);
        try {
            $due = self::dueSources($force);
            $stats = ['mode' => $mode, 'available_before' => self::availableCount(), 'due' => count($due), 'sources_fetched' => 0, 'not_modified' => 0, 'failed' => 0, 'new_articles' => 0, 'new_fresh' => 0];
            $budget = $mode === 'cron' ? (float) config('cron_time_budget', 100) : 15.0;
            $maxSources = $mode === 'cron' ? PHP_INT_MAX : (int) config('on_demand_max_sources', 6);
            $clusterer = new Clusterer();

            foreach (array_chunk($due, max(1, (int) config('fetch_parallel', 6))) as $chunk) {
                if (microtime(true) - $started > $budget || $stats['sources_fetched'] >= $maxSources) {
                    break;
                }
                $chunk = array_slice($chunk, 0, max(1, $maxSources - $stats['sources_fetched']));
                foreach (self::fetchSources($chunk, $clusterer) as $r) {
                    $stats['sources_fetched']++;
                    $stats['not_modified'] += $r['not_modified'] ? 1 : 0;
                    $stats['failed'] += $r['error'] ? 1 : 0;
                    $stats['new_articles'] += $r['new'];
                    $stats['new_fresh'] += $r['new_fresh'];
                }
            }
            if ($mode === 'cron') {
                $stats['pruned'] = self::prune();
            }
            $stats['seconds'] = round(microtime(true) - $started, 2);
            $stats['at'] = now_utc();
            state_set('last_retrieval', $stats);
            if ($mode === 'cron') {
                state_set('last_cron', $stats);
            }
            return $stats;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Fetch a single source right now (admin "Fetch now"), ignoring intervals and deficits. */
    public static function fetchNow(int $sourceId): array
    {
        $source = Sources::find($sourceId);
        if (!$source) {
            return ['error' => 'Unknown source'];
        }
        $source['etag'] = null;
        $source['last_modified'] = null;
        return self::fetchSources([$source], new Clusterer())[0];
    }

    /** Unseen fresh articles per source id. */
    public static function availableBySource(): array
    {
        $since = now_utc(-(int) config('pool_fresh_hours', 24) * 3600);
        $rows = q(
            "SELECT a.source_id, COUNT(*) AS n FROM articles a
             WHERE a.published_at >= ? AND COALESCE(a.paywall_status, 'unknown') != 'paywalled'
               AND NOT EXISTS (SELECT 1 FROM user_articles ua WHERE ua.article_id = a.id AND ua.seen = 1)
             GROUP BY a.source_id",
            [$since]
        )->fetchAll();
        return array_map('intval', array_column($rows, 'n', 'source_id'));
    }

    /** Sources that need a request now: emptiest first, then stalest, interleaved by geo group. */
    public static function dueSources(bool $force = false): array
    {
        $cutoff = now_utc(-(int) config('source_min_interval_minutes', 15) * 60 + 30);
        $maxAge = strtotime(now_utc(-(int) config('source_max_age_hours', 6) * 3600) . ' UTC');
        $min = (int) config('source_min_available', 3);
        $available = self::availableBySource();

        $rows = q(
            "SELECT * FROM sources
             WHERE status = 'approved' AND is_active = 1
               AND (last_fetched_at IS NULL OR last_fetched_at <= :cutoff)
               AND (retry_after IS NULL OR retry_after <= :now)
             ORDER BY last_fetched_at IS NOT NULL, last_fetched_at ASC",
            ['cutoff' => $cutoff, 'now' => now_utc()]
        )->fetchAll();

        $due = [];
        foreach ($rows as $row) {
            $n = $available[$row['id']] ?? 0;
            $stale = !$row['last_fetched_at'] || strtotime($row['last_fetched_at'] . ' UTC') < $maxAge;
            if ($force || $n < $min || $stale) {
                $row['_available'] = $n;
                $due[] = $row;
            }
        }
        usort($due, fn ($a, $b) => [$a['_available'] >= $min, $a['last_fetched_at'] ?? ''] <=> [$b['_available'] >= $min, $b['last_fetched_at'] ?? '']);

        $buckets = [];
        foreach ($due as $row) {
            $buckets[$row['geo_group']][] = $row;
        }
        $out = [];
        while ($buckets) {
            foreach ($buckets as $geo => &$list) {
                $out[] = array_shift($list);
                if (!$list) {
                    unset($buckets[$geo]);
                }
            }
            unset($list);
        }
        return $out;
    }

    /** @return list<array{source_id: int, new: int, new_fresh: int, not_modified: bool, error: ?string}> */
    private static function fetchSources(array $sources, Clusterer $clusterer): array
    {
        $requests = [];
        foreach ($sources as $i => $s) {
            $headers = [];
            if ($s['etag']) {
                $headers[] = 'If-None-Match: ' . $s['etag'];
            }
            if ($s['last_modified']) {
                $headers[] = 'If-Modified-Since: ' . $s['last_modified'];
            }
            $requests[$i] = ['url' => $s['feed_url'], 'headers' => $headers];
        }
        $responses = Http::fetchMany($requests);

        $out = [];
        foreach ($sources as $i => $s) {
            $r = $responses[$i];
            $result = ['source_id' => (int) $s['id'], 'name' => $s['name'], 'new' => 0, 'new_fresh' => 0, 'not_modified' => false, 'error' => null, 'items' => 0];
            $now = now_utc();

            if ($r['status'] === 304) {
                $result['not_modified'] = true;
                q('UPDATE sources SET last_fetched_at = ?, last_status = 304, fail_count = 0, retry_after = NULL, last_error = NULL, updated_at = ? WHERE id = ?', [$now, $now, $s['id']]);
                $out[] = $result;
                continue;
            }

            $error = null;
            $feed = null;
            if ($r['status'] !== 200) {
                $error = 'HTTP ' . $r['status'] . ($r['error'] ? ' – ' . $r['error'] : '');
            } else {
                try {
                    $feed = FeedParser::parse($r['body'], $r['final_url'] ?: $s['feed_url']);
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }

            if ($error !== null) {
                $fails = (int) $s['fail_count'] + 1;
                $backoffMinutes = min(1440, 15 * (2 ** min(7, $fails - 1)));
                $access = in_array($r['status'], [401, 402, 403, 451], true) ? 'denied' : ($fails >= 5 ? 'failing' : $s['access_status']);
                q(
                    'UPDATE sources SET last_fetched_at = ?, last_status = ?, last_error = ?, fail_count = ?, retry_after = ?, access_status = ?, updated_at = ? WHERE id = ?',
                    [$now, $r['status'], mb_substr($error, 0, 500), $fails, now_utc($backoffMinutes * 60), $access, $now, $s['id']]
                );
                $result['error'] = $error;
                $out[] = $result;
                continue;
            }

            [$new, $fresh] = self::ingest($s, $feed, $clusterer);
            $result['new'] = $new;
            $result['new_fresh'] = $fresh;
            $result['items'] = count($feed['items']);
            q(
                "UPDATE sources SET last_fetched_at = :now, last_success_at = :now, last_status = 200, last_error = NULL, fail_count = 0, retry_after = NULL,
                    access_status = 'ok', etag = :etag, last_modified = :lm, last_item_count = :items, last_new_count = :new,
                    metadata_profile = :profile, feed_type = :type, updated_at = :now WHERE id = :id",
                [
                    'now' => $now, 'etag' => $r['headers']['etag'] ?? null, 'lm' => $r['headers']['last-modified'] ?? null,
                    'items' => count($feed['items']), 'new' => $new,
                    'profile' => json_encode(self::profile($feed), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'type' => $feed['format'] === 'rdf' ? 'rdf' : $feed['format'], 'id' => $s['id'],
                ]
            );
            $out[] = $result;
        }
        return $out;
    }

    /** Normalize, deduplicate, categorize, cluster and store feed items. @return array{0: int, 1: int} [new, new within fresh window] */
    private static function ingest(array $source, array $feed, Clusterer $clusterer): array
    {
        $windowStart = time() - (int) config('feed_window_days', 7) * 86400;
        $freshStart = time() - (int) config('pool_fresh_hours', 24) * 3600;
        $now = now_utc();
        $new = 0;
        $fresh = 0;

        $findUrl = db()->prepare('SELECT id FROM articles WHERE url_hash = ?');
        $findExternal = db()->prepare('SELECT id FROM articles WHERE source_id = ? AND external_id = ?');
        $findTitle = db()->prepare('SELECT id FROM articles WHERE source_id = ? AND title_hash = ? AND published_at >= ?');
        $fillGaps = db()->prepare('UPDATE articles SET image_url = COALESCE(image_url, ?), excerpt = COALESCE(excerpt, ?), author = COALESCE(author, ?) WHERE id = ?');
        $insert = db()->prepare(
            'INSERT INTO articles (source_id, title, url, canonical_url, url_hash, title_hash, external_id, excerpt, image_url, author, published_at, language, category, categories_raw, tags, search_text, title_tokens, paywall_status, paywall_reason, paywall_checked_at, retrieved_at, created_at, updated_at)
             VALUES (:source_id, :title, :url, :canonical_url, :url_hash, :title_hash, :external_id, :excerpt, :image_url, :author, :published_at, :language, :category, :categories_raw, :tags, :search_text, :title_tokens, :paywall_status, :paywall_reason, :paywall_checked_at, :now, :now, :now)'
        );
        $setCluster = db()->prepare('UPDATE articles SET cluster_id = ? WHERE id = ?');

        Db::transaction(function () use ($feed, $source, $clusterer, $windowStart, $freshStart, $now, &$new, &$fresh, $findUrl, $findExternal, $findTitle, $fillGaps, $insert, $setCluster) {
            foreach ($feed['items'] as $item) {
                if (!$item['title'] || !$item['url']) {
                    continue;
                }
                $publishedTs = $item['published_ts'] ?? time();
                if ($publishedTs < $windowStart) {
                    continue;
                }
                $language = self::itemLanguage($item['language'], $source['language']);
                if ($language === null) {
                    continue; // explicitly tagged in an unsupported language
                }
                if (!$item['language']) {
                    $language = guess_language($item['title'] . ' ' . $item['excerpt']) ?? $language;
                }

                $urlHash = Urls::hash($item['url']);
                $titleHash = sha1(trim(search_text($item['title'])));
                $existing = null;
                $findUrl->execute([$urlHash]);
                $existing = $findUrl->fetchColumn() ?: null;
                if (!$existing && $item['external_id']) {
                    $findExternal->execute([$source['id'], $item['external_id']]);
                    $existing = $findExternal->fetchColumn() ?: null;
                }
                if (!$existing) {
                    $findTitle->execute([$source['id'], $titleHash, gmdate('Y-m-d H:i:s', $publishedTs - 3 * 86400)]);
                    $existing = $findTitle->fetchColumn() ?: null;
                }
                if ($existing) {
                    $fillGaps->execute([$item['image_url'], $item['excerpt'], $item['author'], $existing]);
                    continue;
                }

                $category = Categories::classify(array_merge($item['categories'], $item['tags']), $item['url'], $source['default_category']);
                $tokens = Clusterer::tokens($item['title']);
                $wall = Paywall::fromFeed($item['title'], $item['excerpt'], $item['categories'] ?? [], $item['tags'] ?? []);
                // Only auto-hide when the feed itself clearly labels premium; "likely" still enters the pool.
                $paywallStatus = $wall['status'] === Paywall::PAYWALLED ? Paywall::PAYWALLED : Paywall::UNKNOWN;
                $insert->execute([
                    'source_id' => $source['id'],
                    'title' => $item['title'],
                    'url' => $item['url'],
                    'canonical_url' => Urls::clean($item['url']),
                    'url_hash' => $urlHash,
                    'title_hash' => $titleHash,
                    'external_id' => $item['external_id'],
                    'excerpt' => $item['excerpt'],
                    'image_url' => $item['image_url'],
                    'author' => $item['author'],
                    'published_at' => gmdate('Y-m-d H:i:s', $publishedTs),
                    'language' => $language,
                    'category' => $category,
                    'categories_raw' => $item['categories'] ? json_encode($item['categories'], JSON_UNESCAPED_UNICODE) : null,
                    'tags' => $item['tags'] ? json_encode($item['tags'], JSON_UNESCAPED_UNICODE) : null,
                    'search_text' => search_text($item['title'], (string) $item['excerpt'], implode(' ', $item['categories']), implode(' ', $item['tags'])),
                    'title_tokens' => implode(' ', $tokens),
                    'paywall_status' => $paywallStatus,
                    'paywall_reason' => $paywallStatus === Paywall::PAYWALLED ? $wall['reason'] : null,
                    'paywall_checked_at' => $paywallStatus === Paywall::PAYWALLED ? $now : null,
                    'now' => $now,
                ]);
                $id = (int) db()->lastInsertId();
                $setCluster->execute([$clusterer->assign($id, $tokens), $id]);
                $new++;
                if ($publishedTs >= $freshStart) {
                    $fresh++;
                }
            }
        });
        return [$new, $fresh];
    }

    private static function itemLanguage(?string $itemLang, string $sourceLang): ?string
    {
        if (!$itemLang) {
            return $sourceLang;
        }
        $code = strtolower(substr($itemLang, 0, 2));
        return isset(Sources::LANGUAGES[$code]) ? $code : null;
    }

    /** Summary of what a feed actually provides, for the admin metadata inspector. */
    public static function profile(array $feed): array
    {
        $items = $feed['items'];
        $coverage = [];
        foreach (self::PROFILE_FIELDS as $field) {
            $coverage[$field] = count(array_filter($items, fn ($it) => !empty($it[$field])));
        }
        $elements = [];
        foreach ($items as $it) {
            foreach ($it['elements'] as $el) {
                $elements[$el] = ($elements[$el] ?? 0) + 1;
            }
        }
        arsort($elements);
        $categories = [];
        foreach ($items as $it) {
            foreach ($it['categories'] as $c) {
                $categories[$c] = ($categories[$c] ?? 0) + 1;
            }
        }
        arsort($categories);
        $newest = max(array_map(fn ($it) => $it['published_ts'] ?? 0, $items) ?: [0]);

        return [
            'format' => $feed['format'],
            'feed_title' => $feed['title'],
            'feed_language' => $feed['language'],
            'items' => count($items),
            'coverage' => $coverage,
            'elements' => $elements,
            'raw_categories' => array_slice($categories, 0, 30, true),
            'newest' => $newest ? gmdate('Y-m-d H:i:s', $newest) : null,
            'samples' => array_map(fn ($it) => [
                'title' => $it['title'],
                'url' => $it['url'],
                'excerpt' => $it['excerpt'] ? mb_substr($it['excerpt'], 0, 160) : null,
                'image_url' => $it['image_url'],
                'author' => $it['author'],
                'published' => $it['published_ts'] ? gmdate('Y-m-d H:i:s', $it['published_ts']) : null,
                'categories' => $it['categories'],
                'tags' => array_slice($it['tags'], 0, 8),
                'mapped_category' => Categories::classify(array_merge($it['categories'], $it['tags']), $it['url'], null),
            ], array_slice($items, 0, 4)),
            'checked_at' => now_utc(),
        ];
    }

    /** Delete old unsaved articles; user_articles rows cascade. */
    public static function prune(): int
    {
        $cutoff = now_utc(-(int) config('retention_days', 30) * 86400);
        return q(
            'DELETE FROM articles WHERE published_at < ? AND id NOT IN (SELECT article_id FROM user_articles WHERE saved = 1)',
            [$cutoff]
        )->rowCount();
    }
}
