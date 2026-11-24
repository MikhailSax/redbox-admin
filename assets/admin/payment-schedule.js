/*
 * Payment schedule form (templates/admin/payment/schedule.html.twig): as the manager types the sums of the months,
 * shows how much is in the schedule and how much of the total is left to distribute. Nothing is filled in for them.
 */
const format = (amount) => {
    const rounded = Math.round(amount * 100) / 100;
    return rounded.toLocaleString('ru-RU', {minimumFractionDigits: rounded % 1 === 0 ? 0 : 2, maximumFractionDigits: 2}) + ' ₽';
};

const parse = (value) => {
    const number = Number(String(value).replace(/\s/g, '').replace(',', '.'));
    return Number.isFinite(number) && number > 0 ? number : 0;
};

const update = (form) => {
    const total = Number(form.dataset.scheduleTotal) || 0;
    const sum = [...form.querySelectorAll('[data-schedule-amount]')].reduce((acc, input) => acc + parse(input.value), 0);
    const left = Math.round((total - sum) * 100) / 100;

    document.querySelector('[data-schedule-sum]').textContent = sum > 0 ? format(sum) : '—';
    const leftCell = document.querySelector('[data-schedule-left]');
    leftCell.textContent = format(left);
    leftCell.classList.toggle('text-fg-danger', left < 0);
    leftCell.classList.toggle('text-fg-success-strong', left === 0);
};

document.addEventListener('input', (event) => {
    const form = event.target.closest('form[data-payment-schedule]');
    if (form && event.target.matches('[data-schedule-amount]')) {
        update(form);
    }
});

const form = document.querySelector('form[data-payment-schedule]');
if (form) {
    update(form);
}
