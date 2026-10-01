<main class="home">
    <header class="home-bar">
        <a class="logo" href="<?= e(url('/')) ?>"><span class="logo-mark">B</span><?= e(config('app_name')) ?></a>
        <a class="btn btn-small" href="<?= e(url('login')) ?>">Log in</a>
    </header>

    <section class="home-hero">
        <div class="home-copy">
            <h1>News, one swipe at a time</h1>
            <p>Most news sites throw everything at you at once. Here you get a single story, then another when you are ready. Bookmark what you want to keep, and tap through when a piece deserves the full read.</p>
            <div class="home-cta">
                <a class="btn btn-primary" href="<?= e(url('login')) ?>">Log in</a>
                <?php if (!empty($canRegister)): ?>
                    <a class="btn" href="<?= e(url('register')) ?>">Create account</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="home-preview" aria-hidden="true">
            <article class="home-card home-card-back">
                <div class="home-card-media"></div>
                <div class="home-card-line"></div>
                <div class="home-card-line short"></div>
            </article>
            <article class="home-card">
                <div class="home-card-media"></div>
                <div class="home-card-chips"><span></span><span></span></div>
                <div class="home-card-line"></div>
                <div class="home-card-line short"></div>
            </article>
        </div>
    </section>
</main>
