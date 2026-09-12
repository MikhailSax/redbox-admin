/*
 * Live filtering of admin lists without page reloads.
 *
 *   <form data-live-filter="results-id"> — typing (debounced) or changing a select reloads the results
 *   <div id="results-id">                — replaced with the server's "results" block (X-Live-Filter header)
 *   <a data-live-link> inside results    — pagination / chips load in place, and the form follows their query
 *
 * The URL is kept in sync (history.replaceState), so a reload or a shared link shows the same list.
 */
const DEBOUNCE_MS = 250;

document.querySelectorAll('form[data-live-filter]').forEach((form) => {
    const target = document.getElementById(form.dataset.liveFilter);
    let timer = null;
    let controller = null;

    const urlFromForm = () => {
        const params = new URLSearchParams(new FormData(form));
        [...params.keys()].forEach((key) => {
            if (params.get(key) === '') {
                params.delete(key);
            }
        });
        const query = params.toString();

        return form.action.split('?')[0] + (query ? '?' + query : '');
    };

    // Keep the form in line with a URL loaded from a link (e.g. a status chip)
    const syncForm = (url) => {
        const params = new URL(url, location.href).searchParams;
        for (const field of form.elements) {
            if (field.name && field.type !== 'submit') {
                const fallback = field.tagName === 'SELECT' ? (field.options[0]?.value ?? '') : '';
                field.value = params.get(field.name) ?? fallback;
            }
        }
    };

    async function load(url) {
        controller?.abort();
        controller = new AbortController();
        target.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { headers: { 'X-Live-Filter': '1' }, signal: controller.signal });
            // A redirect means the session expired (login page) — don't inject that page into the list
            if (!response.ok || response.redirected) {
                throw new Error('HTTP ' + response.status);
            }
            target.innerHTML = await response.text();
            history.replaceState(null, '', url);
        } catch (error) {
            if (error.name !== 'AbortError') {
                location.href = url; // fall back to a normal page load
            }
        } finally {
            target.removeAttribute('aria-busy');
        }
    }

    form.addEventListener('input', (event) => {
        if (event.target.matches('input[type="search"], input[type="text"]')) {
            clearTimeout(timer);
            timer = setTimeout(() => load(urlFromForm()), DEBOUNCE_MS);
        }
    });
    form.addEventListener('change', (event) => {
        if (event.target.matches('select')) {
            load(urlFromForm());
        }
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        clearTimeout(timer);
        load(urlFromForm());
    });

    target.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-live-link]');
        if (link && !event.metaKey && !event.ctrlKey) {
            event.preventDefault();
            syncForm(link.href);
            load(link.href);
        }
    });
});
