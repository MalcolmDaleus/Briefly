<main class="page">
    <p style="margin:0 0 6px"><a class="muted" href="<?= e(url('admin/sources')) ?>">← Sources</a></p>
    <div class="page-head">
        <div>
            <h1>Add a source</h1>
            <p>Paste a homepage, any article from the site, or its RSS/Atom feed.</p>
        </div>
    </div>

    <form class="panel" method="get" action="<?= e(url('admin/sources/new')) ?>">
        <div class="field" style="margin:0">
            <label for="url">Website or feed URL</label>
            <div style="display:flex;gap:8px">
                <input class="input" id="url" name="url" type="text" inputmode="url" placeholder="https://www.example.com" value="<?= e($input) ?>" required autofocus>
                <button class="btn btn-primary" type="submit">Find feed</button>
            </div>
        </div>
    </form>

    <?php if ($discovery && !$discovery['ok']): ?>
        <div class="alert alert-error"><?= e($discovery['error']) ?></div>
    <?php elseif ($discovery): $s = $discovery; ?>
        <?php if ($discovery['existing']): ?>
            <div class="alert">This feed is already a source: <a href="<?= e(url('admin/sources/' . $discovery['existing']['id'])) ?>"><?= e($discovery['existing']['name']) ?></a> (<?= e($discovery['existing']['status']) ?>).</div>
        <?php endif; ?>
        <div class="panel-grid">
            <section class="panel">
                <h2>Review &amp; add</h2>
                <p class="hint" style="margin-top:-6px;margin-bottom:12px">Region, language and name were guessed — check them before approving.</p>
                <form method="post" action="<?= e(url('admin/sources/new')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="feed_type" value="<?= e($s['feed_type']) ?>">
                    <?php require __DIR__ . '/_source_form.php'; ?>
                    <div class="actions-row">
                        <button class="btn btn-primary" type="submit" name="status" value="approved">Add &amp; approve</button>
                        <button class="btn" type="submit" name="status" value="pending">Save as pending</button>
                    </div>
                </form>
            </section>
            <section class="panel">
                <h2>What this feed provides</h2>
                <?php $profile = $discovery['profile']; require __DIR__ . '/_profile.php'; ?>
            </section>
        </div>
    <?php endif; ?>
</main>
