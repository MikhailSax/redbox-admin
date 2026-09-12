/*
 * Photo gallery: clicking a [data-gallery-thumb] shows it in the [data-gallery-main] image.
 */
document.addEventListener('click', (event) => {
    const thumb = event.target.closest('[data-gallery-thumb]');
    if (!thumb) {
        return;
    }

    const gallery = thumb.closest('[data-gallery]');
    const main = gallery.querySelector('[data-gallery-main]');
    main.src = thumb.dataset.src;
    main.alt = thumb.dataset.name;
    gallery.querySelector('[data-gallery-link]').href = thumb.dataset.src;
    gallery.querySelector('[data-gallery-caption]').textContent = thumb.dataset.caption;

    gallery.querySelectorAll('[data-gallery-thumb]').forEach((other) => {
        const active = other === thumb;
        other.classList.toggle('border-brand', active);
        other.classList.toggle('border-transparent', !active);
    });
});
