import 'admin-lte/dist/css/adminlte.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '../styles/_admin-theme.scss';
import 'bootstrap';
import { Toast } from 'bootstrap';
import 'admin-lte';
import { bindMediaPickers } from './media-picker.js';

// Bottom-right toast notifications for in-page feedback.
window.cmsToast = function (message, variant = 'success') {
    let container = document.querySelector('.desk-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'desk-toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = 'toast align-items-center';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    const flex = document.createElement('div');
    flex.className = 'd-flex';
    const body = document.createElement('div');
    body.className = 'toast-body';
    const icon = document.createElement('i');
    icon.className = 'bi me-2 ' + (variant === 'error' ? 'bi-exclamation-triangle' : 'bi-check-circle');
    body.appendChild(icon);
    body.appendChild(document.createTextNode(message));
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'btn-close btn-close-white me-2 m-auto';
    close.dataset.bsDismiss = 'toast';
    close.setAttribute('aria-label', 'Close');
    flex.append(body, close);
    toast.appendChild(flex);
    container.appendChild(toast);
    const instance = new Toast(toast, { delay: 3200 });
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
    instance.show();
};

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
