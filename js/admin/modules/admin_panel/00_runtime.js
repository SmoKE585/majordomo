(function (window, document) {
    'use strict';

    var admin = window.MDJAdminPanel = window.MDJAdminPanel || {};
    var state = admin.state = admin.state || {};

    state.bootTasks = state.bootTasks || [];
    state.bootTaskNames = state.bootTaskNames || {};
    state.moduleUIs = state.moduleUIs || {};
    state.bootRoot = state.bootRoot || null;
    state.domReadyBound = !!state.domReadyBound;
    state.domReadyDone = !!state.domReadyDone;

    admin.addBootTask = function (name, task) {
        if (!name || typeof task !== 'function' || state.bootTaskNames[name]) {
            return;
        }
        state.bootTaskNames[name] = true;
        state.bootTasks.push(task);
    };

    admin.getBootTasks = function () {
        return state.bootTasks.slice();
    };

    admin.getModuleUIs = function () {
        return state.moduleUIs;
    };

    admin.setBootRoot = function (root) {
        state.bootRoot = root || document;
    };

    admin.getBootRoot = function () {
        return state.bootRoot || document;
    };
})(window, document);
