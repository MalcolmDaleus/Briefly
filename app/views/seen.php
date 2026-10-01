<main class="page">
    <div class="page-head">
        <div>
            <h1>Seen</h1>
            <p><?= count($articles) ?> article<?= count($articles) === 1 ? '' : 's' ?> you’ve already opened in the feed</p>
        </div>
    </div>

    <?php if (!$articles): ?>
        <div class="empty">
            <svg class="icon" style="width:40px;height:40px"><use href="#i-seen"/></svg>
            <h2>Nothing seen yet</h2>
            <p>Cards you linger on in the feed show up here.</p>
            <p><a class="btn btn-primary" href="<?= e(url('/')) ?>">Open the feed</a></p>
        </div>
    <?php else: ?>
        <div class="seen-list">
            <?php foreach ($articles as $a): ?>
                <a class="seen-item" href="<?= e($a['url']) ?>" target="_blank" rel="noopener" data-geo="<?= e($a['geo_group']) ?>">
                    <h2><?= e($a['title']) ?></h2>
                    <div class="saved-meta">
                        <?= e($a['source_name']) ?>
                        · <?= e(Categories::label($a['category'])) ?>
                        · seen <time datetime="<?= e(iso_utc($a['first_seen_at'])) ?>" data-ago><?= e(time_ago($a['first_seen_at'])) ?></time>
                        <?php if (!empty($a['saved'])): ?> · saved<?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
