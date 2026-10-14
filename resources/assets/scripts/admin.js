import 'admin-lte/dist/css/adminlte.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import 'bootstrap';
import 'admin-lte';
import { bindMediaPickers } from './media-picker.js';

document.addEventListener('DOMContentLoaded', () => bindMediaPickers());

// "Add new content": derive the slug from the title until it is edited by hand.
document.addEventListener('DOMContentLoaded', () => {
    const title = document.getElementById('title');
    const slug = document.getElementById('slug');
    const type = document.getElementById('type');
    if (!title || !slug || !slug.closest('form')?.action.endsWith('/admin/content/new')) {
        return;
    }
    let touched = false;
    slug.addEventListener('input', () => { touched = true; });
    const derive = () => {
        const prefix = type && (type.value === 'news' || type.value === 'story') ? '/news/' : '/';
        const base = title.value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        slug.value = prefix + base;
    };
    title.addEventListener('input', () => { if (!touched) { derive(); } });
    if (type) {
        type.addEventListener('change', () => { if (!touched) { derive(); } });
    }
});
