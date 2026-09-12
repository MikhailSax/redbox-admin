/*
 * Show or hide a form row depending on another field:
 *   <div data-visible-when="product_form_owner"> — visible while #product_form_owner has a value
 *   <div data-hidden-when="promotion_form_appliesToAll"> — hidden while the checkbox is checked
 * Checkboxes and radios count as "having a value" when checked.
 */
function isSet(source) {
    if (!source) {
        return false;
    }

    return source.type === 'checkbox' || source.type === 'radio' ? source.checked : source.value !== '';
}

function update(row) {
    if (row.dataset.visibleWhen !== undefined) {
        row.classList.toggle('hidden', !isSet(document.getElementById(row.dataset.visibleWhen)));
    }
    if (row.dataset.hiddenWhen !== undefined) {
        row.classList.toggle('hidden', isSet(document.getElementById(row.dataset.hiddenWhen)));
    }
}

function dependentsOf(id) {
    const escaped = CSS.escape(id);

    return document.querySelectorAll(`[data-visible-when="${escaped}"], [data-hidden-when="${escaped}"]`);
}

document.querySelectorAll('[data-visible-when], [data-hidden-when]').forEach(update);

document.addEventListener('change', (event) => {
    const target = event.target;
    // a radio unchecks its siblings without a change event on them
    const sources = target.type === 'radio' && target.name
        ? document.querySelectorAll(`input[type="radio"][name="${CSS.escape(target.name)}"]`)
        : [target];

    sources.forEach((source) => source.id && dependentsOf(source.id).forEach(update));
});
