/*
 * MajorDoMo admin bundle.
 *
 * This file is the single entry point for the admin shell. It loads the
 * vendor libraries once and then the shell modules. Page-specific legacy
 * scripts (scripts.js, inline panel helpers) are still loaded as classic
 * scripts until they are migrated.
 */

import './globals.js';

import 'jquery-migrate';
import * as bootstrap from 'bootstrap';
import Alpine from 'alpinejs';
import 'jquery-toast-plugin';
import '@fancyapps/fancybox';
import HtmlDurationPickerModule from 'html-duration-picker';
import TomSelectModule from 'tom-select';

import '../../js/jquery.cookie.js';
import '../../js/jquery.tiny-pubsub.js';

const HtmlDurationPicker = HtmlDurationPickerModule && HtmlDurationPickerModule.default
    ? HtmlDurationPickerModule.default
    : HtmlDurationPickerModule;
const TomSelect = TomSelectModule && TomSelectModule.default
    ? TomSelectModule.default
    : TomSelectModule;

window.bootstrap = bootstrap;
window.Alpine = Alpine;
window.HtmlDurationPicker = HtmlDurationPicker;
window.TomSelect = TomSelect;

/* Styles: order matters — Bootstrap first, project and legacy layers after it. */
import 'bootstrap/dist/css/bootstrap.min.css';
import 'bootstrap-icons/font/bootstrap-icons.css';
import '@tabler/icons-webfont/dist/tabler-icons.min.css';
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';
import 'jquery-toast-plugin/dist/jquery.toast.min.css';
import '@fancyapps/fancybox/dist/jquery.fancybox.min.css';
import '../../3rdparty/fonts/rubik/rubik.css';
import '../../css/admin/admin_panel.css';
import '../../css/admin/ui-v2.css';
import '../../css/admin/ui-system.css';

/* Shell modules. Keep the order: runtime -> core -> features -> init -> pages. */
import '../../js/admin/modules/admin_panel/00_runtime.js';
import '../../js/admin/modules/admin_panel/10_core.js';
import '../../js/admin/modules/admin_panel/20_drawer.js';
import '../../js/admin/modules/admin_panel/30_search.js';
import '../../js/admin/modules/admin_panel/40_history.js';
import '../../js/admin/modules/admin_panel/50_console.js';
import '../../js/admin/modules/admin_panel/60_notifications.js';
import '../../js/admin/modules/admin_panel/99_init.js';
import '../../js/admin/modules/admin_panel/70_controls.js';
import '../../js/admin/modules/admin_panel/80_header_search.js';
import '../../js/admin/modules/admin_panel/100_dashboard.js';
import '../../js/admin/modules/admin_panel/100_chart_runtime.js';
import '../../js/admin/modules/admin_panel/101_dashboard_charts.js';
import '../../js/admin/modules/admin_panel/110_objects.js';
import '../../js/admin/modules/admin_panel/115_alpine.js';
import '../../js/admin/modules/admin_panel/118_data_table.js';
import '../../js/admin/modules/admin_panel/119_cycle_widget.js';
import '../../js/admin/modules/admin_panel/116_ui_components.js';
import '../../js/admin/modules/admin_panel/120_linkedobject.js';
import '../../js/admin/codeeditor-loader.js';


