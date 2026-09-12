/*
 * "Add a service" form of a media plan: picking a catalog entry fills name, unit and price
 * (ServiceLineFormType puts them on the <option> as data-name / data-unit / data-price).
 */
document.addEventListener('change', (event) => {
    const select = event.target.closest('select[data-service-select]');
    if (!select) {
        return;
    }

    const option = select.selectedOptions[0];
    const form = select.form;
    if (!option || option.value === '') {
        return;
    }

    for (const key of ['name', 'unit', 'price']) {
        const field = form.querySelector('[data-service-field="' + key + '"]');
        if (field && option.dataset[key] !== undefined) {
            // "350.00" → "350", "350.50" → "350.5" (the money field accepts a dot)
            field.value = key === 'price' ? String(Number(option.dataset.price)) : option.dataset[key];
        }
    }
    form.querySelector('input[name$="[quantity]"]')?.focus();
});
