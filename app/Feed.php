<?php
declare(strict_types=1);

/**
 * Feed selection. Filters constrain the pool; a deterministic score decides the order.
 *
 *   pool   = approved sources, published within feed_window_days, unseen, not already
 *            in the client's window, matching category/keyword filters
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

    public static function next(int $userId, array $opts): array
    {
        $limit = max(1, min(30, (int) ($opts['limit'] ?? config('feed_batch_size', 15))));
        $exclude = array_slice(array_values(array_filter(array_map('intval', $opts['exclude'] ?? []))), 0, 200);
        $context = array_slice(array_values(array_filter(array_map('intval', $opts['context'] ?? []))), -self::CONTEXT);
        $category = isset(Categories::LABELS[$opts['category'] ?? '']) ? $opts['category'] : null;
        $keywords = self::keywords((string) ($opts['q'] ?? ''));
        $includeSeen = !empty($opts['include_seen']);

        $pool = self::pool($userId, $exclude, $category, $keywords, $includeSeen);
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
            if ($keywords) {
                $title = search_text($a['title']);
                foreach ($keywords as $k) {
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

    private static function pool(int $userId, array $exclude, ?string $category, array $keywords, bool $includeSeen): array
    {
        $where = ["a.published_at >= :since", "s.status = 'approved'", 's.is_active = 1'];
        $params = ['uid' => $userId, 'since' => now_utc(-(int) config('feed_window_days', 7) * 86400)];
        if (!$includeSeen) {
            $where[] = '(ua.seen IS NULL OR ua.seen = 0)';
        }
        if ($exclude) {
            $where[] = 'a.id NOT IN (' . implode(',', $exclude) . ')';
        }
        if ($category) {
            $where[] = 'a.category = :cat';
            $params['cat'] = $category;
        }
        if ($keywords) {
            $or = [];
            foreach ($keywords as $i => $k) {
                $or[] = "a.search_text LIKE :k{$i}";
                $params["k{$i}"] = '% ' . str_replace(['%', '_'], ['\\%', '\\_'], $k) . ' %';
            }
            $where[] = '(' . implode(' OR ', array_map(fn ($c) => $c . " ESCAPE '\\'", $or)) . ')';
        }
        $sql = 'SELECT a.id, a.source_id, a.title, a.url, a.excerpt, a.image_url, a.author, a.published_at, a.language, a.category, a.cluster_id, a.title_tokens,
                       s.name AS source_name, s.domain AS source_domain, s.geo_group,
                       COALESCE(ua.saved, 0) AS saved, COALESCE(ua.seen, 0) AS seen
                FROM articles a
                JOIN sources s ON s.id = a.source_id
                LEFT JOIN user_articles ua ON ua.article_id = a.id AND ua.user_id = :uid
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY a.published_at DESC
                LIMIT ' . (int) config('feed_pool_limit', 500);
        return q($sql, $params)->fetchAll();
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
        ];
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
                'INSERT INTO user_articles (user_id, article_id, seen, saved, first_seen_at, saved_at, created_at, updated_at)
                 VALUES (:uid, :aid, 1, 1, :now, :now, :now, :now)
                 ON CONFLICT (user_id, article_id) DO UPDATE SET saved = 1, seen = 1, saved_at = COALESCE(saved_at, excluded.saved_at),
                    first_seen_at = COALESCE(first_seen_at, excluded.first_seen_at), updated_at = excluded.updated_at',
                ['uid' => $userId, 'aid' => $articleId, 'now' => $now]
            );
        } else {
            q('UPDATE user_articles SET saved = 0, saved_at = NULL, updated_at = ? WHERE user_id = ? AND article_id = ?', [$now, $userId, $articleId]);
        }
        return true;
    }

    public static function saved(int $userId): array
    {
        return q(
            'SELECT a.id, a.title, a.url, a.excerpt, a.image_url, a.author, a.published_at, a.category, s.name AS source_name, s.domain AS source_domain, s.geo_group, ua.saved_at
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
