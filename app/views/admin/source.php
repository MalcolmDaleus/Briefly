<?php
$s = $source;
$statusActions = ['approved' => 'Approve', 'pending' => 'Mark pending', 'rejected' => 'Reject', 'blocked' => 'Block'];
?>
<main class="page">
    <p style="margin:0 0 6px"><a class="muted" href="<?= e(url('admin/sources')) ?>">← Sources</a></p>
    <div class="page-head">
        <div>
            <h1><?= e($s['name']) ?></h1>
            <p>
                <span class="tag"><?= e($s['status']) ?></span>
                <span class="tag"><?= $s['is_active'] ? 'enabled' : 'disabled' ?></span>
                <span class="tag">access: <?= e($s['access_status']) ?></span>
                · <?= $articleCount ?> stored articles
            </p>
        </div>
        <div class="actions-row">
            <form method="post" action="<?= e(url("admin/sources/{$s['id']}/fetch")) ?>"><?= csrf_field() ?><button class="btn btn-small" type="submit">Fetch now</button></form>
            <?php if ($s['homepage_url']): ?><a class="btn btn-small" href="<?= e($s['homepage_url']) ?>" target="_blank" rel="noopener">Visit site ↗</a><?php endif; ?>
        </div>
    </div>

    <div class="panel-grid">
        <section class="panel">
            <h2>Status</h2>
            <div class="actions-row" style="margin-bottom:12px">
                <?php foreach ($statusActions as $status => $label): if ($status === $s['status']) continue; ?>
                    <form method="post" action="<?= e(url("admin/sources/{$s['id']}/status")) ?>">
                        <?= csrf_field() ?><input type="hidden" name="status" value="<?= e($status) ?>">
                        <button class="btn btn-small<?= in_array($status, ['rejected', 'blocked'], true) ? ' btn-danger' : '' ?>" type="submit"><?= e($label) ?></button>
                    </form>
                <?php endforeach; ?>
                <form method="post" action="<?= e(url("admin/sources/{$s['id']}/active")) ?>">
                    <?= csrf_field() ?><input type="hidden" name="active" value="<?= $s['is_active'] ? '0' : '1' ?>">
                    <button class="btn btn-small" type="submit"><?= $s['is_active'] ? 'Disable' : 'Re-enable' ?></button>
                </form>
            </div>
            <dl class="kv">
                <dt>Last checked</dt><dd><?= $s['last_fetched_at'] ? e(time_ago($s['last_fetched_at'])) . ' (HTTP ' . e($s['last_status']) . ')' : 'never' ?></dd>
                <dt>Last success</dt><dd><?= $s['last_success_at'] ? e(time_ago($s['last_success_at'])) : 'never' ?></dd>
                <dt>Items / new</dt><dd><?= e($s['last_item_count'] ?? '—') ?> / <?= e($s['last_new_count'] ?? '—') ?></dd>
                <dt>Failures</dt><dd><?= (int) $s['fail_count'] ?><?= $s['retry_after'] ? ' · retry ' . e($s['retry_after']) . ' UTC' : '' ?></dd>
                <?php if ($s['last_error']): ?><dt>Last error</dt><dd style="color:var(--bad)"><?= e($s['last_error']) ?></dd><?php endif; ?>
                <dt>Caching</dt><dd><?= $s['etag'] || $s['last_modified'] ? 'conditional requests supported' : 'none (full download each time)' ?></dd>
                <dt>Added via</dt><dd><?= e($s['discovered_via'] ?? '—') ?><?= $s['discovered_at'] ? ', ' . e(time_ago($s['discovered_at'])) : '' ?></dd>
            </dl>
        </section>

        <section class="panel">
            <h2>What this feed provides</h2>
            <?php if ($profile): ?>
                <?php require __DIR__ . '/_profile.php'; ?>
            <?php else: ?>
                <p class="muted">Not fetched yet. Use “Fetch now” to inspect the feed.</p>
            <?php endif; ?>
        </section>
    </div>

    <section class="panel">
        <h2>Details</h2>
        <form method="post" action="<?= e(url("admin/sources/{$s['id']}/update")) ?>">
            <?= csrf_field() ?>
            <?php require __DIR__ . '/_source_form.php'; ?>
            <button class="btn btn-primary" type="submit">Save changes</button>
        </form>
    </section>

    <?php if ($recent): ?>
        <section class="panel">
            <h2>Recent articles</h2>
            <?php foreach ($recent as $a): ?>
                <div class="sample">
                    <?php if ($a['image_url']): ?><img src="<?= e($a['image_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><div class="noimg"></div><?php endif; ?>
                    <div>
                        <div class="t"><a href="<?= e($a['url']) ?>" target="_blank" rel="noopener"><?= e($a['title']) ?></a></div>
                        <div class="muted">
                            <?= e(time_ago($a['published_at'])) ?> · <b><?= e(Categories::label($a['category'])) ?></b>
                            <?php if ($a['categories_raw']): ?>(from <?= e(implode(', ', json_decode($a['categories_raw'], true) ?: [])) ?>)<?php endif; ?>
                            <?php if ((int) $a['cluster_sources'] > 1): ?> · story covered by <?= (int) $a['cluster_sources'] ?> sources<?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
