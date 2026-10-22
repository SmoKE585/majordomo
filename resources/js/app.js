/*
 * 2026-10-22 — удалён неиспользуемый выбор длительности из общего бандла.
 *
 * MajorDoMo admin bundle.
 *
 * This file is the single entry point for the admin shell. It loads the
 * vendor libraries once and then the shell modules. Page-specific legacy
 * scripts (scripts.js, inline panel helpers) are still loaded as classic
 * scripts until they are migrated.
 *
 * Layout:
 *   resources/js/app.js        - this entry (admin.js)
 *   resources/js/bootstrap.js  - jQuery globals, must run first
 *   resources/js/modules/      - shell modules
 *   resources/js/codeeditor/   - lazy editor chunk (codeeditor.js)
 *   resources/css/             - stylesheets
 */

import './bootstrap.js';

import 'jquery-migrate';
import * as bootstrap from 'bootstrap';
import Alpine from 'alpinejs';
import '@fancyapps/fancybox';
import TomSelectModule from 'tom-select';

import '../../public/assets/js/jquery.cookie.js';
import '../../public/assets/js/jquery.tiny-pubsub.js';

const TomSelect = TomSelectModule && TomSelectModule.default
    ? TomSelectModule.default
    : TomSelectModule;

window.bootstrap = bootstrap;
window.Alpine = Alpine;
window.TomSelect = TomSelect;

/* Styles: order matters — Bootstrap first, project and legacy layers after it. */
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '@tabler/icons-webfont/dist/tabler-icons.min.css';
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';
import '@fancyapps/fancybox/dist/jquery.fancybox.min.css';
import '../../public/assets/fonts/rubik/rubik.css';
import '../css/admin_panel.css';
import '../css/ui-v2.css';
import '../css/ui-system.css';

/* Shell modules. Keep the order: runtime -> core -> features -> init -> pages. */
import './modules/admin_panel/00_runtime.js';
import './modules/admin_panel/05_auth.js';
import './modules/admin_panel/07_api.js';
import './modules/admin_panel/06_websockets.js';
import './modules/admin_panel/10_core.js';
import './modules/admin_panel/20_drawer.js';
import './modules/admin_panel/30_search.js';
import './modules/admin_panel/40_history.js';
import './modules/admin_panel/41_trace.js';
import './modules/admin_panel/50_console.js';
import './modules/admin_panel/60_notifications.js';
import './modules/admin_panel/99_init.js';
import './modules/admin_panel/70_controls.js';
import './modules/admin_panel/80_header_search.js';
import './modules/admin_panel/100_dashboard.js';
import './modules/admin_panel/100_chart_runtime.js';
import './modules/admin_panel/101_dashboard_charts.js';
import './modules/admin_panel/110_objects.js';
import './modules/admin_panel/115_alpine.js';
import './modules/admin_panel/118_data_table.js';
import './modules/admin_panel/119_cycle_widget.js';
import './modules/admin_panel/121_table_dropdowns.js';
import './modules/admin_panel/122_folder_picker.js';
import './modules/admin_panel/116_ui_components.js';
import './modules/admin_panel/120_linkedobject.js';
import './modules/admin_panel/123_code_sample.js';
import './codeeditor/loader.js';
