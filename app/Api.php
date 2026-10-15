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
            'q' => (string) ($_GET['q'] ?? ''),
            'category' => (string) ($_GET['category'] ?? ''),
            'category_mode' => (string) ($_GET['category_mode'] ?? 'include'),
            'geo' => (string) ($_GET['geo'] ?? ''),
            'geo_mode' => (string) ($_GET['geo_mode'] ?? 'include'),
            'lang' => (string) ($_GET['lang'] ?? ''),
            'lang_mode' => (string) ($_GET['lang_mode'] ?? 'include'),
            'within' => (string) ($_GET['within'] ?? ''),
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

    public static function opened(): void
    {
        $user = Auth::requireUser();
        $data = request_json();
        $ids = $data['ids'] ?? $data['id'] ?? [];
        if (is_string($ids) || is_int($ids)) {
            $ids = explode(',', (string) $ids);
        }
        json_out(['marked' => Feed::markOpened((int) $user['id'], (array) $ids)]);
    }

    public static function save(): void
    {
        $user = Auth::requireUser();
        $data = request_json();
        $ok = Feed::setSaved((int) $user['id'], (int) ($data['id'] ?? 0), !empty($data['saved']));
        json_out(['ok' => $ok, 'saved' => !empty($data['saved'])], $ok ? 200 : 404);
    }

    public static function note(): void
    {
        $user = Auth::requireUser();
        $data = request_json();
        $id = (int) ($data['id'] ?? 0);
        $note = (string) ($data['note'] ?? '');
        $ok = Feed::setNote((int) $user['id'], $id, $note);
        json_out(['ok' => $ok, 'note' => $ok ? (trim($note) === '' ? null : trim(mb_substr($note, 0, 2000))) : null], $ok ? 200 : 404);
    }

    public static function paywallCheck(): void
    {
        $user = Auth::requireUser();
        $id = (int) (request_json()['id'] ?? 0);
        $result = Feed::paywallCheck($id);
        if (!$result) {
            json_out(['error' => 'Not found'], 404);
        }
        json_out($result);
    }

    public static function dismiss(): void
    {
        $user = Auth::requireUser();
        $data = request_json();
        $id = (int) ($data['id'] ?? 0);
        $mark = !empty($data['paywalled']);
        $ok = Feed::dismiss((int) $user['id'], $id, $mark);
        $disabled = false;
        if ($ok && !empty($data['disable_source']) && !empty($user['is_admin'])) {
            $sourceId = (int) q('SELECT source_id FROM articles WHERE id = ?', [$id])->fetchColumn();
            if ($sourceId) {
                Sources::setActive($sourceId, false);
                $disabled = true;
            }
        }
        json_out(['ok' => $ok, 'disabled_source' => $disabled], $ok ? 200 : 404);
    }

    public static function paywallSafe(): void
    {
        $user = Auth::requireUser();
        $id = (int) (request_json()['id'] ?? 0);
        $result = Feed::markSourceSafe($id);
        if (!$result) {
            json_out(['error' => 'Not found'], 404);
        }
        json_out(['ok' => true] + $result);
    }

    public static function filters(): void
    {
        $user = Auth::requireUser();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            json_out(['filters' => Feed::getUserFilters((int) $user['id'])]);
        }
        $saved = Feed::saveUserFilters((int) $user['id'], request_json());
        json_out(['ok' => true, 'filters' => $saved]);
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
