/*
 * Product form, "Стороны и фото": the slot settings ([data-airtime-only]) of a side are shown only when the side
 * is a video screen — by its own type (select[data-side-type]) or, when it has none, by the structure's
 * (select[data-product-type]). Type options carry data-airtime="1|0". Works for sides added from the prototype too.
 */
const airtime = (select) => select?.selectedOptions[0]?.dataset.airtime === '1';

function update(form) {
    const productType = form.querySelector('select[data-product-type]');
    form.querySelectorAll('[data-collection-item]').forEach((side) => {
        const sideType = side.querySelector('select[data-side-type]');
        const isAirtime = sideType?.value ? airtime(sideType) : airtime(productType);
        side.querySelectorAll('[data-airtime-only]').forEach((row) => row.classList.toggle('hidden', !isAirtime));
    });
}

document.querySelectorAll('form').forEach((form) => {
    if (!form.querySelector('select[data-side-type], select[data-product-type]')) {
        return;
    }
    form.addEventListener('change', (event) => {
        if (event.target.matches('select[data-side-type], select[data-product-type]')) {
            update(form);
        }
    });
    // a side added from the prototype
    new MutationObserver(() => update(form)).observe(form, {childList: true, subtree: true});
    update(form);
});
