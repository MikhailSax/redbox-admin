/*
 * New booking: shows what the contact fields will be filled with when they are left empty.
 *   <select data-booking-client>, its options carry data-contact and data-phone of the client card
 *   the contact inputs are the form's clientName / clientPhone
 */
document.querySelectorAll('select[data-booking-client]').forEach((select) => {
    const name = select.form.querySelector('[id$="_clientName"]');
    const phone = select.form.querySelector('[id$="_clientPhone"]');

    const update = () => {
        const client = select.selectedOptions[0]?.dataset ?? {};
        [[name, client.contact], [phone, client.phone]].forEach(([input, value]) => {
            if (input) {
                input.placeholder = value || input.dataset.placeholder || '';
            }
        });
    };

    [name, phone].forEach((input) => input && (input.dataset.placeholder = input.placeholder));
    select.addEventListener('change', update);
    update();
});
