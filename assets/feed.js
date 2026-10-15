/*
 * The feed: one full-height card at a time with a small rolling window —
 * about 15 cards behind the current one and up to 15 ahead. When the queue
 * ahead runs low it asks the server for the next batch; old cards fall out of
 * the DOM (not the database). A card counts as "seen" after ~1s on screen.
 */
(() => {
    const { api, toast, timeAgo, hue, placeholder, setSaved, openNoteDialog, openArticleGuarded, cfg } = window.Briefly;

    const feedEl = document.getElementById('feed');
    const template = document.getElementById('card-template');
    const AHEAD = Number(feedEl.dataset.batch) || 15;
    const BEHIND = 15;
    const REFILL_AT = 5;          // request more when this many (or fewer) cards remain ahead
    const SEEN_AFTER_MS = 900;
    const EXCLUDE_MEMORY = 150;   // recently shown ids the server should not send again

    const WITHIN_LABELS = { '1h': 'Last 1h', '3h': 'Last 3h', '12h': 'Last 12h', '24h': 'Last 24h', '3d': 'Last 3 days', '7d': 'Last 7 days' };
    const emptyFilters = () => ({
        q: '',
        categories: [],
        categoryMode: 'include',
        geos: [],
        geoMode: 'include',
        langs: [],
        langMode: 'include',
        within: '',
    });

    const labelsFor = (name) => {
        const map = {};
        document.querySelectorAll(`#filter-form input[name="${name}"]`).forEach((input) => {
            if (input.value) map[input.value] = input.nextElementSibling.textContent;
        });
        return map;
    };
    const categoryLabels = labelsFor('category');
    const geoLabels = labelsFor('geo');
    const langLabels = labelsFor('lang');

    const parseList = (raw, allowed) => (raw || '').split(',').map((s) => s.trim()).filter((s) => s && allowed[s]);
    const modeOf = (raw) => (raw === 'exclude' ? 'exclude' : 'include');

    const filtersFromParams = (p) => {
        const within = p.get('within') || '';
        return {
            q: (p.get('q') || '').trim(),
            categories: parseList(p.get('category'), categoryLabels),
            categoryMode: modeOf(p.get('category_mode')),
            geos: parseList(p.get('geo'), geoLabels),
            geoMode: modeOf(p.get('geo_mode')),
            langs: parseList(p.get('lang'), langLabels),
            langMode: modeOf(p.get('lang_mode')),
            within: WITHIN_LABELS[within] ? within : '',
        };
    };

    const filtersFromStored = (raw) => {
        const p = new URLSearchParams();
        const obj = raw && typeof raw === 'object' ? raw : {};
        Object.entries(obj).forEach(([k, v]) => {
            if (v !== '' && v != null) p.set(k, String(v));
        });
        return filtersFromParams(p);
    };

    const filtersActive = (f) => Boolean(
        f.q || f.categories.length || f.geos.length || f.langs.length || f.within
    );

    const filtersToParams = (f) => {
        const params = new URLSearchParams();
        if (f.q) params.set('q', f.q);
        if (f.categories.length) {
            params.set('category', f.categories.join(','));
            if (f.categoryMode === 'exclude') params.set('category_mode', 'exclude');
        }
        if (f.geos.length) {
            params.set('geo', f.geos.join(','));
            if (f.geoMode === 'exclude') params.set('geo_mode', 'exclude');
        }
        if (f.langs.length) {
            params.set('lang', f.langs.join(','));
            if (f.langMode === 'exclude') params.set('lang_mode', 'exclude');
        }
        if (f.within) params.set('within', f.within);
        return params;
    };

    // URL wins when present (shareable links); otherwise restore the user's saved filters.
    const urlFilters = filtersFromParams(new URLSearchParams(location.search));
    const storedFilters = filtersFromStored(cfg.filters);
    const initialFilters = filtersActive(urlFilters) ? urlFilters : storedFilters;
    const urlHadFilters = filtersActive(urlFilters);
    if (filtersActive(initialFilters) && !urlHadFilters) {
        const params = filtersToParams(initialFilters);
        history.replaceState(null, '', `${location.pathname}?${params}`);
    }

    const listPill = (values, labels, mode) => {
        const names = values.map((v) => labels[v] || v);
        const joined = names.length <= 2 ? names.join(' + ') : `${names[0]} +${names.length - 1}`;
        return mode === 'exclude' ? `Not ${joined}` : joined;
    };

    const state = {
        items: [],          // [{ data, el }]
        current: -1,
        loading: false,
        exhausted: false,
        failed: false,
        includeSeen: false,
        generation: 0,
        filters: initialFilters,
        recentIds: [],
        seenPending: new Set(),
        seenTimers: new Map(),
    };

    // ------------------------------------------------------------------ cards

    const renderCard = (a) => {
        const el = template.content.firstElementChild.cloneNode(true);
        el.dataset.id = a.id;
        el.dataset.geo = a.geo;
        if (a.language) el.lang = a.language;
        const h = hue(a.source);

        const media = el.querySelector('.card-media');
        media.href = a.url;
        media.tabIndex = -1;
        media.setAttribute('aria-hidden', 'true');
        if (a.image) {
            const img = new Image();
            img.alt = '';
            img.decoding = 'async';
            img.loading = 'lazy';
            img.referrerPolicy = 'no-referrer';
            img.addEventListener('error', () => img.replaceWith(placeholder(a.source)), { once: true });
            img.src = a.image;
            media.prepend(img);
        } else {
            media.prepend(placeholder(a.source));
        }
        el.querySelector('.chip-geo').textContent = a.geo_label;
        const cat = el.querySelector('.chip-cat');
        if (a.category === 'general') cat.remove();
        else cat.textContent = a.category_label;

        const badge = el.querySelector('.badge-initial');
        badge.textContent = a.source.replace(/^(the|el|la|le)\s+/i, '').charAt(0).toUpperCase();
        badge.style.setProperty('--h', h);
        el.querySelector('.card-source strong').textContent = a.source;
        const author = a.author && a.author.length <= 32 ? ` · ${a.author}` : '';
        el.querySelector('.card-time').textContent = `· ${timeAgo(a.published_at)}${author}`;

        const title = el.querySelector('.card-title a');
        title.href = a.url;
        title.textContent = a.title;

        const excerpt = el.querySelector('.card-excerpt');
        if (a.excerpt) excerpt.textContent = a.excerpt;
        else excerpt.remove();

        if (a.cluster_size > 1) {
            const cluster = el.querySelector('.card-cluster');
            cluster.hidden = false;
            cluster.querySelector('span').textContent = `${a.cluster_size} sources covering this story`;
        }

        const save = el.querySelector('.btn-save');
        save.setAttribute('aria-pressed', String(a.saved));
        save.addEventListener('click', () => toggleSave(a, save));

        const read = el.querySelector('.btn-read');
        read.href = a.url;
        read.querySelector('span').textContent = `Read on ${a.source}`;

        el.querySelectorAll('a[href]').forEach((link) => link.addEventListener('click', (e) => {
            e.preventDefault();
            markSeenNow(a.id);
            openArticle(a);
        }));
        return el;
    };

    const removeCard = (a) => {
        const idx = state.items.findIndex((i) => i.data.id === a.id);
        if (idx === -1) return;
        const [item] = state.items.splice(idx, 1);
        observer.unobserve(item.el);
        item.el.remove();
        if (state.current >= state.items.length) state.current = state.items.length - 1;
        if (state.current >= 0) state.items[state.current]?.el.scrollIntoView({ block: 'start' });
        else statusEl.scrollIntoView({ block: 'start' });
        if (state.items.length - 1 - state.current <= REFILL_AT) load();
    };

    const openArticle = async (a) => {
        if (a._opening) return;
        a._opening = true;
        try {
            await openArticleGuarded(a, { onRemoved: removeCard });
        } finally {
            a._opening = false;
        }
    };

    const toggleSave = async (a, btn) => {
        const saved = !a.saved;
        a.saved = saved;
        btn.setAttribute('aria-pressed', String(saved));
        btn.setAttribute('aria-label', saved ? 'Remove from saved' : 'Save article');
        try {
            await setSaved(a.id, saved);
            if (saved) {
                toast('Saved');
                const result = await openNoteDialog(a.id, {
                    title: a.title,
                    note: a.note || '',
                    prompt: true,
                });
                if (result.ok) a.note = result.note;
            } else {
                a.note = null;
                toast('Removed from saved');
            }
        } catch (e) {
            a.saved = !saved;
            btn.setAttribute('aria-pressed', String(!saved));
            toast(e.message);
        }
    };

    // ------------------------------------------------------------ status card

    const statusEl = document.createElement('section');
    statusEl.className = 'card card-status';
    feedEl.append(statusEl);

    const renderStatus = () => {
        const hasFilters = filtersActive(state.filters);
        const buttons = [];
        let html;
        if (state.loading || (!state.exhausted && !state.failed)) {
            html = `<div class="spinner"></div><p>${state.items.length ? 'Finding more stories…' : 'Loading your feed…'}</p>`;
        } else if (state.failed) {
            html = '<h2>Couldn’t load the feed</h2><p>Check your connection and try again.</p>';
            buttons.push(['Try again', 'retry', true]);
        } else {
            html = state.items.length
                ? `<h2>You’re all caught up</h2><p>${hasFilters ? 'No more articles match these filters right now.' : 'New articles arrive through the day. Check back soon.'}</p>`
                : `<h2>Nothing here yet</h2><p>${hasFilters ? 'No recent articles match these filters.' : 'Articles are still being collected. Try again in a minute.'}</p>`;
            buttons.push(['Check for new articles', 'retry', true]);
            if (hasFilters) buttons.push(['Clear filters', 'clear']);
            if (!state.includeSeen) buttons.push(['Show ones I’ve seen', 'seen']);
        }
        statusEl.innerHTML = html + (buttons.length
            ? `<div style="display:grid;gap:10px;width:min(320px,100%);margin-top:10px">${buttons.map(([label, action, primary]) => `<button class="btn${primary ? ' btn-primary' : ''}" type="button" data-action="${action}">${label}</button>`).join('')}</div>`
            : '');
    };

    statusEl.addEventListener('click', (e) => {
        const action = e.target.closest('[data-action]')?.dataset.action;
        if (action === 'retry') { state.exhausted = false; state.failed = false; load(); }
        if (action === 'clear') applyFilters(emptyFilters());
        if (action === 'seen') { state.includeSeen = true; state.exhausted = false; load(); }
    });

    // ----------------------------------------------------------------- loading

    const load = async () => {
        if (state.loading || state.exhausted) return;
        state.loading = true;
        state.failed = false;
        renderStatus();
        const generation = state.generation;
        await flushSeen();

        const upcoming = Math.max(0, state.items.length - 1 - state.current);
        const windowIds = state.items.map((i) => i.data.id);
        const params = new URLSearchParams({
            limit: String(Math.max(1, AHEAD - upcoming)),
            exclude: [...new Set([...state.recentIds, ...windowIds])].join(','),
            context: windowIds.slice(-10).join(','),
        });
        const filterParams = filtersToParams(state.filters);
        filterParams.forEach((value, key) => params.set(key, value));
        if (state.includeSeen) params.set('include_seen', '1');

        try {
            const data = await api('GET', `/api/feed?${params}`);
            if (generation !== state.generation) return;
            append(data.items || []);
            if (!data.items || data.items.length === 0) state.exhausted = true;
        } catch (e) {
            if (generation !== state.generation) return;
            state.failed = true;
        } finally {
            if (generation === state.generation) {
                state.loading = false;
                renderStatus();
            }
        }
    };

    const append = (articles) => {
        if (!articles.length) return;
        const onStatus = state.items.length === 0 || state.current >= state.items.length;
        let first = null;
        for (const a of articles) {
            const el = renderCard(a);
            first ||= el;
            feedEl.insertBefore(el, statusEl);
            state.items.push({ data: a, el });
            state.recentIds.push(a.id);
            observer.observe(el);
        }
        if (state.recentIds.length > EXCLUDE_MEMORY) state.recentIds.splice(0, state.recentIds.length - EXCLUDE_MEMORY);
        // Mandatory scroll-snap would otherwise keep the status card in view.
        if (onStatus) {
            feedEl.style.scrollSnapType = 'none';
            feedEl.scrollTop += first.getBoundingClientRect().top - feedEl.getBoundingClientRect().top;
            requestAnimationFrame(() => { feedEl.style.scrollSnapType = ''; });
        }
    };

    // -------------------------------------------------------- current / seen

    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            const index = state.items.findIndex((i) => i.el === entry.target);
            if (index === -1) continue;
            const id = state.items[index].data.id;
            if (entry.isIntersecting) {
                setCurrent(index);
                if (!state.seenTimers.has(id)) {
                    state.seenTimers.set(id, setTimeout(() => markSeenNow(id), SEEN_AFTER_MS));
                }
            } else if (state.seenTimers.has(id)) {
                clearTimeout(state.seenTimers.get(id));
                state.seenTimers.delete(id);
            }
        }
    }, { root: feedEl, threshold: 0.6 });

    const statusObserver = new IntersectionObserver(([entry]) => {
        if (entry.isIntersecting) {
            state.current = state.items.length;
            if (!state.loading && !state.exhausted && !state.failed) load();
        }
    }, { root: feedEl, threshold: 0.4 });
    statusObserver.observe(statusEl);

    const setCurrent = (index) => {
        state.current = index;
        const upcoming = state.items.length - 1 - index;
        if (upcoming <= REFILL_AT) load();
        scheduleTrim();
    };

    const seenSent = new Set();
    const markSeenNow = (id) => {
        state.seenTimers.delete(id);
        if (seenSent.has(id)) return;
        seenSent.add(id);
        state.seenPending.add(id);
        if (state.seenPending.size >= 5) flushSeen();
    };

    const flushSeen = (useBeacon = false) => {
        const ids = [...state.seenPending];
        if (!ids.length) return Promise.resolve();
        state.seenPending.clear();
        if (useBeacon && navigator.sendBeacon) {
            const form = new FormData();
            form.append('_csrf', cfg.csrf);
            form.append('ids', ids.join(','));
            navigator.sendBeacon(`${cfg.base}/api/seen`, form);
            return Promise.resolve();
        }
        return api('POST', '/api/seen', { ids }).catch(() => ids.forEach((id) => state.seenPending.add(id)));
    };

    setInterval(() => flushSeen(), 5000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flushSeen(true); });
    window.addEventListener('pagehide', () => flushSeen(true));

    // --------------------------------------------- drop cards far behind us

    let trimTimer;
    const scheduleTrim = () => {
        clearTimeout(trimTimer);
        trimTimer = setTimeout(trimBehind, 350);
    };
    feedEl.addEventListener('scroll', () => { if (trimTimer) scheduleTrim(); }, { passive: true });

    const trimBehind = () => {
        trimTimer = null;
        const excess = state.current - BEHIND;
        if (excess <= 0) return;
        const removed = state.items.splice(0, excess);
        let height = 0;
        for (const item of removed) {
            height += item.el.offsetHeight;
            observer.unobserve(item.el);
            item.el.remove();
        }
        state.current -= excess;
        feedEl.style.scrollSnapType = 'none';
        feedEl.scrollTop -= height;
        requestAnimationFrame(() => { feedEl.style.scrollSnapType = ''; });
    };

    // -------------------------------------------------------------- filters

    const filtersDialog = document.getElementById('filters');
    const filterForm = document.getElementById('filter-form');
    const filterDot = document.querySelector('#open-filters .dot');
    const activeEl = document.getElementById('active-filters');

    const setSectionMode = (section, mode) => {
        section.dataset.mode = mode;
        section.querySelectorAll('.segmented-mode button').forEach((btn) => {
            btn.setAttribute('aria-pressed', String(btn.dataset.mode === mode));
        });
    };

    const checkedValues = (name) => [...filterForm.querySelectorAll(`input[name="${name}"]:checked`)].map((el) => el.value).filter(Boolean);

    const hydrateForm = (f) => {
        filterForm.q.value = f.q;
        filterForm.querySelectorAll('input[name="category"]').forEach((el) => { el.checked = f.categories.includes(el.value); });
        filterForm.querySelectorAll('input[name="geo"]').forEach((el) => { el.checked = f.geos.includes(el.value); });
        filterForm.querySelectorAll('input[name="lang"]').forEach((el) => { el.checked = f.langs.includes(el.value); });
        filterForm.querySelectorAll('input[name="within"]').forEach((el) => { el.checked = el.value === f.within; });
        setSectionMode(filterForm.querySelector('[data-filter="category"]'), f.categoryMode);
        setSectionMode(filterForm.querySelector('[data-filter="geo"]'), f.geoMode);
        setSectionMode(filterForm.querySelector('[data-filter="lang"]'), f.langMode);
    };

    const readForm = () => {
        const sectionMode = (name) => filterForm.querySelector(`[data-filter="${name}"]`)?.dataset.mode === 'exclude' ? 'exclude' : 'include';
        const within = filterForm.querySelector('input[name="within"]:checked')?.value || '';
        return {
            q: filterForm.q.value.trim(),
            categories: checkedValues('category'),
            categoryMode: sectionMode('category'),
            geos: checkedValues('geo'),
            geoMode: sectionMode('geo'),
            langs: checkedValues('lang'),
            langMode: sectionMode('lang'),
            within: WITHIN_LABELS[within] ? within : '',
        };
    };

    const renderActiveFilters = () => {
        const f = state.filters;
        const active = filtersActive(f);
        document.body.classList.toggle('has-filters', active);
        if (filterDot) filterDot.hidden = !active;
        activeEl.hidden = !active;
        activeEl.innerHTML = '';
        if (!active) return;
        const open = () => filtersDialog.showModal();
        const pill = (text, onClick) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'pill';
            b.textContent = text;
            b.addEventListener('click', onClick);
            activeEl.append(b);
            return b;
        };
        if (f.q) pill(`“${f.q}”`, open);
        if (f.categories.length) pill(listPill(f.categories, categoryLabels, f.categoryMode), open);
        if (f.geos.length) pill(listPill(f.geos, geoLabels, f.geoMode), open);
        if (f.langs.length) pill(listPill(f.langs, langLabels, f.langMode), open);
        if (f.within) pill(WITHIN_LABELS[f.within], open);
        const clear = pill('', () => applyFilters(emptyFilters()));
        clear.setAttribute('aria-label', 'Clear filters');
        clear.innerHTML = '<svg class="icon"><use href="#i-x"/></svg>';
    };

    const persistFilters = (filters) => {
        const payload = Object.fromEntries(filtersToParams(filters).entries());
        cfg.filters = payload;
        api('POST', '/api/filters', payload).catch(() => {});
    };

    // Keep DB in sync when the user arrived via a filtered URL (so logo → / restores them).
    if (urlHadFilters) {
        persistFilters(initialFilters);
    }

    const applyFilters = (filters) => {
        state.filters = filters;
        const params = filtersToParams(filters);
        history.replaceState(null, '', `${location.pathname}${params.toString() ? `?${params}` : ''}`);
        persistFilters(filters);
        reset();
    };

    const reset = () => {
        flushSeen();
        state.generation++;
        state.items.forEach((i) => { observer.unobserve(i.el); i.el.remove(); });
        state.seenTimers.forEach((t) => clearTimeout(t));
        state.seenTimers.clear();
        state.items = [];
        state.current = -1;
        state.loading = false;
        state.exhausted = false;
        state.failed = false;
        state.includeSeen = false;
        feedEl.scrollTop = 0;
        renderActiveFilters();
        load();
    };

    filterForm.querySelectorAll('.segmented-mode').forEach((group) => {
        group.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-mode]');
            if (!btn) return;
            setSectionMode(btn.closest('.filter-section'), btn.dataset.mode);
        });
    });

    document.getElementById('open-filters')?.addEventListener('click', () => {
        hydrateForm(state.filters);
        filtersDialog.showModal();
    });
    filterForm.addEventListener('submit', () => applyFilters(readForm()));
    document.getElementById('filter-clear').addEventListener('click', () => {
        filtersDialog.close();
        applyFilters(emptyFilters());
    });

    // ------------------------------------------------------------- keyboard

    const go = (delta) => {
        const target = delta > 0 ? (state.items[state.current + 1]?.el || statusEl) : state.items[state.current - 1]?.el;
        target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    document.addEventListener('keydown', (e) => {
        if (e.target.closest('input, textarea, select, dialog[open]') || e.metaKey || e.ctrlKey || e.altKey) return;
        const item = state.items[state.current];
        if (['ArrowDown', 'j', 'PageDown', ' '].includes(e.key)) { e.preventDefault(); go(1); }
        else if (['ArrowUp', 'k', 'PageUp'].includes(e.key)) { e.preventDefault(); go(-1); }
        else if (e.key === 's' && item) item.el.querySelector('.btn-save').click();
        else if ((e.key === 'o' || e.key === 'Enter') && item) {
            e.preventDefault();
            markSeenNow(item.data.id);
            openArticle(item.data);
        }
    });

    renderActiveFilters();
    renderStatus();
    load();
    feedEl.focus({ preventScroll: true });
})();
