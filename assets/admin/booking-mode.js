/*
 * Booking form of a structure whose sides are booked differently (a screen on one side, a static poster on another):
 * shows only the period fields of the chosen side.
 *   <select data-booking-mode-source>, its options carry data-booking-mode="airtime|side"
 *   rows [data-booking-mode="airtime|side"] — visible for that mode, or while no side is chosen
 */
document.querySelectorAll('select[data-booking-mode-source]').forEach((select) => {
    const update = () => {
        const mode = select.selectedOptions[0]?.dataset.bookingMode;
        select.form.querySelectorAll('[data-booking-mode]:not(option)').forEach((row) => {
            row.classList.toggle('hidden', mode !== undefined && row.dataset.bookingMode !== mode);
        });
    };

    select.addEventListener('change', update);
    update();
});
