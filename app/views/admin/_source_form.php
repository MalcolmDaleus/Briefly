<?php
/** @var array $s source values (existing row or discovery result) */
$v = static fn (string $key) => e($s[$key] ?? '');
?>
<div class="form-grid">
    <div class="field"><label>Name</label><input class="input" name="name" required value="<?= $v('name') ?>"></div>
    <div class="field"><label>Domain</label><input class="input" name="domain" value="<?= $v('domain') ?>"></div>
    <div class="field" style="grid-column:1/-1"><label>Feed URL</label><input class="input" name="feed_url" type="url" required value="<?= $v('feed_url') ?>"></div>
    <div class="field" style="grid-column:1/-1"><label>Homepage</label><input class="input" name="homepage_url" type="url" value="<?= $v('homepage_url') ?>"></div>
    <div class="field">
        <label>Region group</label>
        <select class="input" name="geo_group">
            <?php foreach (Sources::GEO as $key => $g): ?>
                <option value="<?= e($key) ?>"<?= ($s['geo_group'] ?? '') === $key ? ' selected' : '' ?>><?= e($g[0]) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label>Language</label>
        <select class="input" name="language">
            <?php foreach (Sources::LANGUAGES as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= ($s['language'] ?? '') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field"><label>Country (ISO code)</label><input class="input" name="country" maxlength="3" placeholder="auto from region" value="<?= $v('country') ?>"></div>
    <div class="field"><label>Region / city</label><input class="input" name="region" placeholder="auto from region" value="<?= $v('region') ?>"></div>
    <div class="field">
        <label>Scope</label>
        <select class="input" name="scope">
            <option value="">Auto from region</option>
            <?php foreach (Sources::SCOPES as $scope): ?>
                <option value="<?= e($scope) ?>"<?= ($s['scope'] ?? '') === $scope ? ' selected' : '' ?>><?= e(ucfirst($scope)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label>Type</label>
        <select class="input" name="source_type">
            <?php foreach (Sources::TYPES as $type): ?>
                <option value="<?= e($type) ?>"<?= ($s['source_type'] ?? '') === $type ? ' selected' : '' ?>><?= e(ucfirst($type)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label>Default category</label>
        <select class="input" name="default_category">
            <option value="">None (use article data)</option>
            <?php foreach (Categories::LABELS as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= ($s['default_category'] ?? '') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field" style="grid-column:1/-1"><label>Notes</label><input class="input" name="notes" value="<?= $v('notes') ?>"></div>
</div>
