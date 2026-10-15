<?php $scripts = ['feed.js']; ?>
<main id="feed" class="feed" tabindex="-1" data-batch="<?= (int) $batchSize ?>" aria-label="News feed"></main>
<div class="active-filters" id="active-filters" hidden></div>

<dialog class="sheet" id="filters">
    <div class="sheet-grip"></div>
    <form id="filter-form" method="dialog">
        <h2>Tune your feed</h2>

        <div class="field">
            <label for="filter-q">Keywords</label>
            <input class="input" id="filter-q" name="q" type="search" placeholder="e.g. Spain, AI, housing" autocomplete="off" enterkeyhint="search">
            <p class="hint">Separate with commas. Matches words in titles, descriptions and tags.</p>
        </div>

        <div class="filter-section" data-filter="category">
            <div class="filter-section-head">
                <h3>Category</h3>
                <div class="segmented segmented-mode" role="group" aria-label="Category mode">
                    <button type="button" data-mode="include" aria-pressed="true">Include</button>
                    <button type="button" data-mode="exclude" aria-pressed="false">Exclude</button>
                </div>
            </div>
            <div class="choice-grid">
                <?php foreach ($categories as $key => $label): ?>
                    <label class="choice"><input type="checkbox" name="category" value="<?= e($key) ?>"><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="filter-section" data-filter="geo">
            <div class="filter-section-head">
                <h3>Location</h3>
                <div class="segmented segmented-mode" role="group" aria-label="Location mode">
                    <button type="button" data-mode="include" aria-pressed="true">Include</button>
                    <button type="button" data-mode="exclude" aria-pressed="false">Exclude</button>
                </div>
            </div>
            <div class="choice-grid">
                <?php foreach ($geos as $key => $label): ?>
                    <label class="choice"><input type="checkbox" name="geo" value="<?= e($key) ?>"><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="filter-section" data-filter="lang">
            <div class="filter-section-head">
                <h3>Language</h3>
                <div class="segmented segmented-mode" role="group" aria-label="Language mode">
                    <button type="button" data-mode="include" aria-pressed="true">Include</button>
                    <button type="button" data-mode="exclude" aria-pressed="false">Exclude</button>
                </div>
            </div>
            <div class="choice-grid">
                <?php foreach ($languages as $key => $label): ?>
                    <label class="choice"><input type="checkbox" name="lang" value="<?= e($key) ?>"><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="filter-section" data-filter="within">
            <h3>Published</h3>
            <div class="choice-grid">
                <label class="choice"><input type="radio" name="within" value="" checked><span>All</span></label>
                <?php foreach ($withinOptions as $key => $label): ?>
                    <label class="choice"><input type="radio" name="within" value="<?= e($key) ?>"><span><?= e($label) ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sheet-actions">
            <button class="btn" type="button" id="filter-clear">Clear</button>
            <button class="btn btn-primary" type="submit" value="apply">Show articles</button>
        </div>
    </form>
</dialog>

<template id="card-template">
    <article class="card">
        <a class="card-media" target="_blank" rel="noopener">
            <div class="media-chips"><span class="chip chip-geo"></span><span class="chip chip-cat"></span></div>
        </a>
        <div class="card-body">
            <div class="card-source"><span class="badge-initial"></span><strong></strong><span class="card-time"></span></div>
            <h2 class="card-title"><a target="_blank" rel="noopener"></a></h2>
            <p class="card-excerpt"></p>
            <div class="card-cluster" hidden><svg class="icon"><use href="#i-layers"/></svg><span></span></div>
        </div>
        <div class="card-actions">
            <button class="btn-save" type="button" aria-pressed="false" aria-label="Save article"><svg class="icon"><use href="#i-bookmark"/></svg></button>
            <a class="btn-read" target="_blank" rel="noopener"><span></span><svg class="icon"><use href="#i-external"/></svg></a>
        </div>
    </article>
</template>
