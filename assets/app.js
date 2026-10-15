/* Shared helpers: API calls, theme, menu, toasts, relative times, saved-page toggles. */
(() => {
    const cfg = window.BRIEFLY || { base: '', csrf: '' };

    const api = async (method, path, body) => {
        const res = await fetch(cfg.base + path, {
            method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': cfg.csrf,
                ...(body ? { 'Content-Type': 'application/json' } : {}),
            },
            body: body ? JSON.stringify(body) : undefined,
        });
        if (res.status === 401) {
            location.href = cfg.base + '/login';
            throw new Error('Not logged in');
        }
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
        return data;
    };

    let toastTimer;
    const toast = (message) => {
        const el = document.getElementById('toast');
        if (!el) return;
        el.textContent = message;
        el.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => el.classList.remove('show'), 1800);
    };

    const timeAgo = (iso) => {
        const diff = (Date.now() - new Date(iso).getTime()) / 1000;
        if (!isFinite(diff)) return '';
        if (diff < 60) return 'just now';
        if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
        if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
        if (diff < 7 * 86400) return `${Math.floor(diff / 86400)}d ago`;
        return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    };

    // Stable hue per source name, for placeholders and initials.
    const hue = (text) => {
        let h = 0;
        for (const ch of String(text)) h = (h * 31 + ch.codePointAt(0)) % 360;
        return h;
    };

    const placeholder = (name) => {
        const div = document.createElement('div');
        div.className = 'placeholder';
        div.style.setProperty('--h', hue(name));
        div.textContent = name;
        return div;
    };

    const setSaved = async (id, saved) => api('POST', '/api/save', { id, saved });
    const setNote = async (id, note) => api('POST', '/api/note', { id, note });

    /** Record that the original article was opened (any outbound link). */
    const markOpened = (id) => {
        if (!id) return;
        if (navigator.sendBeacon) {
            const form = new FormData();
            form.append('_csrf', cfg.csrf);
            form.append('ids', String(id));
            navigator.sendBeacon(`${cfg.base}/api/opened`, form);
            return;
        }
        api('POST', '/api/opened', { ids: [id] }).catch(() => {});
    };

    const checkPaywall = (id) => api('POST', '/api/paywall-check', { id });
    const dismissArticle = (id, opts = {}) => api('POST', '/api/dismiss', {
        id,
        paywalled: !!opts.paywalled,
        disable_source: !!opts.disableSource,
    });

    /**
     * Warn when a page looks paywalled.
     * Resolves 'proceed' | 'safe' | 'remove' | 'disable' | 'cancel'
     */
    const openPaywallDialog = ({ title, reason } = {}) => {
        const dialog = document.getElementById('paywall-dialog');
        const articleEl = document.getElementById('paywall-dialog-article');
        const reasonEl = document.getElementById('paywall-dialog-reason');
        const proceed = document.getElementById('paywall-proceed');
        const safe = document.getElementById('paywall-safe');
        const remove = document.getElementById('paywall-remove');
        const disable = document.getElementById('paywall-disable');
        if (!dialog) return Promise.resolve('proceed');

        articleEl.textContent = title || '';
        reasonEl.textContent = reason || 'This page looks like it may require a subscription.';
        if (disable) disable.hidden = !cfg.isAdmin;

        return new Promise((resolve) => {
            let settled = false;
            const finish = (action) => {
                if (settled) return;
                settled = true;
                proceed.removeEventListener('click', onProceed);
                safe?.removeEventListener('click', onSafe);
                remove.removeEventListener('click', onRemove);
                disable?.removeEventListener('click', onDisable);
                dialog.removeEventListener('close', onClose);
                if (dialog.open) dialog.close();
                resolve(action);
            };
            const onProceed = () => finish('proceed');
            const onSafe = () => finish('safe');
            const onRemove = () => finish('remove');
            const onDisable = () => finish('disable');
            const onClose = () => finish('cancel');
            proceed.addEventListener('click', onProceed);
            safe?.addEventListener('click', onSafe);
            remove.addEventListener('click', onRemove);
            disable?.addEventListener('click', onDisable);
            dialog.addEventListener('close', onClose);
            dialog.showModal();
        });
    };

    /** Check paywall (unless already known free), then open or warn. */
    const openArticleGuarded = async (article, { onRemoved } = {}) => {
        const id = article.id;
        const url = article.url;
        let status = article.paywall_status || 'unknown';
        let reason = article.paywall_reason || '';

        // Always revalidate unless already free (server caches, respects paywall_safe).
        if (status !== 'free') {
            try {
                toast('Checking for a paywall…');
                const result = await checkPaywall(id);
                status = result.status || status;
                reason = result.reason || reason;
                article.paywall_status = status;
                article.paywall_reason = reason;
                if (result.source_id) article.source_id = result.source_id;
            } catch (e) {
                // Network/bot blocks → let the user open it themselves.
                status = 'unknown';
            }
        }

        if (status === 'paywalled' || status === 'likely') {
            const action = await openPaywallDialog({ title: article.title || article.source, reason });
            if (action === 'proceed') {
                markOpened(id);
                window.open(url, '_blank', 'noopener');
                return 'proceed';
            }
            if (action === 'safe') {
                try {
                    await api('POST', '/api/paywall-safe', { id });
                    article.paywall_status = 'free';
                    toast('Source marked safe — we won’t warn again for it');
                    markOpened(id);
                    window.open(url, '_blank', 'noopener');
                } catch (e) {
                    toast(e.message);
                }
                return 'safe';
            }
            if (action === 'remove' || action === 'disable') {
                try {
                    await dismissArticle(id, { paywalled: true, disableSource: action === 'disable' });
                    toast(action === 'disable' ? 'Removed and source disabled' : 'Removed from your feed');
                    onRemoved?.(article);
                } catch (e) {
                    toast(e.message);
                }
                return action;
            }
            return 'cancel';
        }

        markOpened(id);
        window.open(url, '_blank', 'noopener');
        return 'proceed';
    };

    /** Open the shared note sheet. Resolves { ok:true, note } or { ok:false }. */
    const openNoteDialog = (id, opts = {}) => {
        const dialog = document.getElementById('note-dialog');
        const form = document.getElementById('note-form');
        const text = document.getElementById('note-text');
        const titleEl = document.getElementById('note-dialog-title');
        const articleEl = document.getElementById('note-dialog-article');
        const skip = document.getElementById('note-skip');
        if (!dialog || !form || !text) return Promise.resolve({ ok: false });

        const existing = (opts.note || '').trim();
        titleEl.textContent = opts.prompt ? 'Add a note?' : (existing ? 'Edit note' : 'Add a note');
        skip.textContent = opts.prompt ? 'Not now' : 'Cancel';
        if (opts.title) {
            articleEl.hidden = false;
            articleEl.textContent = opts.title;
        } else {
            articleEl.hidden = true;
            articleEl.textContent = '';
        }
        text.value = existing;
        dialog.dataset.articleId = String(id);

        return new Promise((resolve) => {
            let settled = false;
            const cleanup = () => {
                form.removeEventListener('submit', onSubmit);
                skip.removeEventListener('click', onSkip);
                dialog.removeEventListener('close', onClose);
            };
            const finish = (result) => {
                if (settled) return;
                settled = true;
                cleanup();
                if (dialog.open) dialog.close();
                resolve(result);
            };
            const onSkip = () => finish({ ok: false });
            const onClose = () => finish({ ok: false });
            const onSubmit = async (e) => {
                e.preventDefault();
                const note = text.value.trim();
                try {
                    const data = await setNote(id, note);
                    const savedNote = data.note ?? null;
                    opts.onSaved?.(savedNote);
                    toast(note ? 'Note saved' : 'Note cleared');
                    finish({ ok: true, note: savedNote });
                } catch (err) {
                    toast(err.message);
                }
            };
            form.addEventListener('submit', onSubmit);
            skip.addEventListener('click', onSkip);
            dialog.addEventListener('close', onClose);
            dialog.showModal();
            requestAnimationFrame(() => text.focus());
        });
    };

    // ---- theme
    const applyTheme = (theme) => {
        if (theme === 'system') delete document.documentElement.dataset.theme;
        else document.documentElement.dataset.theme = theme;
        document.querySelectorAll('[data-theme-set]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.themeSet === theme)));
        const dark = theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#0d0d12' : '#f5f4f0');
    };

    document.addEventListener('DOMContentLoaded', () => {
        applyTheme(cfg.theme || 'system');

        document.querySelectorAll('[data-theme-set]').forEach((btn) => btn.addEventListener('click', () => {
            const theme = btn.dataset.themeSet;
            applyTheme(theme);
            api('POST', '/api/theme', { theme }).catch((e) => toast(e.message));
        }));

        const menu = document.getElementById('menu');
        document.getElementById('open-menu')?.addEventListener('click', () => menu?.showModal());
        document.querySelectorAll('dialog.sheet').forEach((d) => d.addEventListener('click', (e) => {
            if (e.target === d) d.close(); // click on backdrop
        }));

        document.querySelectorAll('time[data-ago]').forEach((t) => { t.textContent = timeAgo(t.getAttribute('datetime')); });

        document.querySelectorAll('.placeholder[data-hue]').forEach((p) => p.style.setProperty('--h', hue(p.dataset.hue)));
        document.querySelectorAll('img[data-fallback]').forEach((img) => img.addEventListener('error', () => {
            img.replaceWith(placeholder(img.dataset.fallback.slice(0, 2)));
        }, { once: true }));

        // Saved page: toggle without leaving the page
        document.querySelectorAll('[data-save-toggle]').forEach((btn) => btn.addEventListener('click', async () => {
            const saved = btn.getAttribute('aria-pressed') !== 'true';
            btn.setAttribute('aria-pressed', String(saved));
            const item = btn.closest('.saved-item');
            item?.classList.toggle('is-unsaved', !saved);
            try {
                await setSaved(Number(btn.dataset.saveToggle), saved);
                toast(saved ? 'Saved' : 'Removed from saved');
                if (!saved) {
                    item?.querySelector('.saved-note')?.remove();
                    const noteBtn = item?.querySelector('[data-note-edit]');
                    if (noteBtn) {
                        noteBtn.dataset.note = '';
                        noteBtn.setAttribute('aria-label', 'Add note');
                    }
                }
            } catch (e) {
                btn.setAttribute('aria-pressed', String(!saved));
                item?.classList.toggle('is-unsaved', saved);
                toast(e.message);
            }
        }));

        // Saved / Seen / Insights: guard outbound article links the same way as the feed.
        document.querySelectorAll('.saved-item a[href], .seen-item[href], a[data-track-open]').forEach((link) => {
            const id = Number(link.dataset.trackOpen || link.closest('[data-id]')?.dataset.id || 0);
            if (!id || !link.href) return;
            link.addEventListener('click', async (e) => {
                e.preventDefault();
                const item = link.closest('.saved-item, .insight-article, .seen-item');
                await openArticleGuarded({
                    id,
                    url: link.href,
                    title: link.textContent.trim() || item?.querySelector('h2, h3')?.textContent?.trim() || '',
                    paywall_status: 'unknown',
                }, {
                    onRemoved: () => item?.remove(),
                });
            });
        });

        document.querySelectorAll('[data-note-edit]').forEach((btn) => btn.addEventListener('click', async () => {
            const id = Number(btn.dataset.noteEdit);
            const item = btn.closest('.saved-item');
            const result = await openNoteDialog(id, {
                title: btn.dataset.title || '',
                note: btn.dataset.note || '',
            });
            if (!result.ok) return;
            const note = result.note || '';
            btn.dataset.note = note;
            btn.setAttribute('aria-label', note ? 'Edit note' : 'Add note');
            let preview = item?.querySelector('.saved-note');
            if (note) {
                if (!preview) {
                    preview = document.createElement('p');
                    preview.className = 'saved-note';
                    item?.querySelector('.saved-body')?.append(preview);
                }
                preview.textContent = note;
            } else if (preview) {
                preview.remove();
            }
        }));
    });

    window.Briefly = {
        api, toast, timeAgo, hue, placeholder, setSaved, setNote, openNoteDialog,
        markOpened, checkPaywall, dismissArticle, openPaywallDialog, openArticleGuarded, cfg,
    };
})();
