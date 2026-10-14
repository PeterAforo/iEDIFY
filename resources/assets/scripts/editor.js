import 'quill/dist/quill.snow.css';
import { initBlockEditor } from './block-editor.js';
import { bindMediaPickers } from './media-picker.js';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-block-editor]').forEach(initBlockEditor);
    bindMediaPickers();
});
