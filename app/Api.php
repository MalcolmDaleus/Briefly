<?php
declare(strict_types=1);

final class Api
{
    public static function feed(): void
    {
        $user = Auth::requireUser();
        session_write_close(); // don't block parallel requests on the session lock

        $ids = static fn (string $key) => array_filter(array_map('intval', explode(',', (string) ($_GET[$key] ?? ''))));
        $opts = [
            'limit' => (int) ($_GET['limit'] ?? config('feed_batch_size', 15)),
            'exclude' => $ids('exclude'),
            'context' => $ids('context'),
            'category' => (string) ($_GET['category'] ?? ''),
            'q' => (string) ($_GET['q'] ?? ''),
            'include_seen' => !empty($_GET['include_seen']),
        ];
        $limit = max(1, min(30, $opts['limit']));
        $items = Feed::next((int) $user['id'], $opts);

        // Running dry: fetch a small batch of sources right now, then fill the gap.
        $retrieved = null;
        if (count($items) < $limit && empty($opts['include_seen'])) {
            $retrieved = Retrieval::topUp('on-demand');
            if (!empty($retrieved['new_articles'])) {
                $more = Feed::next((int) $user['id'], array_merge($opts, [
                    'limit' => $limit - count($items),
                    'exclude' => array_merge($opts['exclude'], array_column($items, 'id')),
                    'context' => array_merge($opts['context'], array_column($items, 'id')),
                ]));
                $items = array_merge($items, $more);
            }
        }

        $body = json_encode(['items' => $items, 'exhausted' => count($items) === 0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Length: ' . strlen((string) $body));
        echo $body;

        // Quietly keep the pool topped up between cron runs when sources are due.
        if ($retrieved === null && self::shouldTopUp()) {
            finish_request();
            Retrieval::topUp('on-demand');
        }
        exit;
    }

    public static function seen(): void
    {
        $user = Auth::requireUser();
        $data = request_json();
        $ids = $data['ids'] ?? [];
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        json_out(['marked' => Feed::markSeen((int) $user['id'], (array) $ids)]);
    }

    public static function save(): void
    {
        $user = Auth::requireUser();
        $data = request_json();
        $ok = Feed::setSaved((int) $user['id'], (int) ($data['id'] ?? 0), !empty($data['saved']));
        json_out(['ok' => $ok, 'saved' => !empty($data['saved'])], $ok ? 200 : 404);
    }

    public static function theme(): void
    {
        $user = Auth::requireUser();
        $theme = (string) (request_json()['theme'] ?? '');
        if (!in_array($theme, ['system', 'light', 'dark'], true)) {
            json_out(['error' => 'Unknown theme'], 422);
        }
        q('UPDATE users SET theme = ?, updated_at = ? WHERE id = ?', [$theme, now_utc(), $user['id']]);
        json_out(['ok' => true, 'theme' => $theme]);
    }

    private static function shouldTopUp(): bool
    {
        $last = state_get('last_retrieval');
        if (is_array($last) && isset($last['at']) && strtotime($last['at'] . ' UTC') > time() - 300) {
            return false;
        }
        return Retrieval::dueSources() !== [];
    }
}
