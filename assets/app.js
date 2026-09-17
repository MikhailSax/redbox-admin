/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import '@fontsource-variable/inter';
import './styles/app.css';
import 'flowbite';
import './admin/collection.js';
import './admin/confirm.js';
import './admin/gallery.js';
import './admin/file-preview.js';
import './admin/password-toggle.js';
import './admin/tabs.js';
import './admin/command-palette.js';
import './admin/live-filter.js';
import './admin/toasts.js';
import './admin/dependent-fields.js';
import './admin/booking-mode.js';
import './admin/sidebar.js';
import './admin/checklist-filter.js';

// Map widgets (Yandex Maps) are only fetched on pages that show a map
if (document.querySelector('[data-map], [data-coordinate-picker]')) {
    import('./admin/map.js');
}
import './admin/service-line.js';
