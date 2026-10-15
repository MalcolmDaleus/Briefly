<?php
/** @var string $content */
$theme = $user['theme'] ?? 'system';
$bodyClass ??= '';
$isFeed = $bodyClass === 'page-feed';
$flashMessage = flash();
?>
<!doctype html>
<html lang="en"<?= $theme !== 'system' ? ' data-theme="' . e($theme) . '"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= e($title ?? config('app_name')) ?></title>
    <meta name="theme-color" content="#0d0d12">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="robots" content="noindex">
    <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
    <link rel="icon" href="<?= e(asset('icon.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e(asset('icon-192.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <script>
        window.BRIEFLY = <?= json_encode([
            'base' => base_path(),
            'csrf' => csrf_token(),
            'theme' => $theme,
            'isAdmin' => !empty($user['is_admin']),
            'filters' => isset($user['feed_filters']) && is_string($user['feed_filters']) && $user['feed_filters'] !== ''
                ? (json_decode($user['feed_filters'], true) ?: new stdClass())
                : new stdClass(),
        ], JSON_UNESCAPED_SLASHES) ?>;
    </script>
</head>
<body class="<?= e($bodyClass) ?>">
<svg xmlns="http://www.w3.org/2000/svg" style="display:none">
    <symbol id="i-bookmark" viewBox="0 0 24 24"><path d="M6 3.5h12a1 1 0 0 1 1 1V21l-7-4.5L5 21V4.5a1 1 0 0 1 1-1z"/></symbol>
    <symbol id="i-external" viewBox="0 0 24 24"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></symbol>
    <symbol id="i-filter" viewBox="0 0 24 24"><path d="M4 6h16M7 12h10M10 18h4"/></symbol>
    <symbol id="i-menu" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c1.2-3.5 4-5 7-5s5.8 1.5 7 5"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
    <symbol id="i-layers" viewBox="0 0 24 24"><path d="M12 3l9 5-9 5-9-5 9-5z"/><path d="M3 13l9 5 9-5"/></symbol>
    <symbol id="i-home" viewBox="0 0 24 24"><path d="M4 11l8-7 8 7v9a1 1 0 0 1-1 1h-4v-6h-6v6H5a1 1 0 0 1-1-1z"/></symbol>
    <symbol id="i-rss" viewBox="0 0 24 24"><path d="M5 11a8 8 0 0 1 8 8M5 5a14 14 0 0 1 14 14"/><circle cx="6" cy="18" r="1.5"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l-4-4 4-4M6 12h10"/></symbol>
    <symbol id="i-seen" viewBox="0 0 24 24"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></symbol>
    <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 11a8 8 0 0 0-14.9-3.5M4 4v4h4M4 13a8 8 0 0 0 14.9 3.5M20 20v-4h-4"/></symbol>
    <symbol id="i-note" viewBox="0 0 24 24"><path d="M5 4.5h10l4 4V19.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-14a1 1 0 0 1 1-1z"/><path d="M15 4.5V9h4.5M8 12h8M8 16h6"/></symbol>
    <symbol id="i-chart" viewBox="0 0 24 24"><path d="M4 19V5M4 19h16M8 17V11M12 17V8M16 17v-4"/></symbol>
</svg>

<?php if ($user): ?>
<header class="topbar">
    <a class="logo" href="<?= e(url('/')) ?>"><span class="logo-mark">B</span><?= e(config('app_name')) ?></a>
    <?php if ($isFeed): ?>
        <button class="icon-btn" type="button" id="open-filters" aria-label="Filter feed">
            <svg class="icon"><use href="#i-filter"/></svg><span class="dot" hidden></span>
        </button>
    <?php endif; ?>
    <a class="icon-btn" href="<?= e(url('saved')) ?>" aria-label="Saved articles"><svg class="icon"><use href="#i-bookmark"/></svg></a>
    <button class="icon-btn" type="button" id="open-menu" aria-label="Menu"><svg class="icon"><use href="#i-menu"/></svg></button>
</header>
<?php endif; ?>

<?php if ($flashMessage && !$isFeed): ?>
    <div class="page" style="padding-bottom:0"><div class="alert"><?= e($flashMessage) ?></div></div>
<?php endif; ?>

<?= $content ?>

<?php if ($user): ?>
<dialog class="sheet" id="menu">
    <div class="sheet-grip"></div>
    <div class="menu-user">
        <div class="avatar"><?= e(mb_strtoupper(mb_substr($user['first_name'], 0, 1))) ?></div>
        <div>
            <strong><?= e($user['first_name'] . ' ' . $user['last_name']) ?></strong>
            <div class="muted" style="font-size:.85rem"><?= e($user['email']) ?></div>
        </div>
    </div>
    <h3>Appearance</h3>
    <div class="segmented" role="group" aria-label="Theme">
        <?php foreach (['system' => 'Auto', 'light' => 'Light', 'dark' => 'Dark'] as $key => $label): ?>
            <button type="button" data-theme-set="<?= $key ?>" aria-pressed="<?= $theme === $key ? 'true' : 'false' ?>"><?= $label ?></button>
        <?php endforeach; ?>
    </div>
    <nav class="menu-links">
        <a href="<?= e(url('/')) ?>"><svg class="icon"><use href="#i-home"/></svg>Feed</a>
        <a href="<?= e(url('saved')) ?>"><svg class="icon"><use href="#i-bookmark"/></svg>Saved articles</a>
        <a href="<?= e(url('seen')) ?>"><svg class="icon"><use href="#i-seen"/></svg>Seen articles</a>
        <?php if ($user['is_admin']): ?>
            <a href="<?= e(url('admin/insights')) ?>"><svg class="icon"><use href="#i-chart"/></svg>Insights</a>
            <a href="<?= e(url('admin/sources')) ?>"><svg class="icon"><use href="#i-rss"/></svg>Sources</a>
        <?php endif; ?>
        <form method="post" action="<?= e(url('logout')) ?>">
            <?= csrf_field() ?>
            <button type="submit"><svg class="icon"><use href="#i-logout"/></svg>Log out</button>
        </form>
    </nav>
</dialog>

<dialog class="sheet" id="note-dialog">
    <div class="sheet-grip"></div>
    <h2 id="note-dialog-title">Add a note</h2>
    <p class="note-dialog-article muted" id="note-dialog-article" hidden></p>
    <form id="note-form">
        <div class="field">
            <label for="note-text" class="sr-only">Note</label>
            <textarea class="input" id="note-text" name="note" rows="5" maxlength="2000" placeholder="Why did you save this? Optional."></textarea>
        </div>
        <div class="sheet-actions">
            <button class="btn" type="button" id="note-skip">Not now</button>
            <button class="btn btn-primary" type="submit" id="note-save">Save note</button>
        </div>
    </form>
</dialog>

<dialog class="sheet" id="paywall-dialog">
    <div class="sheet-grip"></div>
    <h2>Possible paywall</h2>
    <p class="note-dialog-article" id="paywall-dialog-article"></p>
    <p class="hint" id="paywall-dialog-reason"></p>
    <p class="hint">Briefly only peeks at the page — it does not try to get around the wall. If this is a false alarm, mark the source as safe so we stop checking it.</p>
    <div class="sheet-actions paywall-actions">
        <button class="btn btn-primary" type="button" id="paywall-proceed">Open anyway</button>
        <button class="btn" type="button" id="paywall-safe">Not a paywall — mark source safe</button>
        <button class="btn" type="button" id="paywall-remove">Remove article</button>
        <button class="btn btn-danger" type="button" id="paywall-disable" hidden>Remove &amp; disable source</button>
    </div>
</dialog>
<?php endif; ?>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script src="<?= e(asset('app.js')) ?>" defer></script>
<?php foreach ($scripts ?? [] as $script): ?>
    <script src="<?= e(asset($script)) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
