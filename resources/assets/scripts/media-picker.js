/**
 * Media library picker — a Bootstrap modal listing approved public media.
 * Any element with [data-media-picker] gets a picker: data attributes
 *   data-media-kind   image | document | any   (default: image)
 *   data-media-target CSS selector of the input receiving the media id
 *   data-media-preview CSS selector of a container showing the selection
 * The picker's callback style lets block-editor cards reuse it too.
 */
import { Modal } from 'bootstrap';

let cache = {};

async function fetchMedia(kind) {
    if (!cache[kind]) {
        const res = await fetch('/admin/media/options?kind=' + encodeURIComponent(kind), { credentials: 'same-origin' });
        if (!res.ok) {
            throw new Error('Could not load the media library.');
        }
        cache[kind] = (await res.json()).items || [];
    }
    return cache[kind];
}

function ensureModal() {
    let modal = document.getElementById('media-picker-modal');
    if (modal) {
        return modal;
    }
    modal = document.createElement('div');
    modal.className = 'modal fade';
    modal.id = 'media-picker-modal';
    modal.tabIndex = -1;
    modal.innerHTML = [
        '<div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">',
        '<div class="modal-header"><h5 class="modal-title">Media library</h5>',
        '<input type="search" class="form-control w-auto ms-auto me-2" placeholder="Search media" aria-label="Search media">',
        '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>',
        '<div class="modal-body"><div class="row g-2 media-picker-grid"></div>',
        '<p class="text-body-secondary small media-picker-empty d-none mt-2">No approved media found. Upload files under Media, approve them, then pick them here.</p></div>',
        '</div></div>',
    ].join('');
    document.body.appendChild(modal);
    return modal;
}

function card(item, onPick) {
    const col = document.createElement('div');
    col.className = 'col-6 col-md-3 col-lg-2 media-picker-item';
    col.dataset.search = (item.filename + ' ' + item.alt).toLowerCase();
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-outline-secondary w-100 h-100 p-1 text-start';
    if (item.mime.startsWith('image/')) {
        const img = document.createElement('img');
        img.src = item.url;
        img.alt = item.alt || item.filename;
        img.className = 'img-fluid rounded mb-1';
        img.loading = 'lazy';
        btn.appendChild(img);
    } else {
        const icon = document.createElement('div');
        icon.className = 'display-6 text-center py-3';
        icon.innerHTML = '<i class="bi bi-file-earmark"></i>';
        btn.appendChild(icon);
    }
    const name = document.createElement('small');
    name.className = 'd-block text-truncate';
    name.textContent = item.filename;
    btn.appendChild(name);
    btn.addEventListener('click', () => onPick(item));
    col.appendChild(btn);
    return col;
}

/** Open the picker; onPick(item) receives {id, url, mime, alt, filename}. */
export function openMediaPicker(kind, onPick) {
    const modalEl = ensureModal();
    const grid = modalEl.querySelector('.media-picker-grid');
    const search = modalEl.querySelector('input[type="search"]');
    const empty = modalEl.querySelector('.media-picker-empty');
    grid.textContent = '';
    search.value = '';
    fetchMedia(kind).then((items) => {
        grid.textContent = '';
        empty.classList.toggle('d-none', items.length > 0);
        items.forEach((item) => grid.appendChild(card(item, (picked) => {
            Modal.getOrCreateInstance(modalEl).hide();
            onPick(picked);
        })));
    }).catch(() => {
        empty.textContent = 'Could not load the media library.';
        empty.classList.remove('d-none');
    });
    search.oninput = () => {
        const q = search.value.trim().toLowerCase();
        modalEl.querySelectorAll('.media-picker-item').forEach((el) => {
            el.classList.toggle('d-none', q !== '' && !el.dataset.search.includes(q));
        });
    };
    Modal.getOrCreateInstance(modalEl).show();
}

function renderPreview(container, item) {
    container.textContent = '';
    if (!item) {
        return;
    }
    const wrap = document.createElement('div');
    wrap.className = 'd-flex align-items-center gap-2 border rounded p-2 bg-body';
    if (item.mime && item.mime.startsWith('image/')) {
        const img = document.createElement('img');
        img.src = item.url;
        img.alt = item.alt || '';
        img.style.cssText = 'max-width:64px;max-height:64px;object-fit:cover;';
        img.className = 'rounded';
        wrap.appendChild(img);
    } else {
        const icon = document.createElement('i');
        icon.className = 'bi bi-file-earmark fs-3';
        wrap.appendChild(icon);
    }
    const label = document.createElement('small');
    label.textContent = item.filename || ('Media #' + item.id);
    wrap.appendChild(label);
    container.appendChild(wrap);
}

/**
 * Bind declarative pickers used outside the block editor (hero slides,
 * team portraits). The hidden input gets the id; the preview container
 * shows a thumbnail. Initial preview comes from data-* JSON if present.
 */
export function bindMediaPickers(root = document) {
    root.querySelectorAll('[data-media-picker]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const kind = btn.dataset.mediaKind || 'image';
            const target = document.querySelector(btn.dataset.mediaTarget);
            const preview = document.querySelector(btn.dataset.mediaPreview);
            openMediaPicker(kind, (item) => {
                if (target) {
                    target.value = String(item.id);
                }
                if (preview) {
                    renderPreview(preview, item);
                }
            });
        });
    });
    root.querySelectorAll('[data-media-preview]').forEach((container) => {
        if (container.dataset.mediaCurrent) {
            try {
                renderPreview(container, JSON.parse(container.dataset.mediaCurrent));
            } catch { /* malformed current value — leave empty */ }
        }
    });
}
