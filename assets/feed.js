/*
 * The feed: one full-height card at a time with a small rolling window —
 * about 15 cards behind the current one and up to 15 ahead. When the queue
 * ahead runs low it asks the server for the next batch; old cards fall out of
 * the DOM (not the database). A card counts as "seen" after ~1s on screen.
 */
(() => {
    const { api, toast, timeAgo, hue, placeholder, setSaved, cfg } = window.Briefly;

    const feedEl = document.getElementById('feed');
    const template = document.getElementById('card-template');
    const AHEAD = Number(feedEl.dataset.batch) || 15;
    const BEHIND = 15;
    const REFILL_AT = 5;          // request more when this many (or fewer) cards remain ahead
    const SEEN_AFTER_MS = 900;
    const EXCLUDE_MEMORY = 150;   // recently shown ids the server should not send again

    const categoryLabels = {};
    document.querySelectorAll('#filter-form input[name="category"]').forEach((input) => {
        categoryLabels[input.value] = input.nextElementSibling.textContent;
    });

    const readFilters = () => {
        const p = new URLSearchParams(location.search);
        const category = p.get('category') || '';
        return { category: categoryLabels[category] ? category : '', q: (p.get('q') || '').trim() };
    };

    const state = {
        items: [],          // [{ data, el }]
        current: -1,
        loading: false,
        exhausted: false,
        failed: false,
        includeSeen: false,
        generation: 0,
        filters: readFilters(),
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

        el.querySelectorAll('a[href]').forEach((link) => link.addEventListener('click', () => markSeenNow(a.id)));
        return el;
    };

    const toggleSave = async (a, btn) => {
        const saved = !a.saved;
        a.saved = saved;
        btn.setAttribute('aria-pressed', String(saved));
        btn.setAttribute('aria-label', saved ? 'Remove from saved' : 'Save article');
        try {
            await setSaved(a.id, saved);
            toast(saved ? 'Saved' : 'Removed from saved');
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
        const hasFilters = Boolean(state.filters.category || state.filters.q);
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
        if (action === 'clear') applyFilters({ category: '', q: '' });
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
        if (state.filters.category) params.set('category', state.filters.category);
        if (state.filters.q) params.set('q', state.filters.q);
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

    const renderActiveFilters = () => {
        const { category, q } = state.filters;
        const active = Boolean(category || q);
        document.body.classList.toggle('has-filters', active);
        if (filterDot) filterDot.hidden = !active;
        activeEl.hidden = !active;
        activeEl.innerHTML = '';
        if (!active) return;
        const pill = (text, onClick) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'pill';
            b.textContent = text;
            b.addEventListener('click', onClick);
            activeEl.append(b);
            return b;
        };
        if (category) pill(categoryLabels[category], () => filtersDialog.showModal());
        if (q) pill(`“${q}”`, () => filtersDialog.showModal());
        const clear = pill('', () => applyFilters({ category: '', q: '' }));
        clear.setAttribute('aria-label', 'Clear filters');
        clear.innerHTML = '<svg class="icon"><use href="#i-x"/></svg>';
    };

    const applyFilters = (filters) => {
        state.filters = filters;
        const params = new URLSearchParams();
        if (filters.category) params.set('category', filters.category);
        if (filters.q) params.set('q', filters.q);
        history.replaceState(null, '', `${location.pathname}${params.toString() ? `?${params}` : ''}`);
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

    document.getElementById('open-filters')?.addEventListener('click', () => {
        filterForm.q.value = state.filters.q;
        filterForm.querySelectorAll('input[name="category"]').forEach((r) => { r.checked = r.value === state.filters.category; });
        filtersDialog.showModal();
    });
    filterForm.addEventListener('submit', () => {
        const category = filterForm.querySelector('input[name="category"]:checked')?.value || '';
        applyFilters({ category, q: filterForm.q.value.trim() });
    });
    document.getElementById('filter-clear').addEventListener('click', () => {
        filtersDialog.close();
        applyFilters({ category: '', q: '' });
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
        else if ((e.key === 'o' || e.key === 'Enter') && item) item.el.querySelector('.btn-read').click();
    });

    renderActiveFilters();
    renderStatus();
    load();
    feedEl.focus({ preventScroll: true });
})();
