import './bootstrap';
import htmx from 'htmx.org';
import { createIcons, icons } from 'lucide';
import flatpickr from 'flatpickr';
import { Latvian } from 'flatpickr/dist/l10n/lv.js';
import { Russian } from 'flatpickr/dist/l10n/ru.js';
import 'flatpickr/dist/flatpickr.min.css';

window.htmx = htmx;
window.flatpickr = flatpickr;
window.flatpickrLocales = {
    'lv': Latvian,
    'ru': Russian,
    'en': 'default'
};

// Initialize Lucide icons on page load and on every HTMX content swap
document.addEventListener('DOMContentLoaded', () => {
    createIcons({ icons });
});

document.addEventListener('htmx:afterSwap', () => {
    createIcons({ icons });
});

document.addEventListener('htmx:afterSettle', () => {
    createIcons({ icons });
});
