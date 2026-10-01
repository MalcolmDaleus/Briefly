<?php
/** @var array $profile output of Retrieval::profile() */
$labels = ['title' => 'Title', 'url' => 'Link', 'excerpt' => 'Description', 'image_url' => 'Image', 'author' => 'Author', 'published_ts' => 'Date', 'categories' => 'Categories', 'tags' => 'Tags', 'external_id' => 'Article ID'];
$n = max(1, (int) $profile['items']);
?>
<dl class="kv" style="margin-bottom:14px">
    <dt>Format</dt><dd><?= e(strtoupper($profile['format'])) ?></dd>
    <dt>Feed title</dt><dd><?= e($profile['feed_title'] ?? '—') ?></dd>
    <dt>Declared language</dt><dd><?= e($profile['feed_language'] ?? '—') ?></dd>
    <dt>Items in feed</dt><dd><?= (int) $profile['items'] ?></dd>
    <dt>Newest item</dt><dd><?= $profile['newest'] ? e(time_ago($profile['newest'])) : '—' ?></dd>
    <?php if (!empty($profile['checked_at'])): ?><dt>Checked</dt><dd><?= e(time_ago($profile['checked_at'])) ?></dd><?php endif; ?>
</dl>

<div class="coverage">
    <?php foreach ($labels as $key => $label): $c = (int) ($profile['coverage'][$key] ?? 0); $pct = round($c / $n * 100); ?>
        <div class="coverage-row">
            <span><?= e($label) ?></span>
            <span class="bar"><i style="width:<?= $pct ?>%"></i></span>
            <span class="muted"><?= $c ?>/<?= (int) $profile['items'] ?></span>
        </div>
    <?php endforeach; ?>
</div>

<?php if (!empty($profile['raw_categories'])): ?>
    <h3 class="label" style="margin:16px 0 6px">Categories the source uses → mapped</h3>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
        <?php foreach ($profile['raw_categories'] as $cat => $count): ?>
            <span class="tag"><?= e($cat) ?> ×<?= (int) $count ?> → <?= e(Categories::label(Categories::match((string) $cat) ?? 'general')) ?></span>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($profile['elements'])): ?>
    <details style="margin-top:14px">
        <summary>Raw XML elements per item</summary>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px">
            <?php foreach ($profile['elements'] as $el => $count): ?>
                <span class="tag"><?= e($el) ?> ×<?= (int) $count ?></span>
            <?php endforeach; ?>
        </div>
    </details>
<?php endif; ?>

<?php if (!empty($profile['samples'])): ?>
    <h3 class="label" style="margin:16px 0 4px">Sample items (normalized)</h3>
    <?php foreach ($profile['samples'] as $item): ?>
        <div class="sample">
            <?php if ($item['image_url']): ?><img src="<?= e($item['image_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><div class="noimg"></div><?php endif; ?>
            <div>
                <div class="t"><a href="<?= e($item['url']) ?>" target="_blank" rel="noopener"><?= e($item['title']) ?></a></div>
                <div class="muted"><?= e($item['excerpt'] ?? 'No description') ?></div>
                <div class="muted" style="margin-top:4px">
                    <?= e($item['published'] ? time_ago($item['published']) : 'no date') ?> ·
                    <?= e($item['author'] ?? 'no author') ?> ·
                    raw: <?= e($item['categories'] ? implode(', ', $item['categories']) : 'none') ?> →
                    <b><?= e(Categories::label($item['mapped_category'])) ?></b>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
