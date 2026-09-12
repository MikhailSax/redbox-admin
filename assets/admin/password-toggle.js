/*
 * Show/hide password: <button data-password-toggle="input-id"> with [data-icon="show"] and [data-icon="hide"] inside.
 */
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-password-toggle]');
    if (!button) {
        return;
    }

    const input = document.getElementById(button.dataset.passwordToggle);
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';

    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('aria-label', visible ? 'Скрыть пароль' : 'Показать пароль');
    button.querySelector('[data-icon="show"]').classList.toggle('hidden', visible);
    button.querySelector('[data-icon="hide"]').classList.toggle('hidden', !visible);
});
