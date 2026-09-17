/*
 * Show or hide a form row depending on another field:
 *   <div data-visible-when="product_form_owner"> — visible while #product_form_owner has a value
 *   <div data-hidden-when="promotion_form_appliesToAll"> — hidden while the checkbox is checked
 *   <div data-visible-when="client_form_clientType" data-visible-values="entrepreneur legal">
 *       — visible while the field's value is one of the listed; the source may be a group of radios (its container id)
 * Checkboxes and radios count as "having a value" when checked.
 */
function valueOf(source) {
    if (!source) {
        return '';
    }
    if (source.type === 'checkbox' || source.type === 'radio') {
        return source.checked ? source.value : '';
    }
    if (!('value' in source) || source.tagName === 'DIV' || source.tagName === 'FIELDSET') {
        // expanded choices: the container of the radios
        return source.querySelector('input:checked')?.value ?? '';
    }

    return source.value;
}

function update(row) {
    if (row.dataset.visibleWhen !== undefined) {
        const value = valueOf(document.getElementById(row.dataset.visibleWhen));
        const visible = row.dataset.visibleValues !== undefined
            ? row.dataset.visibleValues.split(/\s+/).includes(value)
            : value !== '';
        row.classList.toggle('hidden', !visible);
    }
    if (row.dataset.hiddenWhen !== undefined) {
        row.classList.toggle('hidden', valueOf(document.getElementById(row.dataset.hiddenWhen)) !== '');
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
        ? [...document.querySelectorAll(`input[type="radio"][name="${CSS.escape(target.name)}"]`)]
        : [target];
    // rows may depend on the container of a radio group rather than on one radio
    const container = target.type === 'radio' ? target.parentElement?.closest('[id]') : null;
    if (container) {
        sources.push(container);
    }

    sources.forEach((source) => source.id && dependentsOf(source.id).forEach(update));
});
