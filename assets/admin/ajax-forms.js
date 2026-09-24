/*
 * POST forms that update the page in place instead of reloading it.
 *
 *   <div data-ajax-forms>        — every POST form inside is sent with fetch (the controller answers as usual:
 *                                  a redirect back to the page with flash messages)
 *   <… id="…" data-ajax-region>  — replaced with the element of the same id from the page the redirect led to
 *   form[data-live-filter]       — lists filtered live (live-filter.js) are reloaded with their current query
 *
 * Flash messages of the answer become toasts. A redirect to another page (e.g. after deleting), an error
 * or an expired session falls back to a normal page load. Confirmations (confirm.js) run first: their
 * requestSubmit() comes back here once confirmed.
 */
function showToasts(doc) {
    const container = document.querySelector('[aria-live="polite"]');
    doc.querySelectorAll('[data-toast]').forEach((toast) => {
        const node = document.importNode(toast, true);
        container?.append(node);
        if (node.dataset.toast === 'auto') {
            setTimeout(() => {
                node.classList.add('opacity-0', 'translate-y-2');
                setTimeout(() => node.remove(), 300);
            }, 5000);
        }
    });
}

document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (event.defaultPrevented || form.method.toLowerCase() !== 'post' || !form.closest('[data-ajax-forms]')) {
        return;
    }
    event.preventDefault();

    const submitter = event.submitter;
    const body = new FormData(form, submitter ?? undefined); // before disabling: a disabled button is left out
    submitter?.setAttribute('disabled', '');
    document.body.setAttribute('aria-busy', 'true');
    let response;
    try {
        response = await fetch(form.action, { method: 'POST', body, headers: { 'X-Ajax-Form': '1' } });
    } catch {
        form.submit(); // the request never got through: send it the ordinary way

        return;
    }

    try {
        const url = new URL(response.url);
        if (!response.ok || url.pathname !== location.pathname) {
            location.href = response.url;

            return;
        }

        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        document.querySelectorAll('[data-ajax-region][id]').forEach((region) => {
            const fresh = doc.getElementById(region.id);
            if (fresh) {
                region.replaceWith(document.importNode(fresh, true));
            }
        });
        showToasts(doc);
        // e.g. the structure picker: "✓" on the sides just added, with the search the manager typed
        document.querySelectorAll('form[data-live-filter]').forEach((filter) => filter.requestSubmit());
    } catch {
        location.reload(); // the form was sent; show its result the ordinary way
    } finally {
        submitter?.removeAttribute('disabled');
        document.body.removeAttribute('aria-busy');
    }
});
