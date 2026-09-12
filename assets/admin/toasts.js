/*
 * Flash message toasts (admin/layout.html.twig): success ones hide after a few seconds, errors stay until closed.
 */
function dismiss(toast) {
    toast.classList.add('opacity-0', 'translate-y-2');
    setTimeout(() => toast.remove(), 300);
}

document.querySelectorAll('[data-toast]').forEach((toast) => {
    if (toast.dataset.toast === 'auto') {
        setTimeout(() => dismiss(toast), 5000);
    }
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-toast-close]');
    if (button) {
        dismiss(button.closest('[data-toast]'));
    }
});
