/*
 * Alpine.js runtime for the admin.
 *
 * Alpine is started once, after the admin shell has booted. On partLoad the
 * new content is passed to Alpine.initTree() so x-data components keep
 * working without a full page reload.
 */
import Alpine from 'alpinejs';

window.Alpine = Alpine;

(function (window, document) {
    'use strict';

    var started = false;

    function start() {
        if (started || !Alpine) {
            return;
        }
        started = true;
        Alpine.start();
    }

    function boot(root) {
        if (!Alpine) {
            return;
        }
        if (!started) {
            start();
            return;
        }
        if (root && root.nodeType === 1) {
            Alpine.initTree(root);
        }
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('alpine', { init: boot });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})(window, document);
