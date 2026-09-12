/*
 * Shows thumbnails of images picked in <input type="file" data-file-preview> before the form is saved.
 */
document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || input.type !== 'file' || !input.hasAttribute('data-file-preview')) {
        return;
    }

    let preview = input.parentElement.querySelector('[data-file-preview-list]');
    if (!preview) {
        preview = document.createElement('div');
        preview.dataset.filePreviewList = '';
        // Several files: a row of square thumbnails; a single file: one wide preview
        preview.className = input.multiple ? 'grid grid-cols-4 sm:grid-cols-6 gap-2 mt-3' : 'mt-3';
        input.insertAdjacentElement('afterend', preview);
    }

    preview.querySelectorAll('img').forEach((img) => URL.revokeObjectURL(img.src));
    preview.replaceChildren(...Array.from(input.files)
        .filter((file) => file.type.startsWith('image/'))
        .map((file) => {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            img.alt = file.name;
            img.title = `${file.name} — будет загружено после сохранения`;
            img.className = `w-full ${input.multiple ? 'aspect-square' : 'aspect-video'} object-cover rounded-base border border-dashed border-brand`;

            return img;
        }));
});
