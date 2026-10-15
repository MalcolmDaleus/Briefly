<?php
declare(strict_types=1);

/**
 * Feed selection. Filters constrain the pool; a deterministic score decides the order.
 *
 *   pool   = approved sources, published within feed_window_days (or a tighter
 *            "within" window), unseen, not already in the client's window,
 *            matching keyword / category / geo / language filters (include or exclude)
 *   score  = recency (exponential decay)
 *          + story size bonus (widely covered stories rank a little higher)
 *          + keyword title bonus
 *          − penalty if the user already saw coverage of the same story, or it is
 *            already queued/recently shown in the client's window
 *          − penalties for repeating the story / topic / source / region / category
 *            of the articles just before it (context from the client + picks so far);
 *            "topic" = sharing rare title words, which catches coverage clustering missed
 * Articles are picked greedily one at a time so every pick sees the previous ones.
 */
final class Feed
{
    private const RECENCY_HOURS = 24;       // e-folding time for recency score
    private const CONTEXT = 10;             // how far back repetition penalties look
    private const MAX_PER_SOURCE = 3;       // per batch

    /** Allowed published-within windows: URL token => hours. */
    public const WITHIN = [
        '1h' => 1,
        '3h' => 3,
        '12h' => 12,
        '24h' => 24,
        '3d' => 72,
        '7d' => 168,
    ];

    public static function next(int $userId, array $opts): array
    {
        $limit = max(1, min(30, (int) ($opts['limit'] ?? config('feed_batch_size', 15))));
        $exclude = array_slice(array_values(array_filter(array_map('intval', $opts['exclude'] ?? []))), 0, 200);
        $context = array_slice(array_values(array_filter(array_map('intval', $opts['context'] ?? []))), -self::CONTEXT);
        $filters = self::normalizeFilters($opts);
        $includeSeen = !empty($opts['include_seen']);

        $pool = self::pool($userId, $exclude, $filters, $includeSeen);
        if (!$pool) {
            return [];
        }

        $since = now_utc(-(int) config('feed_window_days', 7) * 86400);
        $clusterSizes = self::clusterSizes(array_unique(array_column($pool, 'cluster_id')), $since);
        $seenClusters = array_flip(array_map('intval', q(
            'SELECT DISTINCT a.cluster_id FROM user_articles ua JOIN articles a ON a.id = ua.article_id
             WHERE ua.user_id = ? AND ua.seen = 1 AND a.published_at >= ?',
            [$userId, $since]
        )->fetchAll(PDO::FETCH_COLUMN)));

        // Articles the client already has (queued or recently shown)
        $windowRows = $exclude ? q('SELECT cluster_id, title_tokens FROM articles WHERE id IN (' . implode(',', $exclude) . ')')->fetchAll() : [];
        $windowClusters = array_flip(array_map('intval', array_column($windowRows, 'cluster_id')));

        // Token rarity within the pool, for topic similarity
        $df = [];
        foreach ($pool as &$a) {
            $a['_tokens'] = $a['title_tokens'] !== '' ? array_flip(explode(' ', $a['title_tokens'])) : [];
            foreach ($a['_tokens'] as $t => $_) {
                $df[$t] = ($df[$t] ?? 0) + 1;
            }
        }
        unset($a);
        $n = count($pool) + 1;
        $idf = static fn (string $t) => log(($n + 1) / (($df[$t] ?? 0) + 1));
        $windowWeights = [];
        foreach ($windowRows as $w) {
            $weights = [];
            foreach ($w['title_tokens'] !== '' ? explode(' ', $w['title_tokens']) : [] as $t) {
                $weights[$t] = $idf($t);
            }
            $windowWeights[] = $weights;
        }

        $now = time();
        foreach ($pool as &$a) {
            $ageHours = max(0, ($now - strtotime($a['published_at'] . ' UTC')) / 3600);
            $score = 100 * exp(-$ageHours / self::RECENCY_HOURS);
            $size = $clusterSizes[$a['cluster_id']] ?? 1;
            $score += min(15, 5 * ($size - 1));
            if (isset($seenClusters[(int) $a['cluster_id']]) && !$a['seen']) {
                $score -= 40;
            }
            if (isset($windowClusters[(int) $a['cluster_id']])) {
                $score -= 60;
            }
            $a['_weights'] = [];
            foreach ($a['_tokens'] as $t => $_) {
                $a['_weights'][$t] = $idf($t);
            }
            $closest = 0.0;
            foreach ($windowWeights as $w) {
                $closest = max($closest, self::topicSimilarity($a['_weights'], $w));
            }
            if ($closest >= 0.45) {
                $score -= 45 * min(1.0, $closest);
            }
            if ($filters['keywords']) {
                $title = search_text($a['title']);
                foreach ($filters['keywords'] as $k) {
                    if (str_contains($title, ' ' . $k . ' ')) {
                        $score += 10;
                    }
                }
            }
            $a['_base'] = $score;
            $a['cluster_size'] = $size;
        }
        unset($a);

        $sequence = array_map(static function (array $c) use ($idf) {
            $c['_weights'] = [];
            foreach ($c['title_tokens'] !== '' ? explode(' ', $c['title_tokens']) : [] as $t) {
                $c['_weights'][$t] = $idf($t);
            }
            return $c;
        }, self::contextAttributes($context));
        $picked = [];
        $perSource = [];
        while (count($picked) < $limit && $pool) {
            $bestKey = null;
            $bestScore = -INF;
            foreach ($pool as $key => $a) {
                if (($perSource[$a['source_id']] ?? 0) >= self::MAX_PER_SOURCE) {
                    continue;
                }
                $score = $a['_base'] - self::repetitionPenalty($a, $sequence);
                if ($score > $bestScore || ($score === $bestScore && $a['id'] > $pool[$bestKey]['id'])) {
                    $bestScore = $score;
                    $bestKey = $key;
                }
            }
            if ($bestKey === null) {
                break; // only over-represented sources left
            }
            $choice = $pool[$bestKey];
            unset($pool[$bestKey]);
            $picked[] = $choice;
            $perSource[$choice['source_id']] = ($perSource[$choice['source_id']] ?? 0) + 1;
            $sequence[] = $choice;
        }
        return array_map([self::class, 'present'], $picked);
    }

    private static function repetitionPenalty(array $a, array $sequence): float
    {
        $penalty = 0.0;
        $n = count($sequence);
        for ($d = 1; $d <= min(self::CONTEXT, $n); $d++) {
            $p = $sequence[$n - $d];
            if ((int) $p['cluster_id'] === (int) $a['cluster_id']) {
                $penalty += 70;
            } elseif (($similarity = self::topicSimilarity($a['_weights'], $p['_weights'])) >= 0.3) {
                $penalty += 70 * min(1.0, $similarity);
            }
            if ($d <= 5 && (int) $p['source_id'] === (int) $a['source_id']) {
                $penalty += 45 / $d;
            }
            if ($d <= 3 && $p['geo_group'] === $a['geo_group']) {
                $penalty += 25 / $d;
            }
            if ($d <= 3 && $p['category'] === $a['category']) {
                $penalty += 18 / $d;
            }
        }
        return $penalty;
    }

    /** Share of the shorter title's distinctive weight that both titles have in common. */
    private static function topicSimilarity(array $a, array $b): float
    {
        if (!$a || !$b) {
            return 0.0;
        }
        $shared = array_intersect_key($a, $b);
        if (count($shared) < 2) {
            return 0.0;
        }
        return array_sum($shared) / max(0.001, min(array_sum($a), array_sum($b)));
    }

    /**
     * @param array{categories: string[], category_mode: string, geos: string[], geo_mode: string, langs: string[], lang_mode: string, keywords: string[], within_hours: ?int} $filters
     */
    private static function pool(int $userId, array $exclude, array $filters, bool $includeSeen): array
    {
        $windowHours = (int) config('feed_window_days', 7) * 24;
        $hours = $filters['within_hours'] !== null ? min($filters['within_hours'], $windowHours) : $windowHours;
        $where = [
            "a.published_at >= :since",
            "s.status = 'approved'",
            's.is_active = 1',
            "COALESCE(a.paywall_status, 'unknown') != 'paywalled'",
            'COALESCE(ua.dismissed, 0) = 0',
        ];
        $params = ['uid' => $userId, 'since' => now_utc(-$hours * 3600)];
        if (!$includeSeen) {
            $where[] = '(ua.seen IS NULL OR ua.seen = 0)';
        }
        if ($exclude) {
            $where[] = 'a.id NOT IN (' . implode(',', $exclude) . ')';
        }
        self::applyListFilter($where, $params, 'a.category', 'cat', $filters['categories'], $filters['category_mode']);
        self::applyListFilter($where, $params, 's.geo_group', 'geo', $filters['geos'], $filters['geo_mode']);
        if ($filters['langs']) {
            $placeholders = [];
            foreach ($filters['langs'] as $i => $lang) {
                $key = "lang{$i}";
                $placeholders[] = ':' . $key;
                $params[$key] = $lang;
            }
            $in = implode(', ', $placeholders);
            if ($filters['lang_mode'] === 'exclude') {
                // Missing language still passes an exclude filter.
                $where[] = "(a.language IS NULL OR a.language = '' OR a.language NOT IN ({$in}))";
            } else {
                $where[] = "a.language IN ({$in})";
            }
        }
        if ($filters['keywords']) {
            $or = [];
            foreach ($filters['keywords'] as $i => $k) {
                $or[] = "a.search_text LIKE :k{$i}";
                $params["k{$i}"] = '% ' . str_replace(['%', '_'], ['\\%', '\\_'], $k) . ' %';
            }
            $where[] = '(' . implode(' OR ', array_map(fn ($c) => $c . " ESCAPE '\\'", $or)) . ')';
        }
        $sql = 'SELECT a.id, a.source_id, a.title, a.url, a.excerpt, a.image_url, a.author, a.published_at, a.language, a.category, a.cluster_id, a.title_tokens,
                       a.paywall_status, a.paywall_reason,
                       s.name AS source_name, s.domain AS source_domain, s.geo_group,
                       COALESCE(ua.saved, 0) AS saved, COALESCE(ua.seen, 0) AS seen,
                       COALESCE(ua.opened, 0) AS opened, ua.note
                FROM articles a
                JOIN sources s ON s.id = a.source_id
                LEFT JOIN user_articles ua ON ua.article_id = a.id AND ua.user_id = :uid
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY a.published_at DESC
                LIMIT ' . (int) config('feed_pool_limit', 500);
        return q($sql, $params)->fetchAll();
    }

    /** @param list<string> $values */
    private static function applyListFilter(array &$where, array &$params, string $column, string $prefix, array $values, string $mode): void
    {
        if (!$values) {
            return;
        }
        $placeholders = [];
        foreach ($values as $i => $value) {
            $key = $prefix . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }
        $in = implode(', ', $placeholders);
        $where[] = $mode === 'exclude' ? "{$column} NOT IN ({$in})" : "{$column} IN ({$in})";
    }

    /** Normalize and validate filter opts from the API / client. */
    public static function normalizeFilters(array $opts): array
    {
        $mode = static fn (string $key): string => (($opts[$key] ?? '') === 'exclude') ? 'exclude' : 'include';

        $categories = self::listParam($opts['categories'] ?? ($opts['category'] ?? ''), array_keys(Categories::LABELS));
        // Legacy single-category URL (?category=politics) still works.
        if (!$categories && is_string($opts['category'] ?? null) && isset(Categories::LABELS[$opts['category']])) {
            $categories = [$opts['category']];
        }

        $withinToken = (string) ($opts['within'] ?? '');
        $withinHours = self::WITHIN[$withinToken] ?? null;

        return [
            'categories' => $categories,
            'category_mode' => $mode('category_mode'),
            'geos' => self::listParam($opts['geos'] ?? ($opts['geo'] ?? ''), array_keys(Sources::GEO)),
            'geo_mode' => $mode('geo_mode'),
            'langs' => self::listParam($opts['langs'] ?? ($opts['lang'] ?? ''), array_keys(Sources::LANGUAGES)),
            'lang_mode' => $mode('lang_mode'),
            'keywords' => self::keywords((string) ($opts['q'] ?? '')),
            'within_hours' => $withinHours,
            'within' => $withinHours !== null ? $withinToken : '',
        ];
    }

    /** @param list<string>|string $raw @param list<string> $allowed */
    private static function listParam(array|string $raw, array $allowed): array
    {
        $parts = is_array($raw) ? $raw : explode(',', $raw);
        $allowedFlip = array_flip($allowed);
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '' && isset($allowedFlip[$part])) {
                $out[] = $part;
            }
        }
        return array_values(array_unique($out));
    }

    private static function clusterSizes(array $clusterIds, string $since): array
    {
        $ids = array_values(array_filter(array_map('intval', $clusterIds)));
        if (!$ids) {
            return [];
        }
        $rows = q(
            'SELECT cluster_id, COUNT(DISTINCT source_id) AS n FROM articles WHERE published_at >= ? AND cluster_id IN (' . implode(',', $ids) . ') GROUP BY cluster_id',
            [$since]
        )->fetchAll();
        return array_column($rows, 'n', 'cluster_id');
    }

    private static function contextAttributes(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $rows = q(
            'SELECT a.id, a.source_id, a.category, a.cluster_id, a.title_tokens, s.geo_group FROM articles a JOIN sources s ON s.id = a.source_id WHERE a.id IN (' . implode(',', $ids) . ')'
        )->fetchAll();
        $byId = array_column($rows, null, 'id');
        return array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $ids)));
    }

    /** "Spain, AI" or "climate change; valencia" → folded keyword phrases. */
    public static function keywords(string $q): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/', $q) ?: [] as $part) {
            $k = trim(search_text($part));
            if ($k !== '') {
                $out[] = $k;
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 10);
    }

    public static function present(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'title' => $a['title'],
            'url' => $a['url'],
            'excerpt' => $a['excerpt'],
            'image' => $a['image_url'],
            'author' => $a['author'],
            'published_at' => iso_utc($a['published_at']),
            'language' => $a['language'],
            'category' => $a['category'],
            'category_label' => Categories::label($a['category']),
            'source' => $a['source_name'],
            'domain' => $a['source_domain'],
            'geo' => $a['geo_group'],
            'geo_label' => Sources::geoLabel($a['geo_group'], true),
            'cluster_size' => (int) ($a['cluster_size'] ?? 1),
            'saved' => (bool) $a['saved'],
            'seen' => (bool) $a['seen'],
            'opened' => (bool) ($a['opened'] ?? false),
            'note' => $a['note'] ?? null,
            'paywall_status' => $a['paywall_status'] ?? Paywall::UNKNOWN,
            'paywall_reason' => $a['paywall_reason'] ?? null,
            'source_id' => (int) $a['source_id'],
        ];
    }

    /** Hide an article from this user's feed (and optionally mark the article paywalled). */
    public static function dismiss(int $userId, int $articleId, bool $markPaywalled = false): bool
    {
        if (!q('SELECT 1 FROM articles WHERE id = ?', [$articleId])->fetchColumn()) {
            return false;
        }
        $now = now_utc();
        q(
            'INSERT INTO user_articles (user_id, article_id, seen, dismissed, first_seen_at, created_at, updated_at)
             VALUES (:uid, :aid, 1, 1, :now, :now, :now)
             ON CONFLICT (user_id, article_id) DO UPDATE SET dismissed = 1, seen = 1,
                first_seen_at = COALESCE(first_seen_at, excluded.first_seen_at), updated_at = excluded.updated_at',
            ['uid' => $userId, 'aid' => $articleId, 'now' => $now]
        );
        if ($markPaywalled) {
            Paywall::store($articleId, Paywall::PAYWALLED, 'Marked after paywall warning');
        }
        return true;
    }

    /** Check (or reuse) paywall status for an article the user is about to open. */
    public static function paywallCheck(int $articleId): ?array
    {
        $row = q(
            'SELECT a.id, a.url, a.source_id, a.paywall_status, a.paywall_reason, a.paywall_checked_at,
                    COALESCE(s.paywall_safe, 0) AS paywall_safe
             FROM articles a JOIN sources s ON s.id = a.source_id WHERE a.id = ?',
            [$articleId]
        )->fetch();
        if (!$row) {
            return null;
        }
        if ((int) $row['paywall_safe'] === 1) {
            Paywall::store((int) $row['id'], Paywall::FREE, 'Source marked safe');
            return [
                'id' => (int) $row['id'],
                'url' => $row['url'],
                'source_id' => (int) $row['source_id'],
                'status' => Paywall::FREE,
                'reason' => 'Source marked safe — skipping paywall check',
                'cached' => true,
            ];
        }
        $status = $row['paywall_status'] ?: Paywall::UNKNOWN;
        // Only cache "free" — paywalled/likely are rechecked so tighter markers can clear FPs.
        $freshFree = $status === Paywall::FREE
            && $row['paywall_checked_at']
            && strtotime($row['paywall_checked_at'] . ' UTC') > time() - 7 * 86400;

        if ($freshFree) {
            return [
                'id' => (int) $row['id'],
                'url' => $row['url'],
                'source_id' => (int) $row['source_id'],
                'status' => $status,
                'reason' => $row['paywall_reason'],
                'cached' => true,
            ];
        }

        $result = Paywall::checkUrl($row['url']);
        // Don't overwrite a firm RSS paywalled label with "unknown" from a failed fetch.
        if (!($status === Paywall::PAYWALLED && $result['status'] === Paywall::UNKNOWN)) {
            Paywall::store((int) $row['id'], $result['status'], $result['reason']);
            $status = $result['status'];
        }
        return [
            'id' => (int) $row['id'],
            'url' => $row['url'],
            'source_id' => (int) $row['source_id'],
            'status' => $status,
            'reason' => $result['reason'] ?? $row['paywall_reason'],
            'cached' => false,
        ];
    }

    /** False-positive override: this source is fine; stop checking its pages. */
    public static function markSourceSafe(int $articleId): ?array
    {
        $row = q('SELECT id, source_id, url FROM articles WHERE id = ?', [$articleId])->fetch();
        if (!$row) {
            return null;
        }
        Paywall::markSourceSafe((int) $row['source_id']);
        Paywall::store((int) $row['id'], Paywall::FREE, 'Source marked safe');
        return [
            'id' => (int) $row['id'],
            'source_id' => (int) $row['source_id'],
            'url' => $row['url'],
            'status' => Paywall::FREE,
        ];
    }

    /** @return array<string, mixed> */
    public static function getUserFilters(int $userId): array
    {
        $raw = q('SELECT feed_filters FROM users WHERE id = ?', [$userId])->fetchColumn();
        if (!$raw || !is_string($raw)) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function saveUserFilters(int $userId, array $opts): array
    {
        $filters = self::normalizeFilters($opts);
        $store = [
            'q' => (string) ($opts['q'] ?? ''),
            'category' => implode(',', $filters['categories']),
            'category_mode' => $filters['category_mode'],
            'geo' => implode(',', $filters['geos']),
            'geo_mode' => $filters['geo_mode'],
            'lang' => implode(',', $filters['langs']),
            'lang_mode' => $filters['lang_mode'],
            'within' => $filters['within'],
        ];
        // Drop empty fields so the JSON stays small.
        $store = array_filter($store, static fn ($v) => $v !== '' && $v !== null);
        q(
            'UPDATE users SET feed_filters = ?, updated_at = ? WHERE id = ?',
            [$store ? json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null, now_utc(), $userId]
        );
        return $store;
    }

    // -----------------------------------------------------------------------
    // User article state

    public static function markSeen(int $userId, array $articleIds): int
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $articleIds)))), 0, 100);
        if (!$ids) {
            return 0;
        }
        $now = now_utc();
        $stmt = db()->prepare(
            'INSERT INTO user_articles (user_id, article_id, seen, first_seen_at, created_at, updated_at)
             SELECT :uid, id, 1, :now, :now, :now FROM articles WHERE id = :aid
             ON CONFLICT (user_id, article_id) DO UPDATE SET seen = 1, first_seen_at = COALESCE(first_seen_at, excluded.first_seen_at), updated_at = excluded.updated_at'
        );
        Db::transaction(function () use ($stmt, $ids, $userId, $now) {
            foreach ($ids as $id) {
                $stmt->execute(['uid' => $userId, 'aid' => $id, 'now' => $now]);
            }
        });
        return count($ids);
    }

    public static function setSaved(int $userId, int $articleId, bool $saved): bool
    {
        if (!q('SELECT 1 FROM articles WHERE id = ?', [$articleId])->fetchColumn()) {
            return false;
        }
        $now = now_utc();
        if ($saved) {
            q(
                'INSERT INTO user_articles (user_id, article_id, seen, saved, first_seen_at, first_saved_at, saved_at, created_at, updated_at)
                 VALUES (:uid, :aid, 1, 1, :now, :now, :now, :now, :now)
                 ON CONFLICT (user_id, article_id) DO UPDATE SET saved = 1, seen = 1,
                    saved_at = COALESCE(saved_at, excluded.saved_at),
                    first_saved_at = COALESCE(first_saved_at, excluded.first_saved_at),
                    first_seen_at = COALESCE(first_seen_at, excluded.first_seen_at),
                    updated_at = excluded.updated_at',
                ['uid' => $userId, 'aid' => $articleId, 'now' => $now]
            );
        } else {
            q('UPDATE user_articles SET saved = 0, saved_at = NULL, note = NULL, updated_at = ? WHERE user_id = ? AND article_id = ?', [$now, $userId, $articleId]);
        }
        return true;
    }

    /** Record a click-through to the original article (image, title, or Read button). */
    public static function markOpened(int $userId, array $articleIds): int
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $articleIds)))), 0, 50);
        if (!$ids) {
            return 0;
        }
        $now = now_utc();
        $stmt = db()->prepare(
            'INSERT INTO user_articles (user_id, article_id, seen, opened, open_count, first_seen_at, first_opened_at, last_opened_at, created_at, updated_at)
             SELECT :uid, id, 1, 1, 1, :now, :now, :now, :now, :now FROM articles WHERE id = :aid
             ON CONFLICT (user_id, article_id) DO UPDATE SET
                seen = 1,
                opened = 1,
                open_count = open_count + 1,
                first_seen_at = COALESCE(first_seen_at, excluded.first_seen_at),
                first_opened_at = COALESCE(first_opened_at, excluded.first_opened_at),
                last_opened_at = excluded.last_opened_at,
                updated_at = excluded.updated_at'
        );
        Db::transaction(function () use ($stmt, $ids, $userId, $now) {
            foreach ($ids as $id) {
                $stmt->execute(['uid' => $userId, 'aid' => $id, 'now' => $now]);
            }
        });
        return count($ids);
    }

    /** Set or clear the note on a saved article. Empty string clears it. */
    public static function setNote(int $userId, int $articleId, string $note): bool
    {
        $note = trim($note);
        if (mb_strlen($note) > 2000) {
            $note = mb_substr($note, 0, 2000);
        }
        $row = q(
            'SELECT saved FROM user_articles WHERE user_id = ? AND article_id = ?',
            [$userId, $articleId]
        )->fetch();
        if (!$row || !(int) $row['saved']) {
            return false;
        }
        q(
            'UPDATE user_articles SET note = ?, updated_at = ? WHERE user_id = ? AND article_id = ?',
            [$note === '' ? null : $note, now_utc(), $userId, $articleId]
        );
        return true;
    }

    public static function saved(int $userId): array
    {
        return q(
            'SELECT a.id, a.title, a.url, a.excerpt, a.image_url, a.author, a.published_at, a.category, s.name AS source_name, s.domain AS source_domain, s.geo_group, ua.saved_at, ua.note
             FROM user_articles ua JOIN articles a ON a.id = ua.article_id JOIN sources s ON s.id = a.source_id
             WHERE ua.user_id = ? AND ua.saved = 1 ORDER BY ua.saved_at DESC',
            [$userId]
        )->fetchAll();
    }

    public static function seen(int $userId): array
    {
        return q(
            'SELECT a.id, a.title, a.url, a.published_at, a.category, s.name AS source_name, s.geo_group, ua.first_seen_at, ua.saved
             FROM user_articles ua JOIN articles a ON a.id = ua.article_id JOIN sources s ON s.id = a.source_id
             WHERE ua.user_id = ? AND ua.seen = 1 ORDER BY ua.first_seen_at DESC',
            [$userId]
        )->fetchAll();
    }
}
