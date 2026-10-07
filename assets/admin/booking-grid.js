/*
 * Occupancy grid of a structure's booking page (templates/admin/booking/product.html.twig).
 *
 *   <button data-book-side="side id" data-book-from="2026-10" | "2026-10-07"> — a free month of a whole side
 *                                       or a day of airtime with room left: fills the new booking form
 *   <a data-booking-link="booking-ID">  — a taken cell: scrolls to its row in the list when it is on this tab
 *   [data-booking-pick]                 — the bar that shows what is picked, with "fill the form" / "reset"
 *   form[data-booking-form data-min-days] — the new booking form
 *
 * The first click picks the side and the start, a click further along the same side picks the end
 * (the number of months, or the last day); a click anywhere else starts over.
 * Everything is delegated: the grid is swapped in place after actions (ajax-forms.js).
 */
const PICKED = ['ring-2', 'ring-brand', 'ring-offset-1'];

const form = document.querySelector('form[data-booking-form]');
let pick = null; // {side, from, to, ended}: ended once the second click picked the end

const pad = (n) => String(n).padStart(2, '0');
const isoDay = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const addDays = (day, n) => {
    const date = new Date(day + 'T00:00:00');
    date.setDate(date.getDate() + n);

    return isoDay(date);
};
const monthsBetween = (from, to) => {
    const [fy, fm] = from.split('-').map(Number);
    const [ty, tm] = to.split('-').map(Number);

    return (ty - fy) * 12 + (tm - fm) + 1;
};
const isMonth = (key) => key.length === 7;
const field = (name) => form?.querySelector(`[name="booking_form[${name}]"]`);

function setValue(input, value) {
    if (!input) {
        return;
    }
    if (input.tagName === 'SELECT' && ![...input.options].some((option) => option.value === String(value))) {
        return;
    }
    input.value = String(value);
    // booking-mode.js shows the period fields of the side, dependent-fields.js reacts to changes
    input.dispatchEvent(new Event('change', { bubbles: true }));
}

function periodLabel({ from, to }) {
    const date = (key) => new Date((isMonth(key) ? key + '-01' : key) + 'T00:00:00');
    if (isMonth(from)) {
        const month = (key) => date(key).toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }).replace(' г.', '');
        const count = monthsBetween(from, to);

        return (count === 1 ? month(from) : `${month(from)} — ${month(to)}`) + ` (${count} мес.)`;
    }
    const day = (key) => date(key).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
    const days = Math.round((date(to) - date(from)) / 86400000) + 1;

    return `${day(from)} — ${day(to)} (${days} дн.)`;
}

function highlight() {
    document.querySelectorAll('[data-book-from]').forEach((cell) => {
        const inRange = pick && cell.dataset.bookSide === pick.side && cell.dataset.bookFrom >= pick.from && cell.dataset.bookFrom <= pick.to;
        cell.classList.toggle(PICKED[0], inRange);
        PICKED.slice(1).forEach((css) => cell.classList.toggle(css, inRange));
        cell.setAttribute('aria-pressed', inRange ? 'true' : 'false');
    });

    const bar = document.querySelector('[data-booking-pick]');
    if (bar) {
        bar.classList.toggle('hidden', !pick);
        bar.classList.toggle('flex', !!pick);
        if (pick) {
            const side = field('side')?.selectedOptions[0]?.textContent.trim().split(' · ')[0] ?? '';
            bar.querySelector('[data-booking-pick-text]').textContent = `${side}: ${periodLabel(pick)}`;
        }
    }
}

function fill() {
    setValue(field('side'), pick.side);
    if (isMonth(pick.from)) {
        setValue(field('startMonth'), pick.from);
        setValue(field('months'), Math.min(12, monthsBetween(pick.from, pick.to)));
    } else {
        setValue(field('startDate'), pick.from);
        setValue(field('endDate'), pick.to);
    }
}

function choose(cell) {
    const side = cell.dataset.bookSide;
    const key = cell.dataset.bookFrom;
    if (pick && !pick.ended && pick.side === side && key > pick.from) {
        // the second click: the end of the period
        pick.to = key;
        pick.ended = true;
    } else {
        // a new start; airtime takes the minimum number of days until the end is picked
        const minDays = Number(form?.dataset.minDays ?? 1);
        pick = { side, from: key, to: isMonth(key) ? key : addDays(key, minDays - 1), ended: false };
    }
    fill();
    highlight();
}

document.addEventListener('click', (event) => {
    const cell = event.target.closest('[data-book-from]');
    if (cell && form) {
        choose(cell);

        return;
    }

    if (event.target.closest('[data-booking-pick-reset]')) {
        pick = null;
        highlight();

        return;
    }

    if (event.target.closest('[data-booking-pick-go]')) {
        document.getElementById('new-booking')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        (field('client') ?? field('clientName'))?.focus({ preventScroll: true });

        return;
    }

    // A taken cell: its row is on this tab — scroll to it and flash it instead of reloading
    const link = event.target.closest('a[data-booking-link]');
    const row = link && document.getElementById(link.dataset.bookingLink);
    if (row && !event.metaKey && !event.ctrlKey) {
        event.preventDefault();
        history.replaceState(null, '', '#' + row.id);
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.classList.add('bg-brand-softer');
        setTimeout(() => row.classList.remove('bg-brand-softer'), 2000);
    }
});

// The grid swapped in place after an action keeps the picked cells marked
document.addEventListener('ajax-forms:updated', highlight);
