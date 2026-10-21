(function (window, document) {
    'use strict';

    var admin = window.MDJAdminPanel = window.MDJAdminPanel || {};
    var state = admin.state = admin.state || {};

    function getModuleUIs() {
        return state.moduleUIs || {};
    }

    window.MDJAdminUI = window.MDJAdminUI || {};
    window.MDJAdminUI.boot = admin.boot;
    window.MDJAdminUI.copyLegacyBootstrapAttributes = admin.copyLegacyBootstrapAttributes;
    window.MDJAdminUI.initBootstrapWidgets = admin.initBootstrapWidgets;
    window.MDJAdminUI.installJqueryBridge = admin.installJqueryBridge;
    window.MDJAdminUI.ensureActiveModuleTabsVisible = admin.ensureActiveModuleTabsVisible;
    window.MDJAdminUI.registerModuleUI = function (name, moduleUI) {
        if (!name || !moduleUI) {
            return;
        }
        getModuleUIs()[name] = moduleUI;
        if (window.MDJAdminLastBootRoot && typeof moduleUI.init === 'function') {
            moduleUI.init(window.MDJAdminLastBootRoot);
        }
    };
    window.MDJAdminUI.unregisterModuleUI = function (name) {
        if (name && getModuleUIs()[name]) {
            delete getModuleUIs()[name];
        }
    };
    window.MDJAdminUI.getBootRoot = function () {
        return admin.getBootRoot ? admin.getBootRoot() : (window.MDJAdminLastBootRoot || document);
    };
    window.MDJAdminUI.openSearch = function (value) {
        if (window.MDJAdminSearch) {
            window.MDJAdminSearch.open(value);
        }
    };
    window.MDJAdminUI.closeSearch = function () {
        if (window.MDJAdminSearch) {
            window.MDJAdminSearch.close();
        }
    };
    window.MDJAdminUI.openDrawer = function (options) {
        if (window.MDJAdminDrawerHost) {
            return window.MDJAdminDrawerHost.open(options);
        }
        return false;
    };
    window.MDJAdminUI.closeDrawer = function (owner) {
        if (window.MDJAdminDrawerHost) {
            return window.MDJAdminDrawerHost.close(owner);
        }
        return false;
    };
    window.MDJAdminUI.openConsole = function () {
        if (window.MDJAdminConsole) {
            window.MDJAdminConsole.open();
        }
    };

    function onDomReady() {
        if (state.domReadyDone) {
            return;
        }
        state.domReadyDone = true;
        admin.applyThemeFromCookie();
        admin.boot(document);
        admin.initHeaderDateTime();
        admin.installJqueryBridge();
        admin.initAdminSidebarDrawer();
        admin.scrollSidebarToActiveItem();
    }

    if (!state.domReadyBound) {
        state.domReadyBound = true;
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', onDomReady);
        } else {
            onDomReady();
        }
    }
})(window, document);
