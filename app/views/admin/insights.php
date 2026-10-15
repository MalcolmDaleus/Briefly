<?php
$n = static fn ($v) => (int) ($v ?? 0);
$pct = static function ($part, $whole): string {
    $w = (int) $whole;
    if ($w <= 0) {
        return '—';
    }
    return round(100 * ((int) $part) / $w) . '%';
};
$orderLabel = static function (array $row): string {
    $opened = $row['first_opened_at'] ?? null;
    $saved = $row['first_saved_at'] ?? null;
    if ($opened && $saved) {
        if ($opened < $saved) {
            return 'Opened, then saved';
        }
        if ($saved < $opened) {
            return 'Saved, then opened';
        }
        return 'Same moment';
    }
    if ($opened) {
        return 'Opened only';
    }
    if ($saved) {
        return 'Saved only';
    }
    return '—';
};
?>
<main class="page">
    <div class="page-head">
        <div>
            <h1>Insights</h1>
            <p>Your reading: what you saw in the feed, what you opened, and what you saved.</p>
        </div>
        <a class="btn btn-small" href="<?= e(url('admin/sources')) ?>">Sources</a>
    </div>

    <div class="stats" style="margin-bottom:18px">
        <div class="stat"><b><?= $n($summary['seen']) ?></b><span>seen in feed</span></div>
        <div class="stat"><b><?= $n($summary['opened']) ?></b><span>articles opened</span></div>
        <div class="stat"><b><?= $n($summary['open_events']) ?></b><span>total opens</span></div>
        <div class="stat"><b><?= $n($summary['saved_now']) ?></b><span>saved now</span></div>
        <div class="stat"><b><?= $n($summary['ever_saved']) ?></b><span>ever saved</span></div>
        <div class="stat"><b><?= e($pct($summary['opened'] ?? 0, $summary['seen'] ?? 0)) ?></b><span>open rate</span></div>
    </div>

    <section class="panel">
        <h2>Open vs save order</h2>
        <p class="hint" style="margin-top:0">Based on the first time each article was opened or saved.</p>
        <div class="stats">
            <div class="stat"><b><?= $n($order['opened_then_saved']) ?></b><span>opened, then saved</span></div>
            <div class="stat"><b><?= $n($order['saved_then_opened']) ?></b><span>saved, then opened</span></div>
            <div class="stat"><b><?= $n($order['opened_never_saved']) ?></b><span>opened, never saved</span></div>
            <div class="stat"><b><?= $n($order['saved_never_opened']) ?></b><span>saved, never opened</span></div>
        </div>
    </section>

    <div class="panel-grid">
        <section class="panel">
            <h2>Sources you open most</h2>
            <?php if (!$bySource): ?>
                <p class="hint">No reading data yet. Swipe the feed and open a few stories.</p>
            <?php else: ?>
                <div class="insight-table">
                    <div class="insight-row insight-head"><span>Source</span><span>Opens</span><span>Opened</span><span>Seen</span><span>Saved</span></div>
                    <?php foreach ($bySource as $row): ?>
                        <a class="insight-row" href="<?= e(url('admin/sources/' . $row['id'])) ?>">
                            <span class="name"><?= e($row['name']) ?><small><?= e(Sources::geoLabel($row['geo_group'], true)) ?> · <?= e(strtoupper((string) $row['language'])) ?></small></span>
                            <span><?= $n($row['open_events']) ?></span>
                            <span><?= $n($row['opened_n']) ?></span>
                            <span><?= $n($row['seen_n']) ?></span>
                            <span><?= $n($row['saved_n']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Topics</h2>
            <?php if (!$byCategory): ?>
                <p class="hint">Nothing here yet.</p>
            <?php else: ?>
                <div class="insight-table">
                    <div class="insight-row insight-head"><span>Category</span><span>Opens</span><span>Opened</span><span>Seen</span><span>Saved</span></div>
                    <?php foreach ($byCategory as $row): ?>
                        <div class="insight-row">
                            <span class="name"><?= e(Categories::label($row['category'])) ?></span>
                            <span><?= $n($row['open_events']) ?></span>
                            <span><?= $n($row['opened_n']) ?></span>
                            <span><?= $n($row['seen_n']) ?></span>
                            <span><?= $n($row['saved_n']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="panel">
        <h2>Locations</h2>
        <?php if (!$byGeo): ?>
            <p class="hint">Nothing here yet.</p>
        <?php else: ?>
            <div class="insight-table">
                <div class="insight-row insight-head"><span>Location</span><span>Opens</span><span>Opened</span><span>Seen</span><span>Saved</span></div>
                <?php foreach ($byGeo as $row): ?>
                    <div class="insight-row">
                        <span class="name" data-geo="<?= e($row['geo_group']) ?>"><span class="chip-geo" style="display:inline-flex"><?= e(Sources::geoLabel($row['geo_group'], true)) ?></span></span>
                        <span><?= $n($row['open_events']) ?></span>
                        <span><?= $n($row['opened_n']) ?></span>
                        <span><?= $n($row['seen_n']) ?></span>
                        <span><?= $n($row['saved_n']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Recently opened</h2>
        <?php if (!$recentOpens): ?>
            <p class="hint">No opens recorded yet. Opening the original article (image, title, or Read button) counts.</p>
        <?php else: ?>
            <div class="insight-list">
                <?php foreach ($recentOpens as $a): ?>
                    <article class="insight-article">
                        <h3><a href="<?= e($a['url']) ?>" target="_blank" rel="noopener" data-track-open="<?= (int) $a['id'] ?>"><?= e($a['title']) ?></a></h3>
                        <div class="saved-meta">
                            <?= e($a['source_name']) ?>
                            · <?= e(Categories::label($a['category'])) ?>
                            · <?= e(Sources::geoLabel($a['geo_group'], true)) ?>
                            · opened <?= (int) $a['open_count'] ?>×
                            · last <time datetime="<?= e(iso_utc($a['last_opened_at'])) ?>" data-ago><?= e(time_ago($a['last_opened_at'])) ?></time>
                            · <?= e($orderLabel($a)) ?>
                            <?php if (!empty($a['saved'])): ?> · saved now<?php endif; ?>
                        </div>
                        <?php if (!empty($a['note'])): ?>
                            <p class="saved-note"><?= e($a['note']) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
