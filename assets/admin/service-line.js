/*
 * "Add a service" forms (media plan, booking): picking a catalog entry fills name, unit and price
 * (ServiceLineFormType puts them on the <option> as data-name / data-unit / data-price).
 * A form with several service rows (the new booking) wraps each one in [data-service-line]: the pick fills its own row.
 */
document.addEventListener('change', (event) => {
    const select = event.target.closest('select[data-service-select]');
    if (!select) {
        return;
    }

    const option = select.selectedOptions[0];
    const scope = select.closest('[data-service-line]') ?? select.form;
    if (!option || option.value === '' || !scope) {
        return;
    }

    for (const key of ['name', 'unit', 'price']) {
        const field = scope.querySelector('[data-service-field="' + key + '"]');
        if (field && option.dataset[key] !== undefined) {
            // "350.00" → "350", "350.50" → "350.5" (the money field accepts a dot)
            field.value = key === 'price' ? String(Number(option.dataset.price)) : option.dataset[key];
        }
    }
    scope.querySelector('input[name$="[quantity]"]')?.focus();
});
