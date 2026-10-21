/*
 * Deprecated compatibility shim.
 *
 * The LinkedObject controller now ships in assets/admin/admin.js
 * (js/admin/modules/admin_panel/120_linkedobject.js). This file only exists for
 * old cached templates or custom templates that still include it directly.
 */
(function (window, document) {
    'use strict';

    function init() {
        if (window.MDLinkedObjectUI && typeof window.MDLinkedObjectUI.init === 'function') {
            window.MDLinkedObjectUI.init(document);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
