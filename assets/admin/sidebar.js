/*
 * Collapsible sidebar (desktop): the toggle switches <html data-sidebar="collapsed">, remembered in localStorage
 * (base.html.twig applies it before the first paint, so pages don't jump). While collapsed, the labels of the
 * icons pop up as a tooltip on hover/focus — one fixed element, so the scrolling menu doesn't clip it.
 *
 *   <button data-sidebar-toggle>     collapses / expands
 *   <a data-sidebar-tip="Брони · 4">  tooltip text in the collapsed rail
 */
const STORAGE_KEY = 'redbox.sidebar';
const root = document.documentElement;
const desktop = window.matchMedia('(min-width: 64rem)');

const isCollapsed = () => root.dataset.sidebar === 'collapsed' && desktop.matches;

function syncToggle() {
    document.querySelectorAll('[data-sidebar-toggle]').forEach((button) => {
        const collapsed = root.dataset.sidebar === 'collapsed';
        button.setAttribute('aria-expanded', String(!collapsed));
        button.setAttribute('aria-label', collapsed ? 'Развернуть меню' : 'Свернуть меню');
    });
}

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-sidebar-toggle]')) {
        return;
    }
    const collapse = root.dataset.sidebar !== 'collapsed';
    if (collapse) {
        root.dataset.sidebar = 'collapsed';
    } else {
        delete root.dataset.sidebar;
    }
    try {
        localStorage.setItem(STORAGE_KEY, collapse ? 'collapsed' : 'expanded');
    } catch (error) {
        // private mode: the choice just isn't remembered
    }
    hideTip();
    syncToggle();
    // Maps and other widgets that measure their container
    window.dispatchEvent(new Event('resize'));
});

/* ---------- Tooltips of the collapsed rail ---------- */

let tip = null;

function showTip(target) {
    if (!isCollapsed()) {
        return;
    }
    tip ??= Object.assign(document.createElement('div'), {
        className: 'pointer-events-none fixed z-50 whitespace-nowrap rounded-md bg-zinc-900 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg ring-1 ring-white/10',
        role: 'tooltip',
    });
    tip.textContent = target.dataset.sidebarTip;
    document.body.append(tip);
    const rect = target.getBoundingClientRect();
    tip.style.left = rect.right + 10 + 'px';
    tip.style.top = rect.top + rect.height / 2 - tip.offsetHeight / 2 + 'px';
}

function hideTip() {
    tip?.remove();
}

document.addEventListener('mouseover', (event) => {
    const target = event.target.closest('[data-sidebar-tip]');
    target ? showTip(target) : hideTip();
});
document.addEventListener('focusin', (event) => {
    const target = event.target.closest('[data-sidebar-tip]');
    target ? showTip(target) : hideTip();
});
document.querySelector('#default-sidebar nav')?.addEventListener('scroll', hideTip, { passive: true });

syncToggle();
