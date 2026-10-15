<?php
declare(strict_types=1);

final class Admin
{
    private const FILTERS = [
        'approved' => "status = 'approved' AND is_active = 1",
        'pending' => "status = 'pending'",
        'problems' => "status = 'approved' AND (access_status IN ('failing', 'denied') OR fail_count > 0)",
        'disabled' => "status = 'approved' AND is_active = 0",
        'rejected' => "status IN ('rejected', 'blocked')",
        'all' => '1 = 1',
    ];

    public static function sources(): void
    {
        Auth::requireAdmin();
        $filter = isset(self::FILTERS[$_GET['status'] ?? '']) ? $_GET['status'] : 'approved';
        $counts = [];
        foreach (self::FILTERS as $key => $where) {
            $counts[$key] = (int) q("SELECT COUNT(*) FROM sources WHERE {$where}")->fetchColumn();
        }
        $since = now_utc(-(int) config('feed_window_days', 7) * 86400);
        $sources = q(
            'SELECT s.*, (SELECT COUNT(*) FROM articles a WHERE a.source_id = s.id AND a.published_at >= ?) AS recent_articles
             FROM sources s WHERE ' . self::FILTERS[$filter] . ' ORDER BY s.geo_group, s.name',
            [$since]
        )->fetchAll();

        view('admin/sources', [
            'title' => 'Sources · ' . config('app_name'),
            'bodyClass' => 'page-admin',
            'filter' => $filter,
            'counts' => $counts,
            'sources' => $sources,
            'stats' => [
                'articles' => (int) q('SELECT COUNT(*) FROM articles WHERE published_at >= ?', [$since])->fetchColumn(),
                'available' => Retrieval::availableCount(),
                'due' => count(Retrieval::dueSources()),
                'available_by_source' => Retrieval::availableBySource(),
                'last_cron' => state_get('last_cron'),
                'last_retrieval' => state_get('last_retrieval'),
            ],
            'cronCommand' => 'php ' . str_replace('\\', '/', APP_ROOT) . '/cron/fetch.php',
            'cronUrl' => absolute_url('tasks/fetch') . '?key=' . config('cron_key'),
            'bookmarklet' => "javascript:location.href='" . absolute_url('admin/sources/new') . "?url='+encodeURIComponent(location.href)",
        ]);
    }

    public static function source(int $id): void
    {
        Auth::requireAdmin();
        $source = Sources::find($id) ?? not_found();
        $recent = q(
            'SELECT id, title, url, image_url, author, excerpt, published_at, category, categories_raw, tags, cluster_id,
                    (SELECT COUNT(DISTINCT b.source_id) FROM articles b WHERE b.cluster_id = articles.cluster_id) AS cluster_sources
             FROM articles WHERE source_id = ? ORDER BY published_at DESC LIMIT 12',
            [$id]
        )->fetchAll();
        view('admin/source', [
            'title' => $source['name'] . ' · Sources',
            'bodyClass' => 'page-admin',
            'source' => $source,
            'profile' => $source['metadata_profile'] ? json_decode($source['metadata_profile'], true) : null,
            'recent' => $recent,
            'articleCount' => (int) q('SELECT COUNT(*) FROM articles WHERE source_id = ?', [$id])->fetchColumn(),
        ]);
    }

    public static function sourceUpdate(int $id): void
    {
        Auth::requireAdmin();
        Sources::find($id) ?? not_found();
        $data = array_intersect_key($_POST, array_flip(Sources::EDITABLE));
        if (!is_http_url($data['feed_url'] ?? '')) {
            flash('Feed URL must start with http:// or https://');
            redirect("/admin/sources/{$id}");
        }
        try {
            Sources::update($id, $data);
            flash('Source saved.');
        } catch (PDOException $e) {
            flash('Could not save: another source already uses that feed URL.');
        }
        redirect("/admin/sources/{$id}");
    }

    public static function sourceStatus(int $id): void
    {
        Auth::requireAdmin();
        Sources::setStatus($id, (string) ($_POST['status'] ?? ''));
        flash('Status updated.');
        redirect("/admin/sources/{$id}");
    }

    public static function sourceActive(int $id): void
    {
        Auth::requireAdmin();
        Sources::setActive($id, !empty($_POST['active']));
        flash(!empty($_POST['active']) ? 'Source enabled.' : 'Source disabled.');
        redirect("/admin/sources/{$id}");
    }

    public static function sourceFetch(int $id): void
    {
        Auth::requireAdmin();
        $r = Retrieval::fetchNow($id);
        flash($r['error'] ? 'Fetch failed: ' . $r['error'] : "Fetched {$r['items']} items, {$r['new']} new articles stored.");
        redirect("/admin/sources/{$id}");
    }

    /** Add-source screen. Accepts ?url=… directly, or ?text=… from the Android share sheet. */
    public static function newSource(): void
    {
        Auth::requireAdmin();
        $input = trim((string) ($_GET['url'] ?? ''));
        if ($input === '') {
            $shared = (string) ($_GET['text'] ?? '') . ' ' . (string) ($_GET['title'] ?? '');
            if (preg_match('#https?://\S+#', $shared, $m)) {
                $input = $m[0];
            }
        }
        $discovery = $input !== '' ? SourceDiscovery::discover($input) : null;
        view('admin/new', [
            'title' => 'Add source · ' . config('app_name'),
            'bodyClass' => 'page-admin',
            'input' => $input,
            'discovery' => $discovery,
        ]);
    }

    public static function createSource(): void
    {
        Auth::requireAdmin();
        $data = array_intersect_key($_POST, array_flip(Sources::EDITABLE));
        $feedUrl = trim((string) ($data['feed_url'] ?? ''));
        if (!is_http_url($feedUrl)) {
            flash('Feed URL must start with http:// or https://');
            redirect('/admin/sources/new');
        }
        if ($existing = Sources::findByFeed($feedUrl)) {
            flash('That feed is already in your sources.');
            redirect('/admin/sources/' . $existing['id']);
        }
        $status = ($_POST['status'] ?? '') === 'pending' ? 'pending' : 'approved';
        $data['feed_type'] = in_array($_POST['feed_type'] ?? '', ['rss', 'atom', 'rdf'], true) ? $_POST['feed_type'] : 'rss';
        $id = Sources::create($data, $status, 'manual');
        if ($status === 'approved') {
            $r = Retrieval::fetchNow($id);
            flash($r['error'] ? 'Source added, but the first fetch failed: ' . $r['error'] : "Source added and approved — {$r['new']} articles stored.");
        } else {
            flash('Source added as pending review.');
        }
        redirect("/admin/sources/{$id}");
    }

    /** Paste several URLs; each is discovered and queued as a pending source. */
    public static function bulkAdd(): void
    {
        Auth::requireAdmin();
        @set_time_limit(180);
        preg_match_all('#(?:https?://)?[a-z0-9.-]+\.[a-z]{2,}(?:/\S*)?#i', (string) ($_POST['urls'] ?? ''), $m);
        $urls = array_slice(array_unique($m[0]), 0, 15);
        $added = [];
        $failed = [];
        foreach ($urls as $url) {
            $d = SourceDiscovery::discover($url);
            if (!$d['ok']) {
                $failed[] = $url;
                continue;
            }
            if ($d['existing']) {
                continue;
            }
            Sources::create($d, 'pending', 'discovery:bulk');
            $added[] = $d['name'];
        }
        flash(sprintf(
            '%d added to Pending review%s%s',
            count($added),
            $added ? ' (' . implode(', ', $added) . ')' : '',
            $failed ? '. No feed found for: ' . implode(', ', $failed) : '.'
        ));
        redirect('/admin/sources', ['status' => 'pending']);
    }

    public static function importSeed(): void
    {
        Auth::requireAdmin();
        $n = Sources::importSeed();
        flash($n ? "Imported {$n} new sources from the seed list." : 'Seed list already imported — nothing new.');
        redirect('/admin/sources');
    }

    public static function retrieve(): void
    {
        Auth::requireAdmin();
        $s = Retrieval::topUp('cron', !empty($_POST['force']));
        if (isset($s['skipped'])) {
            flash('Skipped: ' . $s['skipped'] . '.');
        } else {
            flash(sprintf(
                'Retrieval done in %ss: %d of %d due feeds requested (%d unchanged, %d failed), %d new articles.',
                $s['seconds'], $s['sources_fetched'], $s['due'], $s['not_modified'], $s['failed'], $s['new_articles']
            ));
        }
        redirect('/admin/sources');
    }

    /** Reading habits for the logged-in admin (seen / opened / saved). */
    public static function insights(): void
    {
        $user = Auth::requireAdmin();
        $uid = (int) $user['id'];

        $summary = q(
            'SELECT
                COUNT(*) AS touched,
                SUM(CASE WHEN seen = 1 THEN 1 ELSE 0 END) AS seen,
                SUM(CASE WHEN opened = 1 THEN 1 ELSE 0 END) AS opened,
                SUM(CASE WHEN saved = 1 THEN 1 ELSE 0 END) AS saved_now,
                SUM(CASE WHEN first_saved_at IS NOT NULL THEN 1 ELSE 0 END) AS ever_saved,
                COALESCE(SUM(open_count), 0) AS open_events
             FROM user_articles WHERE user_id = ?',
            [$uid]
        )->fetch() ?: [];

        $order = q(
            'SELECT
                SUM(CASE WHEN first_opened_at IS NOT NULL AND first_saved_at IS NOT NULL AND first_opened_at < first_saved_at THEN 1 ELSE 0 END) AS opened_then_saved,
                SUM(CASE WHEN first_opened_at IS NOT NULL AND first_saved_at IS NOT NULL AND first_saved_at < first_opened_at THEN 1 ELSE 0 END) AS saved_then_opened,
                SUM(CASE WHEN first_opened_at IS NOT NULL AND first_saved_at IS NOT NULL AND first_opened_at = first_saved_at THEN 1 ELSE 0 END) AS same_moment,
                SUM(CASE WHEN first_opened_at IS NOT NULL AND first_saved_at IS NULL THEN 1 ELSE 0 END) AS opened_never_saved,
                SUM(CASE WHEN first_opened_at IS NULL AND first_saved_at IS NOT NULL THEN 1 ELSE 0 END) AS saved_never_opened
             FROM user_articles WHERE user_id = ?',
            [$uid]
        )->fetch() ?: [];

        $bySource = q(
            'SELECT s.id, s.name, s.geo_group, s.language,
                    COUNT(*) AS seen_n,
                    SUM(CASE WHEN ua.opened = 1 THEN 1 ELSE 0 END) AS opened_n,
                    COALESCE(SUM(ua.open_count), 0) AS open_events,
                    SUM(CASE WHEN ua.saved = 1 THEN 1 ELSE 0 END) AS saved_n
             FROM user_articles ua
             JOIN articles a ON a.id = ua.article_id
             JOIN sources s ON s.id = a.source_id
             WHERE ua.user_id = ? AND ua.seen = 1
             GROUP BY s.id
             ORDER BY open_events DESC, opened_n DESC, seen_n DESC
             LIMIT 25',
            [$uid]
        )->fetchAll();

        $byCategory = q(
            'SELECT a.category,
                    COUNT(*) AS seen_n,
                    SUM(CASE WHEN ua.opened = 1 THEN 1 ELSE 0 END) AS opened_n,
                    COALESCE(SUM(ua.open_count), 0) AS open_events,
                    SUM(CASE WHEN ua.saved = 1 THEN 1 ELSE 0 END) AS saved_n
             FROM user_articles ua
             JOIN articles a ON a.id = ua.article_id
             WHERE ua.user_id = ? AND ua.seen = 1
             GROUP BY a.category
             ORDER BY open_events DESC, opened_n DESC, seen_n DESC',
            [$uid]
        )->fetchAll();

        $byGeo = q(
            'SELECT s.geo_group,
                    COUNT(*) AS seen_n,
                    SUM(CASE WHEN ua.opened = 1 THEN 1 ELSE 0 END) AS opened_n,
                    COALESCE(SUM(ua.open_count), 0) AS open_events,
                    SUM(CASE WHEN ua.saved = 1 THEN 1 ELSE 0 END) AS saved_n
             FROM user_articles ua
             JOIN articles a ON a.id = ua.article_id
             JOIN sources s ON s.id = a.source_id
             WHERE ua.user_id = ? AND ua.seen = 1
             GROUP BY s.geo_group
             ORDER BY open_events DESC, opened_n DESC, seen_n DESC',
            [$uid]
        )->fetchAll();

        $recentOpens = q(
            'SELECT a.id, a.title, a.url, a.category, a.published_at,
                    s.name AS source_name, s.geo_group,
                    ua.open_count, ua.first_opened_at, ua.last_opened_at,
                    ua.saved, ua.first_saved_at, ua.saved_at, ua.note
             FROM user_articles ua
             JOIN articles a ON a.id = ua.article_id
             JOIN sources s ON s.id = a.source_id
             WHERE ua.user_id = ? AND ua.opened = 1
             ORDER BY ua.last_opened_at DESC
             LIMIT 40',
            [$uid]
        )->fetchAll();

        view('admin/insights', [
            'title' => 'Insights · ' . config('app_name'),
            'bodyClass' => 'page-admin',
            'summary' => $summary,
            'order' => $order,
            'bySource' => $bySource,
            'byCategory' => $byCategory,
            'byGeo' => $byGeo,
            'recentOpens' => $recentOpens,
        ]);
    }
}
