/**
 * Visual block editor for CMS sections. Renders the sections JSON as an
 * ordered stack of cards — one per block — with type-specific form fields.
 * On form submit the state serializes back into the hidden `sections`
 * input, so the server contract is unchanged. All user text goes through
 * textContent/value, never innerHTML — CSP and XSS safe by construction.
 */
import Quill from 'quill';
import { openMediaPicker } from './media-picker.js';

const TYPES = {
    heading: { label: 'Section heading', icon: 'bi-type-h2', hint: 'Starts a new section on the page' },
    text: { label: 'Paragraph', icon: 'bi-text-paragraph', hint: 'Plain text; blank lines make paragraphs' },
    rich_text: { label: 'Formatted text', icon: 'bi-textarea-t', hint: 'Bold, links, lists and headings' },
    image: { label: 'Image', icon: 'bi-image', hint: 'From the media library' },
    document: { label: 'Document', icon: 'bi-file-earmark-arrow-down', hint: 'A downloadable file' },
    quote: { label: 'Quote', icon: 'bi-quote', hint: 'A highlighted quotation' },
    cta: { label: 'Button / link', icon: 'bi-link-45deg', hint: 'Call-to-action button' },
    statistic: { label: 'Statistic', icon: 'bi-123', hint: 'A highlighted number with source' },
    cards: { label: 'Card grid', icon: 'bi-grid-3x2-gap', hint: 'Titled cards in a grid' },
    gallery: { label: 'Photo gallery', icon: 'bi-images', hint: 'A grid of images' },
    accordion: { label: 'FAQ accordion', icon: 'bi-list-check', hint: 'Expandable question rows' },
    timeline: { label: 'Timeline', icon: 'bi-clock-history', hint: 'Ordered milestones' },
};

const COLLECTIONS = { cards: 'card', accordion: 'item', timeline: 'milestone' };
const MAX_BLOCKS = 50;
const MAX_ITEMS = 24;
let uidCounter = 0;

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== undefined) {
        node.textContent = text;
    }
    return node;
}

function field(labelText, control, helpText) {
    const wrap = el('div', 'mb-2');
    const label = el('label', 'form-label small fw-semibold mb-1', labelText);
    wrap.appendChild(label);
    wrap.appendChild(control);
    if (helpText) {
        wrap.appendChild(el('div', 'form-text', helpText));
    }
    return wrap;
}

function input(value, attrs = {}) {
    const node = el('input', 'form-control form-control-sm');
    node.type = 'text';
    node.value = value || '';
    Object.assign(node, attrs);
    return node;
}

function textArea(value, rows = 3) {
    const node = el('textarea', 'form-control form-control-sm');
    node.rows = rows;
    node.value = value || '';
    return node;
}

function sync(node, block, key) {
    node.addEventListener('input', () => {
        block[key] = node.value;
    });
    return node;
}

function mediaControl(block, kind, onChange) {
    const wrap = el('div', 'd-flex align-items-start gap-2 flex-wrap');
    const preview = el('div', 'media-thumb');
    const render = () => {
        preview.textContent = '';
        if (!block._media) {
            if (block.media_id) {
                const wrap = el('div', 'd-flex align-items-center gap-2');
                const img = el('img');
                img.src = '/media/' + block.media_id;
                img.alt = '';
                img.className = 'rounded border be-thumb';
                img.addEventListener('error', () => { img.remove(); });
                wrap.appendChild(img);
                wrap.appendChild(el('small', '', 'Media #' + block.media_id));
                preview.appendChild(wrap);
            } else {
                preview.appendChild(el('span', 'text-body-secondary small', 'No media selected'));
            }
            return;
        }
        if (block._media.mime && block._media.mime.startsWith('image/')) {
            const img = el('img');
            img.src = block._media.url;
            img.alt = block._media.alt || '';
            img.className = 'rounded border be-thumb';
            preview.appendChild(img);
        }
        preview.appendChild(el('small', 'd-block', block._media.filename || ('Media #' + block.media_id)));
    };
    render();
    const pick = el('button', 'btn btn-outline-primary btn-sm', block.media_id ? 'Change' : 'Choose');
    pick.type = 'button';
    pick.prepend(Object.assign(el('i', 'bi bi-images me-1'), {}));
    pick.addEventListener('click', () => {
        openMediaPicker(kind, (item) => {
            block.media_id = item.id;
            block._media = item;
            pick.textContent = 'Change';
            render();
            if (onChange) {
                onChange(item);
            }
        });
    });
    wrap.appendChild(preview);
    const btnCol = el('div', 'd-flex flex-column gap-1');
    btnCol.appendChild(pick);
    wrap.appendChild(btnCol);
    return wrap;
}

function collectionItems(block, itemLabel, onMutate) {
    const mutate = () => { if (onMutate) { onMutate(); } };
    const list = el('div', 'vstack gap-2');
    const isGallery = block.type === 'gallery';
    const renderItems = () => {
        list.textContent = '';
        block.items.forEach((item, idx) => {
            const row = el('div', 'border rounded p-2 bg-body-secondary');
            const head = el('div', 'd-flex justify-content-between align-items-center mb-1');
            head.appendChild(el('strong', 'small', itemLabel + ' ' + (idx + 1)));
            const tools = el('div', 'btn-group btn-group-sm');
            const up = el('button', 'btn btn-outline-secondary');
            up.type = 'button';
            up.innerHTML = '<i class="bi bi-arrow-up"></i>';
            up.title = 'Move up';
            up.disabled = idx === 0;
            up.addEventListener('click', () => { [block.items[idx - 1], block.items[idx]] = [block.items[idx], block.items[idx - 1]]; renderItems(); mutate(); });
            const down = el('button', 'btn btn-outline-secondary');
            down.type = 'button';
            down.innerHTML = '<i class="bi bi-arrow-down"></i>';
            down.title = 'Move down';
            down.disabled = idx === block.items.length - 1;
            down.addEventListener('click', () => { [block.items[idx + 1], block.items[idx]] = [block.items[idx], block.items[idx + 1]]; renderItems(); mutate(); });
            const del = el('button', 'btn btn-outline-danger');
            del.type = 'button';
            del.innerHTML = '<i class="bi bi-trash"></i>';
            del.title = 'Remove';
            del.addEventListener('click', () => { block.items.splice(idx, 1); renderItems(); mutate(); });
            tools.append(up, down, del);
            head.appendChild(tools);
            row.appendChild(head);
            if (isGallery) {
                row.appendChild(mediaControl(item, 'image', mutate));
                row.appendChild(field('Alt text', sync(input(item.alt), item, 'alt'), 'Describe the image for screen readers'));
            } else {
                row.appendChild(field('Title', sync(input(item.title), item, 'title')));
                row.appendChild(field('Text', sync(textArea(item.text, 2), item, 'text')));
            }
            list.appendChild(row);
        });
    };
    renderItems();
    const add = el('button', 'btn btn-outline-primary btn-sm align-self-start');
    add.type = 'button';
    add.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add ' + itemLabel;
    add.disabled = block.items.length >= MAX_ITEMS;
    add.addEventListener('click', () => {
        if (block.items.length < MAX_ITEMS) {
            block.items.push(isGallery ? { media_id: 0, alt: '' } : { title: '', text: '' });
            renderItems();
            add.disabled = block.items.length >= MAX_ITEMS;
            mutate();
        }
    });
    const wrap = el('div', 'vstack gap-2');
    wrap.append(list, add);
    return wrap;
}

function blockBody(block, quills, onMutate) {
    const body = el('div', 'card-body');
    switch (block.type) {
        case 'heading':
            body.appendChild(field('Heading text', sync(input(block.text, { maxLength: 250 }), block, 'text')));
            break;
        case 'text':
            body.appendChild(field('Text', sync(textArea(block.text, 4), block, 'text'), 'Blank lines create paragraphs.'));
            break;
        case 'rich_text': {
            const holder = el('div', 'quill-holder bg-body be-quill-holder');
            body.appendChild(field('Formatted content', holder));
            quills.set(block._uid, { holder, html: block.html || '' });
            break;
        }
        case 'image':
            body.appendChild(field('Image', mediaControl(block, 'image', () => onMutate && onMutate())));
            body.appendChild(field('Alt text', sync(input(block.alt, { maxLength: 500 }), block, 'alt'), 'Describe the image for screen readers.'));
            break;
        case 'document':
            body.appendChild(field('File', mediaControl(block, 'document', () => onMutate && onMutate())));
            body.appendChild(field('Link label', sync(input(block.label, { maxLength: 200 }), block, 'label'), 'e.g. "Download the annual report".'));
            break;
        case 'quote':
            body.appendChild(field('Quote', sync(textArea(block.text, 2), block, 'text')));
            body.appendChild(field('Attribution', sync(input(block.attribution, { maxLength: 250 }), block, 'attribution')));
            break;
        case 'cta':
            body.appendChild(field('Button label', sync(input(block.label, { maxLength: 100 }), block, 'label')));
            body.appendChild(field('Link URL', sync(input(block.url, { maxLength: 1000, placeholder: '/contact or https://…' }), block, 'url'), 'A site path like /programs or a full https:// address.'));
            break;
        case 'statistic': {
            const kind = el('select', 'form-select form-select-sm');
            [['context', 'Context — a referenced external figure'], ['target', 'Target — a goal we are working toward']].forEach(([v, label]) => {
                const opt = el('option', '', label);
                opt.value = v;
                if (block.kind === v) {
                    opt.selected = true;
                }
                kind.appendChild(opt);
            });
            kind.addEventListener('change', () => { block.kind = kind.value; });
            body.appendChild(field('Statistic type', kind));
            body.appendChild(field('Value', sync(input(block.value, { maxLength: 100, placeholder: 'e.g. 54%' }), block, 'value')));
            body.appendChild(field('Label', sync(input(block.label, { maxLength: 200, placeholder: 'e.g. People in Africa under 30' }), block, 'label')));
            body.appendChild(field('Source', sync(input(block.source, { maxLength: 1000 }), block, 'source')));
            body.appendChild(field('Period', sync(input(block.period, { maxLength: 100, placeholder: 'e.g. 2024' }), block, 'period')));
            break;
        }
        case 'cards': case 'accordion': case 'timeline': case 'gallery':
            body.appendChild(collectionItems(block, COLLECTIONS[block.type] || 'item', onMutate));
            break;
        default:
            body.appendChild(el('p', 'text-body-secondary small mb-0', 'This block type has no visual editor yet — use Edit as JSON.'));
    }
    return body;
}

export function initBlockEditor(mount) {
    const form = mount.closest('form');
    const scope = form || mount;
    const hidden = form ? form.querySelector('input[name="sections"]') : null;
    const jsonPanel = scope.querySelector('[data-be-json]') || mount.closest('.card')?.querySelector('[data-be-json]') || null;
    const jsonArea = scope.querySelector('[data-be-json-area]') || mount.closest('.card')?.querySelector('[data-be-json-area]') || null;
    const blockList = el('div', 'vstack gap-2 mb-3');
    const toolbar = el('div', 'mb-3');
    const alertBox = el('div', 'mb-2');
    const quills = new Map();
    const slotHeadings = (() => { try { return JSON.parse(mount.dataset.slots || '[]'); } catch { return []; } })();
    let blocks = [];
    let jsonMode = false;
    let dirty = false;
    let dragUid = null;

    const normalise = (s) => String(s || '').toLowerCase().trim().replace(/\s+/g, ' ');

    // Unsaved-changes marker — shown in the publish rail and guards navigation.
    function markDirty() {
        dirty = true;
        document.querySelectorAll('[data-be-dirty]').forEach((pill) => { pill.hidden = false; });
    }

    function clearDirty() {
        dirty = false;
        document.querySelectorAll('[data-be-dirty]').forEach((pill) => { pill.hidden = true; });
    }

    function warn(message) {
        alertBox.textContent = '';
        if (message) {
            const box = el('div', 'alert alert-warning alert-dismissible fade show py-2', message);
            const close = el('button', 'btn-close');
            close.type = 'button';
            close.dataset.bsDismiss = 'alert';
            box.appendChild(close);
            alertBox.appendChild(box);
        }
    }

    function makeCard(block) {
        const card = el('div', 'card be-block');
        card.dataset.uid = block._uid;
        const head = el('div', 'card-header d-flex align-items-center gap-2 py-2');
        // Drag handle — the only draggable element so text selection still works.
        const grip = el('span', 'be-grip');
        grip.innerHTML = '<i class="bi bi-grip-vertical"></i>';
        grip.title = 'Drag to reorder';
        grip.draggable = true;
        head.appendChild(grip);
        const meta = TYPES[block.type];
        const badge = el('span', 'badge text-bg-secondary');
        badge.innerHTML = '<i class="bi ' + (meta ? meta.icon : 'bi-puzzle') + ' me-1"></i>';
        badge.appendChild(document.createTextNode(meta ? meta.label : block.type));
        head.appendChild(badge);
        if (block.type === 'heading' && slotHeadings.includes(normalise(block.text))) {
            const slot = el('span', 'badge text-bg-info', 'Layout section');
            slot.title = 'This heading is used by the designed page layout — renaming it moves this section to generic rendering.';
            head.appendChild(slot);
        }
        // Resolve the block's index at click time — node reuse makes a
        // captured index stale after reorders.
        const idx = () => blocks.findIndex((b) => b._uid === block._uid);
        const body = blockBody(block, quills, markDirty);
        if (block._collapsed) {
            body.classList.add('d-none');
        }
        const tools = el('div', 'btn-group btn-group-sm ms-auto');
        const mkBtn = (icon, title, disabled, fn) => {
            const b = el('button', 'btn btn-outline-secondary');
            b.type = 'button';
            b.innerHTML = '<i class="bi ' + icon + '"></i>';
            b.title = title;
            b.disabled = disabled;
            b.addEventListener('click', fn);
            return b;
        };
        const collapse = mkBtn(block._collapsed ? 'bi-chevron-down' : 'bi-chevron-up', 'Collapse / expand', false, () => {
            block._collapsed = !block._collapsed;
            body.classList.toggle('d-none', block._collapsed);
            collapse.querySelector('i').className = 'bi ' + (block._collapsed ? 'bi-chevron-down' : 'bi-chevron-up');
        });
        tools.appendChild(mkBtn('bi-arrow-up', 'Move up', false, () => move(idx(), -1)));
        tools.appendChild(mkBtn('bi-arrow-down', 'Move down', false, () => move(idx(), 1)));
        tools.appendChild(mkBtn('bi-copy', 'Duplicate', false, () => { const copy = JSON.parse(JSON.stringify(block)); delete copy._uid; delete copy._media; delete copy._collapsed; insert(copy, idx() + 1); }));
        tools.appendChild(mkBtn('bi-trash', 'Delete', false, () => { if (confirm('Delete this block?')) { remove(idx()); } }));
        tools.appendChild(collapse);
        head.appendChild(tools);
        card.appendChild(head);
        card.appendChild(body);
        return card;
    }

    function activateQuills() {
        for (const [uid, entry] of quills) {
            if (entry.quill) {
                continue;
            }
            const quill = new Quill(entry.holder, {
                theme: 'snow',
                modules: { toolbar: [['bold', 'italic', 'underline'], [{ header: [2, 3, 4, false] }], [{ list: 'ordered' }, { list: 'bullet' }], ['blockquote', 'link'], ['clean']] },
            });
            if (entry.html) {
                quill.clipboard.dangerouslyPasteHTML(entry.html);
            }
            entry.quill = quill;
            const block = blocks.find((b) => b._uid === uid);
            if (block) {
                quill.on('text-change', () => { block.html = quill.getSemanticHTML(); markDirty(); });
            }
        }
    }

    function clearDropMarks() {
        blockList.querySelectorAll('.be-block').forEach((n) => {
            n.classList.remove('dragging', 'drop-above', 'drop-below');
            delete n.dataset.dropPos;
        });
    }

    // "+" inserter between blocks — opens the same block-type menu.
    function makeInserter(position) {
        const wrap = el('div', 'be-inserter');
        wrap.dataset.pos = position;
        const drop = el('div', 'dropdown');
        const btn = el('button', 'be-inserter-btn', '+');
        btn.type = 'button';
        btn.dataset.bsToggle = 'dropdown';
        btn.title = 'Insert block here';
        const menu = el('ul', 'dropdown-menu');
        Object.entries(TYPES).forEach(([type, meta]) => {
            const li = el('li');
            const a = el('button', 'dropdown-item');
            a.type = 'button';
            a.innerHTML = '<i class="bi ' + meta.icon + ' me-2"></i>';
            a.appendChild(document.createTextNode(meta.label));
            a.addEventListener('click', () => addBlock(type, position));
            li.appendChild(a);
            menu.appendChild(li);
        });
        drop.append(btn, menu);
        wrap.appendChild(drop);
        return wrap;
    }

    function render() {
        warn(blocks.length >= MAX_BLOCKS ? 'Maximum of ' + MAX_BLOCKS + ' blocks reached.' : '');
        const nodes = new Map();
        blockList.querySelectorAll('.be-block').forEach((n) => nodes.set(n.dataset.uid, n));
        blockList.textContent = '';
        blocks.forEach((block, i) => {
            blockList.appendChild(makeInserter(i));
            let node = nodes.get(String(block._uid));
            if (!node) {
                node = makeCard(block);
            }
            blockList.appendChild(node);
        });
        nodes.forEach((node, uid) => {
            if (!blocks.some((b) => String(b._uid) === uid)) {
                node.remove();
            }
        });
        if (blocks.length === 0) {
            const empty = el('div', 'text-body-secondary rounded p-4 text-center be-empty');
            empty.appendChild(el('div', 'fw-semibold mb-1', 'Start writing'));
            empty.appendChild(el('div', 'small', 'Use "Add block" below to place your first piece of content.'));
            blockList.appendChild(empty);
        }
        activateQuills();
        buildToolbar();
    }

    function move(index, dir) {
        const target = index + dir;
        if (index < 0 || target < 0 || target >= blocks.length) {
            return;
        }
        const [block] = blocks.splice(index, 1);
        blocks.splice(target, 0, block);
        markDirty();
        render();
    }

    function insert(data, index) {
        data._uid = 'b' + (++uidCounter);
        blocks.splice(index === undefined ? blocks.length : index, 0, data);
        markDirty();
        render();
    }

    function remove(index) {
        const [block] = blocks.splice(index, 1);
        quills.delete(block._uid);
        markDirty();
        render();
    }

    function addBlock(type, index) {
        const base = { type };
        if (type === 'text') { base.text = ''; }
        if (type === 'heading') { base.text = ''; }
        if (type === 'rich_text') { base.html = ''; }
        if (type === 'image') { base.media_id = 0; base.alt = ''; }
        if (type === 'document') { base.media_id = 0; base.label = ''; }
        if (type === 'quote') { base.text = ''; base.attribution = ''; }
        if (type === 'cta') { base.label = ''; base.url = ''; }
        if (type === 'statistic') { base.kind = 'context'; base.label = ''; base.value = ''; base.source = ''; base.period = ''; }
        if (type === 'cards' || type === 'accordion' || type === 'timeline') { base.items = [{ title: '', text: '' }]; }
        if (type === 'gallery') { base.items = [{ media_id: 0, alt: '' }]; }
        insert(base, index);
    }

    function buildToolbar() {
        toolbar.textContent = '';
        const group = el('div', 'dropdown');
        const btn = el('button', 'btn btn-primary btn-sm dropdown-toggle', 'Add block');
        btn.type = 'button';
        btn.dataset.bsToggle = 'dropdown';
        btn.disabled = blocks.length >= MAX_BLOCKS;
        const menu = el('ul', 'dropdown-menu');
        Object.entries(TYPES).forEach(([type, meta]) => {
            const li = el('li');
            const a = el('button', 'dropdown-item');
            a.type = 'button';
            a.innerHTML = '<i class="bi ' + meta.icon + ' me-2"></i>';
            a.appendChild(document.createTextNode(meta.label));
            a.title = meta.hint;
            a.addEventListener('click', () => addBlock(type));
            li.appendChild(a);
            menu.appendChild(li);
        });
        group.append(btn, menu);
        toolbar.appendChild(group);
    }

    function serialize() {
        if (jsonMode && jsonArea) {
            if (hidden) {
                hidden.value = jsonArea.value;
            }
            return;
        }
        const clean = blocks.map((block) => {
            const out = {};
            for (const [key, value] of Object.entries(block)) {
                if (key === '_uid' || key === '_media') {
                    continue;
                }
                out[key] = value;
            }
            const quill = quills.get(block._uid);
            if (quill && quill.quill && block.type === 'rich_text') {
                out.html = quill.quill.getSemanticHTML();
            }
            return out;
        });
        if (hidden) {
            hidden.value = JSON.stringify(clean);
        }
    }

    function loadJson(raw) {
        let parsed;
        try {
            parsed = JSON.parse(raw);
        } catch {
            warn('The JSON could not be parsed — check for syntax errors.');
            return false;
        }
        if (!Array.isArray(parsed)) {
            warn('Sections must be a JSON array.');
            return false;
        }
        quills.clear();
        blocks = parsed.filter((b) => b && typeof b === 'object' && typeof b.type === 'string').map((b) => ({ ...b, _uid: 'b' + (++uidCounter) }));
        if (blocks.length !== parsed.length) {
            warn(parsed.length - blocks.length + ' malformed block(s) were dropped.');
        }
        render();
        return true;
    }

    // JSON fallback panel: when open, the textarea becomes the source of truth.
    if (jsonPanel && jsonArea) {
        jsonPanel.addEventListener('toggle', () => {
            jsonMode = jsonPanel.open;
            if (jsonMode) {
                serialize();
                jsonArea.value = hidden ? hidden.value : '[]';
                mount.querySelector('.be-visual').classList.add('d-none');
            } else {
                mount.querySelector('.be-visual').classList.remove('d-none');
                loadJson(jsonArea.value);
            }
        });
    }

    // Dirty tracking: field edits bubble as input/change; structural
    // mutations (move/insert/remove/media pick) call markDirty directly.
    scope.addEventListener('input', markDirty);
    scope.addEventListener('change', markDirty);
    window.addEventListener('beforeunload', (e) => {
        if (dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Drag-and-drop reorder — the grip is the only draggable element.
    blockList.addEventListener('dragstart', (e) => {
        const card = e.target.closest('.be-block');
        if (!card) {
            return;
        }
        dragUid = card.dataset.uid;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', dragUid);
        card.classList.add('dragging');
    });
    blockList.addEventListener('dragover', (e) => {
        if (!dragUid) {
            return;
        }
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        clearDropMarks();
        const over = e.target.closest('.be-block');
        if (over && over.dataset.uid !== dragUid) {
            const rect = over.getBoundingClientRect();
            const before = e.clientY < rect.top + rect.height / 2;
            over.classList.add(before ? 'drop-above' : 'drop-below');
            over.dataset.dropPos = before ? 'before' : 'after';
        }
    });
    blockList.addEventListener('drop', (e) => {
        if (!dragUid) {
            return;
        }
        e.preventDefault();
        const from = blocks.findIndex((b) => String(b._uid) === dragUid);
        let to = -1;
        const over = e.target.closest('.be-block');
        if (over && over.dataset.uid !== dragUid) {
            to = blocks.findIndex((b) => String(b._uid) === over.dataset.uid);
            if (over.dataset.dropPos === 'after') {
                to += 1;
            }
        } else {
            const ins = e.target.closest('.be-inserter');
            if (ins) {
                to = parseInt(ins.dataset.pos, 10);
            }
        }
        if (from !== -1 && to !== -1) {
            const [moved] = blocks.splice(from, 1);
            if (to > from) {
                to -= 1;
            }
            blocks.splice(to, 0, moved);
            markDirty();
            render();
        }
        dragUid = null;
        clearDropMarks();
    });
    blockList.addEventListener('dragend', () => {
        dragUid = null;
        clearDropMarks();
    });

    if (form) {
        form.addEventListener('submit', () => { serialize(); clearDirty(); });
    }

    const visual = el('div', 'be-visual');
    visual.append(alertBox, blockList, toolbar);
    mount.prepend(visual);
    loadJson(hidden ? hidden.value : '[]');
}
