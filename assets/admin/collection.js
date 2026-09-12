/*
 * Add/remove entries of a Symfony CollectionType.
 *
 *   <div id="x" data-prototype="..." data-index="2">   holder with the rendered prototype
 *     <div data-collection-item> ... <button data-collection-remove data-confirm="optional"> </div>
 *   </div>
 *   <button data-collection-add="x">
 */
import { confirmDialog } from './confirm.js';

document.addEventListener('click', async (event) => {
    const addButton = event.target.closest('[data-collection-add]');
    if (addButton) {
        const holder = document.getElementById(addButton.dataset.collectionAdd);
        const index = Number(holder.dataset.index);
        holder.insertAdjacentHTML('beforeend', holder.dataset.prototype.replace(/__name__/g, String(index)));
        holder.dataset.index = String(index + 1);
        holder.lastElementChild?.querySelector('input, textarea, select')?.focus();

        return;
    }

    const removeButton = event.target.closest('[data-collection-remove]');
    if (removeButton) {
        const message = removeButton.dataset.confirm;
        if (message && !(await confirmDialog(message, { title: removeButton.dataset.confirmTitle, button: removeButton.dataset.confirmButton }))) {
            return;
        }
        removeButton.closest('[data-collection-item]')?.remove();
    }
});
