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
            btn.closest('.saved-item')?.classList.toggle('is-unsaved', !saved);
            try {
                await setSaved(Number(btn.dataset.saveToggle), saved);
                toast(saved ? 'Saved' : 'Removed from saved');
            } catch (e) {
                btn.setAttribute('aria-pressed', String(!saved));
                toast(e.message);
            }
        }));
    });

    window.Briefly = { api, toast, timeAgo, hue, placeholder, setSaved, cfg };
})();
