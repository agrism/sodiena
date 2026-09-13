import './bootstrap';
import htmx from 'htmx.org';
import { createIcons, icons } from 'lucide';

window.htmx = htmx;

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
