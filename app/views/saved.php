<main class="page">
    <div class="page-head">
        <div>
            <h1>Saved</h1>
            <p><?= count($articles) ?> article<?= count($articles) === 1 ? '' : 's' ?> to come back to</p>
        </div>
    </div>

    <?php if (!$articles): ?>
        <div class="empty">
            <svg class="icon" style="width:40px;height:40px"><use href="#i-bookmark"/></svg>
            <h2>Nothing saved yet</h2>
            <p>Tap the bookmark on any card in your feed to keep it here.</p>
            <p><a class="btn btn-primary" href="<?= e(url('/')) ?>">Open the feed</a></p>
        </div>
    <?php else: ?>
        <div class="saved-grid">
            <?php foreach ($articles as $a): ?>
                <?php $hasNote = trim((string) ($a['note'] ?? '')) !== ''; ?>
                <article class="saved-item" data-geo="<?= e($a['geo_group']) ?>" data-id="<?= (int) $a['id'] ?>">
                    <a class="saved-thumb" href="<?= e($a['url']) ?>" target="_blank" rel="noopener" tabindex="-1" data-track-open="<?= (int) $a['id'] ?>">
                        <?php if ($a['image_url']): ?>
                            <img src="<?= e($a['image_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" data-fallback="<?= e($a['source_name']) ?>">
                        <?php else: ?>
                            <div class="placeholder" data-hue="<?= e($a['source_name']) ?>"><?= e(mb_substr($a['source_name'], 0, 2)) ?></div>
                        <?php endif; ?>
                    </a>
                    <div class="saved-body">
                        <h2><a href="<?= e($a['url']) ?>" target="_blank" rel="noopener" data-track-open="<?= (int) $a['id'] ?>"><?= e($a['title']) ?></a></h2>
                        <div class="saved-meta">
                            <?= e($a['source_name']) ?> · <time datetime="<?= e(iso_utc($a['published_at'])) ?>" data-ago><?= e(time_ago($a['published_at'])) ?></time>
                            · <?= e(Categories::label($a['category'])) ?>
                        </div>
                        <?php if ($hasNote): ?>
                            <p class="saved-note"><?= e($a['note']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="saved-actions">
                        <button class="btn-note" type="button"
                            aria-label="<?= $hasNote ? 'Edit note' : 'Add note' ?>"
                            data-note-edit="<?= (int) $a['id'] ?>"
                            data-note="<?= e($a['note'] ?? '') ?>"
                            data-title="<?= e($a['title']) ?>">
                            <svg class="icon"><use href="#i-note"/></svg>
                        </button>
                        <button class="btn-save" type="button" aria-pressed="true" aria-label="Remove from saved" data-save-toggle="<?= (int) $a['id'] ?>">
                            <svg class="icon"><use href="#i-bookmark"/></svg>
                        </button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
