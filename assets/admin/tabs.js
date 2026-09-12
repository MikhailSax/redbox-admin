/*
 * Client-side tabs over one form: the structure card (templates/admin/product/_card_header.html.twig + _form.html.twig)
 * and the promotion card (templates/admin/promotion/form.html.twig).
 *
 *   <a data-tab="seo" href="#seo">   switches to <div data-tab-panel="seo">
 *   <input data-tab-input>           receives the open tab, so the server can redirect back to it after saving
 *   <div data-tab-hide="usage">      hidden while one of the listed tabs is open (e.g. Save on a read-only tab)
 *
 * The server renders the initial tab (first one with validation errors, otherwise "main");
 * without errors a #hash in the URL (e.g. /edit#seo from the booking page) opens that tab.
 */
const ACTIVE = ['text-fg-brand', 'border-brand'];
const INACTIVE = ['border-transparent', 'text-body', 'hover:text-heading', 'hover:border-default-strong'];

function activate(name, { updateHash = true } = {}) {
    const panel = document.querySelector(`[data-tab-panel="${CSS.escape(name)}"]`);
    if (!panel) {
        return false;
    }

    document.querySelectorAll('[data-tab-panel]').forEach((p) => p.classList.toggle('hidden', p !== panel));
    document.querySelectorAll('[data-tab]').forEach((tab) => {
        const active = tab.dataset.tab === name;
        tab.classList.remove(...(active ? INACTIVE : ACTIVE));
        tab.classList.add(...(active ? ACTIVE : INACTIVE));
        tab.setAttribute('aria-selected', String(active));
    });
    document.querySelectorAll('[data-tab-input]').forEach((input) => { input.value = name; });
    document.querySelectorAll('[data-tab-hide]').forEach((el) => el.classList.toggle('hidden', el.dataset.tabHide.split(' ').includes(name)));

    // Widgets that can't lay out while hidden (maps) re-measure on this
    document.dispatchEvent(new CustomEvent('tab:shown', { detail: { name } }));

    if (updateHash) {
        history.replaceState(null, '', `#${name}`);
    }

    return true;
}

document.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-tab]');
    if (tab && activate(tab.dataset.tab)) {
        event.preventDefault();
    }
});

document.addEventListener('DOMContentLoaded', () => {
    const hash = decodeURIComponent(location.hash.slice(1));
    const hasErrors = document.querySelector('[data-tab-error]') !== null;
    if (hash && !hasErrors) {
        activate(hash, { updateHash: false });
    }
});
