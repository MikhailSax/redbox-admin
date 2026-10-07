/*
 * Badge of the bell in the top bar (admin/_notifications_bell.html.twig): asks for the unread count every minute
 * while the tab is visible, so a request from the website shows up without reloading the page.
 */
const badge = document.querySelector('[data-notifications-badge]');

async function refresh() {
    if (document.hidden) {
        return;
    }
    try {
        const response = await fetch(badge.dataset.url, { headers: { Accept: 'application/json' } });
        if (!response.ok) {
            return;
        }
        const { unread } = await response.json();
        badge.textContent = unread > 99 ? '99+' : String(unread);
        badge.classList.toggle('hidden', unread === 0);
    } catch {
        // offline for a moment: the next tick will try again
    }
}

if (badge) {
    setInterval(refresh, 60000);
    document.addEventListener('visibilitychange', refresh);
}
