<?php
$tabs = ['approved' => 'Active', 'pending' => 'Pending review', 'problems' => 'Problems', 'disabled' => 'Disabled', 'rejected' => 'Rejected / blocked', 'all' => 'All'];
$last = $stats['last_retrieval'];
$cron = $stats['last_cron'];
$health = static function (array $s): array {
    if (!$s['is_active']) return ['', 'Disabled'];
    if ($s['access_status'] === 'denied') return ['bad', 'Access denied'];
    if ($s['access_status'] === 'failing') return ['bad', 'Failing'];
    if ($s['fail_count'] > 0) return ['warn', 'Last fetch failed'];
    if ($s['access_status'] === 'ok') return ['ok', 'OK'];
    return ['', 'Not fetched yet'];
};
?>
<main class="page">
    <div class="page-head">
        <div>
            <h1>Sources</h1>
            <p><?= $counts['approved'] ?> active sources · <?= $stats['articles'] ?> articles in the last <?= (int) config('feed_window_days', 7) ?> days · <?= $stats['available'] ?> unseen from the last <?= (int) config('pool_fresh_hours', 24) ?>h</p>
        </div>
        <a class="btn btn-primary" href="<?= e(url('admin/sources/new')) ?>">+ Add source</a>
    </div>

    <div class="panel-grid">
        <section class="panel">
            <h2>Retrieval</h2>
            <div class="stats" style="margin-bottom:12px">
                <div class="stat"><b><?= $stats['due'] ?></b><span>sources due now</span></div>
                <div class="stat"><b><?= $cron ? e(time_ago($cron['at'])) : 'never' ?></b><span>last cron run</span></div>
                <div class="stat"><b><?= $last ? (int) $last['new_articles'] : 0 ?></b><span>new in last run<?= $last ? ' (' . e($last['mode']) . ', ' . e(time_ago($last['at'])) . ')' : '' ?></span></div>
            </div>
            <div class="actions-row">
                <form method="post" action="<?= e(url('admin/retrieve')) ?>"><?= csrf_field() ?><button class="btn btn-small" type="submit">Run retrieval now</button></form>
                <form method="post" action="<?= e(url('admin/retrieve')) ?>"><?= csrf_field() ?><input type="hidden" name="force" value="1"><button class="btn btn-small" type="submit" title="Request every source whose 15-minute interval has passed">Fetch everything</button></form>
                <form method="post" action="<?= e(url('admin/sources/import-seed')) ?>"><?= csrf_field() ?><button class="btn btn-small" type="submit">Import seed list</button></form>
            </div>
            <?php if (!$cron): ?>
                <p class="hint" style="color:var(--warn)">The 15-minute cron job hasn't run yet. See “Cron setup” below.</p>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Add sources quickly</h2>
            <form method="post" action="<?= e(url('admin/sources/bulk')) ?>">
                <?= csrf_field() ?>
                <textarea class="input" name="urls" placeholder="Paste one or more site, article or feed URLs&#10;https://www.example-news.com&#10;https://another-paper.es/some-article" style="min-height:90px"></textarea>
                <p class="hint">Feeds are found automatically; each lands in <b>Pending review</b> for you to set its region and approve.</p>
                <button class="btn btn-small" type="submit" style="margin-top:8px">Discover &amp; queue</button>
            </form>
        </section>
    </div>

    <nav class="tabs" aria-label="Source status">
        <?php foreach ($tabs as $key => $label): ?>
            <a class="pill" href="<?= e(url('admin/sources', ['status' => $key])) ?>"<?= $filter === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="count"><?= $counts[$key] ?></span></a>
        <?php endforeach; ?>
    </nav>

    <?php if (!$sources): ?>
        <div class="empty"><p>No sources here.</p></div>
    <?php else: ?>
        <div class="source-list">
            <?php $currentGeo = null; foreach ($sources as $s): [$dot, $healthLabel] = $health($s); ?>
                <?php if ($s['geo_group'] !== $currentGeo): $currentGeo = $s['geo_group']; ?>
                    <h3 class="label" style="margin:14px 2px 2px" data-geo="<?= e($currentGeo) ?>"><span class="chip-geo" style="display:inline-flex;align-items:center;gap:6px"><?= e(Sources::geoLabel($currentGeo)) ?></span></h3>
                <?php endif; ?>
                <a class="source-row" href="<?= e(url('admin/sources/' . $s['id'])) ?>">
                    <span class="name"><span class="status-dot <?= $dot ?>" title="<?= e($healthLabel) ?>"></span><?= e($s['name']) ?></span>
                    <span class="tag"><?= e(strtoupper($s['language'])) ?></span>
                    <span class="meta">
                        <span><?= e($s['domain']) ?></span>
                        <span><?= (int) $s['recent_articles'] ?> articles / <?= (int) config('feed_window_days', 7) ?>d</span>
                        <span><?= (int) ($stats['available_by_source'][$s['id']] ?? 0) ?> unseen fresh</span>
                        <span>checked <?= $s['last_fetched_at'] ? e(time_ago($s['last_fetched_at'])) : 'never' ?></span>
                        <?php if ($s['status'] !== 'approved'): ?><span class="tag"><?= e($s['status']) ?></span><?php endif; ?>
                        <?php if ($s['last_error']): ?><span style="color:var(--bad)"><?= e(mb_substr($s['last_error'], 0, 80)) ?></span><?php endif; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <section class="panel" style="margin-top:24px">
        <h2>Cron setup &amp; shortcuts</h2>
        <details>
            <summary>Background retrieval (every 15 minutes)</summary>
            <p class="hint">Hostinger hPanel → Advanced → Cron Jobs → “Custom” and paste this command, every 15 minutes:</p>
            <code class="code"><?= e($cronCommand) ?></code>
            <p class="hint">Alternative if your plan only allows URL cron jobs (keep this URL private):</p>
            <code class="code">curl -s "<?= e($cronUrl) ?>" &gt; /dev/null</code>
        </details>
        <details style="margin-top:10px">
            <summary>“Add to Briefly” bookmarklet</summary>
            <p class="hint">Drag this link to your bookmarks bar. While reading any news site, click it to add that site as a source.</p>
            <p><a class="btn btn-small" href="<?= e($bookmarklet) ?>">+ Add to Briefly</a></p>
            <p class="hint">On Android, install Briefly to your home screen (browser menu → “Add to Home screen”); it then appears in the share sheet — share any article to Briefly to add its site.</p>
        </details>
    </section>
</main>
