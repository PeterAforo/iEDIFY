// Content list live-preview pane: clicking a row loads the signed
// /preview/{id}/{token} draft render into the split-pane iframe.
// Progressive enhancement — without JS the list links work normally.
export function bindContentPreview() {
    const pane = document.querySelector('[data-desk-preview]');
    if (!pane) {
        return;
    }

    const frame = pane.querySelector('[data-desk-preview-frame]');
    const empty = pane.querySelector('[data-desk-preview-empty]');
    const title = pane.querySelector('[data-desk-preview-title]');
    const edit = pane.querySelector('[data-desk-preview-edit]');
    const open = pane.querySelector('[data-desk-preview-open]');
    const close = pane.querySelector('[data-desk-preview-close]');
    const wideEnough = () => globalThis.matchMedia('(min-width: 1200px)').matches;

    const reset = () => {
        frame.hidden = true;
        frame.src = 'about:blank';
        empty.hidden = false;
        title.textContent = 'Select a page';
        [edit, open, close].forEach((el) => { el.hidden = true; });
        document.querySelectorAll('.desk-preview-row.is-active').forEach((row) => row.classList.remove('is-active'));
    };

    const select = (row) => {
        const url = row.dataset.previewUrl;
        if (!url || !wideEnough()) {
            return;
        }
        frame.src = url;
        frame.hidden = false;
        empty.hidden = true;
        title.textContent = row.dataset.previewTitle || 'Untitled';
        edit.href = '/admin/content/' + row.dataset.previewId;
        open.href = url;
        [edit, open, close].forEach((el) => { el.hidden = false; });
        document.querySelectorAll('.desk-preview-row').forEach((r) => r.classList.toggle('is-active', r === row));
    };

    document.querySelectorAll('.desk-preview-row').forEach((row) => {
        row.addEventListener('click', (event) => {
            // Links and buttons inside the row keep their own behaviour.
            if (event.target instanceof Element && event.target.closest('a, button, input, select, textarea, label')) {
                return;
            }
            select(row);
        });
        row.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && event.target === row) {
                event.preventDefault();
                select(row);
            }
        });
    });

    close.addEventListener('click', reset);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !frame.hidden) {
            reset();
        }
    });
}
