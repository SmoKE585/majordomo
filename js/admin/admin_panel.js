(function (window, document) {
    'use strict';

    var moduleFiles = [
        '00_runtime.js',
        '10_core.js',
        '20_drawer.js',
        '30_search.js',
        '40_history.js',
        '50_console.js',
        '60_notifications.js',
        '99_init.js'
    ];

    if (window.__mdAdminPanelModulesLoaded) {
        return;
    }
    window.__mdAdminPanelModulesLoaded = true;

    function getBasePath() {
        var current = document.currentScript;
        var source = current && current.src ? current.src : '';

        if (!source) {
            var scripts = document.getElementsByTagName('script');
            var lastScript = scripts.length ? scripts[scripts.length - 1] : null;
            source = lastScript && lastScript.src ? lastScript.src : '';
        }

        return source ? source.replace(/[^\/\\?#]+(?:[?#].*)?$/, '') : '';
    }

    var basePath = getBasePath();

    function buildModuleUrl(fileName) {
        return basePath + 'modules/admin_panel/' + fileName;
    }

    function writeScriptsSynchronously() {
        document.write(moduleFiles.map(function (fileName) {
            return '<script src="' + buildModuleUrl(fileName) + '"><\/script>';
        }).join(''));
    }

    function loadSequentially(index) {
        if (index >= moduleFiles.length) {
            return;
        }

        var script = document.createElement('script');
        script.src = buildModuleUrl(moduleFiles[index]);
        script.async = false;
        script.onload = function () {
            loadSequentially(index + 1);
        };
        document.head.appendChild(script);
    }

    if (document.readyState === 'loading' && document.currentScript) {
        writeScriptsSynchronously();
        return;
    }

    loadSequentially(0);
})(window, document);
