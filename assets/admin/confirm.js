/*
 * Confirmation dialog (templates/admin/_confirm_modal.html.twig) instead of window.confirm().
 *
 *   <form data-confirm="Message"> or <button type="submit" data-confirm="Message">
 *   optional: data-confirm-title, data-confirm-button, data-confirm-variant="primary"
 *
 * confirmDialog() is also exported for scripts (e.g. removing a collection entry).
 */
import { Modal } from 'flowbite';

const VARIANTS = {
    danger: { title: 'Удалить?', button: 'Да, удалить', buttonClass: 'btn-danger' },
    primary: { title: 'Подтвердите действие', button: 'Подтвердить', buttonClass: 'btn-primary' },
};

let modal = null;
let modalElement = null;
let resolvePending = null;

function settle(result) {
    const resolve = resolvePending;
    resolvePending = null;
    resolve?.(result);
}

function getModal() {
    const element = (modalElement ??= document.getElementById('confirm-modal'));
    if (!element) {
        return null;
    }
    if (!modal) {
        modal = new Modal(element, {
            backdrop: 'dynamic',
            backdropClasses: 'bg-dark-backdrop/70 fixed inset-0 z-40',
            closable: true, // Esc and backdrop click close it
            onHide: () => settle(false),
        });
        element.querySelectorAll('[data-confirm-cancel]').forEach((button) => button.addEventListener('click', () => modal.hide()));
        element.querySelector('[data-confirm-accept]').addEventListener('click', () => {
            settle(true);
            modal.hide();
        });
    }

    return modal;
}

/**
 * @returns {Promise<boolean>} true when the user confirmed
 */
export function confirmDialog(message, { title, button, variant = 'danger' } = {}) {
    const dialog = getModal();
    if (!dialog) {
        return Promise.resolve(window.confirm(message));
    }

    const preset = VARIANTS[variant] ?? VARIANTS.danger;
    const element = modalElement;
    element.querySelector('[data-confirm-title]').textContent = title || preset.title;
    element.querySelector('[data-confirm-message]').textContent = message;

    const accept = element.querySelector('[data-confirm-accept]');
    accept.textContent = button || preset.button;
    accept.classList.remove(...Object.values(VARIANTS).map((v) => v.buttonClass));
    accept.classList.add(preset.buttonClass);

    element.querySelectorAll('[data-confirm-icon]').forEach((icon) => {
        const visible = icon.dataset.confirmIcon === (variant in VARIANTS ? variant : 'danger');
        icon.classList.toggle('hidden', !visible);
        icon.classList.toggle('flex', visible);
    });

    settle(false); // a previous, still pending dialog counts as cancelled

    return new Promise((resolve) => {
        resolvePending = resolve;
        dialog.show();
        accept.focus();
    });
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    const source = event.submitter?.dataset.confirm ? event.submitter : form;
    const message = source.dataset.confirm;

    if (!message || form.dataset.confirmed === '1') {
        delete form.dataset.confirmed;

        return;
    }

    event.preventDefault();
    const submitter = event.submitter;
    confirmDialog(message, {
        title: source.dataset.confirmTitle,
        button: source.dataset.confirmButton,
        variant: source.dataset.confirmVariant,
    }).then((confirmed) => {
        if (confirmed) {
            form.dataset.confirmed = '1';
            form.requestSubmit(submitter ?? undefined);
        }
    });
});
