/*
 * Ctrl/⌘+K command palette (templates/admin/_command_palette.html.twig).
 * Opens with Ctrl/⌘+K, "/" or any [data-command-open]; searches /admin/search as you type;
 * ↑/↓ move the selection, Enter opens it, Esc closes.
 */
const palette = document.getElementById('command-palette');

if (palette) {
    const input = palette.querySelector('[data-command-input]');
    const defaults = palette.querySelector('[data-command-defaults]');
    const found = palette.querySelector('[data-command-found]');
    const empty = palette.querySelector('[data-command-empty]');
    const spinner = palette.querySelector('[data-command-spinner]');
    const searchUrl = palette.dataset.searchUrl;
    const TONES = {
        free: 'bg-success-soft text-fg-success-strong',
        booked: 'bg-warning-soft text-fg-warning',
        occupied: 'bg-danger-soft text-fg-danger-strong',
        hold: 'bg-warning-soft text-fg-warning',
        paid: 'bg-success-soft text-fg-success-strong',
        promo: 'bg-violet-50 text-violet-700',
    };

    let timer = null;
    let controller = null;
    let activeIndex = 0;

    const isMac = /Mac|iPhone|iPad/.test(navigator.platform);
    document.querySelectorAll('[data-shortcut-label]').forEach((kbd) => { kbd.textContent = isMac ? '⌘K' : 'Ctrl K'; });

    const items = () => [...palette.querySelectorAll('[data-command-item]')].filter((item) => item.offsetParent !== null);

    function select(index) {
        const list = items();
        if (list.length === 0) {
            return;
        }
        activeIndex = (index + list.length) % list.length;
        list.forEach((item, i) => item.setAttribute('aria-selected', String(i === activeIndex)));
        list[activeIndex].scrollIntoView({ block: 'nearest' });
    }

    function open() {
        palette.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        input.value = '';
        showResults(null);
        input.focus();
    }

    function close() {
        palette.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        controller?.abort();
    }

    function element(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    /** groups === null → quick actions */
    function showResults(groups, term = '') {
        defaults.classList.toggle('hidden', groups !== null);
        empty.classList.toggle('hidden', groups === null || groups.length > 0);
        empty.querySelector('[data-command-term]').textContent = term;
        found.replaceChildren();

        for (const group of groups ?? []) {
            found.append(element('p', 'px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-400', group.title));
            for (const item of group.items) {
                const link = element('a', 'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm aria-selected:bg-zinc-100');
                link.href = item.url;
                link.dataset.commandItem = '';
                link.setAttribute('role', 'option');

                const text = element('span', 'min-w-0 flex-1');
                text.append(element('span', 'block truncate font-medium text-heading', item.title));
                text.append(element('span', 'block truncate text-xs text-body-subtle', item.subtitle));
                link.append(text);

                if (item.badge) {
                    link.append(element('span', `shrink-0 rounded-md px-1.5 py-0.5 text-[11px] font-medium ${TONES[item.badge.tone] ?? 'bg-zinc-100 text-body'}`, item.badge.label));
                }
                found.append(link);
            }
        }
        select(0);
    }

    async function search(term) {
        controller?.abort();
        if (term.length < 2) {
            spinner.classList.add('hidden');
            showResults(null);
            return;
        }

        controller = new AbortController();
        spinner.classList.remove('hidden');
        try {
            const response = await fetch(`${searchUrl}?q=${encodeURIComponent(term)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (response.redirected) {
                location.href = response.url; // session expired: go to the login page
                return;
            }
            const data = await response.json();
            showResults(data.groups, term);
        } catch (error) {
            if (error.name !== 'AbortError') {
                showResults([], term);
            }
        } finally {
            spinner.classList.add('hidden');
        }
    }

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => search(input.value.trim()), 150);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            select(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            items()[activeIndex]?.click();
        }
    });

    palette.addEventListener('mousemove', (event) => {
        const item = event.target.closest('[data-command-item]');
        if (item) {
            select(items().indexOf(item));
        }
    });

    palette.querySelectorAll('[data-command-close]').forEach((node) => node.addEventListener('click', close));
    document.querySelectorAll('[data-command-open]').forEach((node) => node.addEventListener('click', open));

    document.addEventListener('keydown', (event) => {
        const typing = event.target.closest?.('input, textarea, select, [contenteditable]');
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            palette.classList.contains('hidden') ? open() : close();
        } else if (event.key === 'Escape' && !palette.classList.contains('hidden')) {
            close();
        } else if (event.key === '/' && !typing && palette.classList.contains('hidden')) {
            event.preventDefault();
            open();
        }
    });
}
