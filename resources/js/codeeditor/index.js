/*
 * Separate Vite entry for the admin code editor.
 *
 * Built to assets/admin/codeeditor.js + codeeditor.css and loaded on demand
 * by resources/js/codeeditor/loader.js (bundled into admin.js).
 */
import '../../css/codeeditor.css';
import './editor.js';
