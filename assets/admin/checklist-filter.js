/*
 * Filter a long checkbox list as you type:
 *   <input type="search" data-checklist-filter="list-id">
 *   <div id="list-id"> … <li data-search="lower-case text"> … <li data-checklist-empty hidden> </div>
 * Checked items always stay visible, so a filter never hides what is selected.
 */
document.addEventListener('input', (event) => {
    const input = event.target;
    if (!input.matches('[data-checklist-filter]')) {
        return;
    }

    const list = document.getElementById(input.dataset.checklistFilter);
    if (!list) {
        return;
    }

    const query = input.value.trim().toLowerCase();
    let visible = 0;
    list.querySelectorAll('[data-search]').forEach((item) => {
        const show = query === '' || item.dataset.search.includes(query) || item.querySelector('input:checked') !== null;
        item.hidden = !show;
        visible += show ? 1 : 0;
    });

    const empty = list.querySelector('[data-checklist-empty]');
    if (empty) {
        empty.hidden = visible > 0;
    }
});
