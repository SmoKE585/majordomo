/*
 * Lazy loader for the admin code editor.
 *
 * The editor itself (CodeMirror 6) is built as a separate `codeeditor.js`
 * chunk and is fetched only when a page actually contains an editor. This
 * keeps dashboards and other non-code pages free from the parser bundle.
 */
(function (window, document) {
    'use strict';

    var config = window.MDCodeEditorConfig || {};
    var scriptUrl = config.script || ((window.ROOTHTML || '/') + 'assets/admin/codeeditor.js');
    var styleUrl = config.style || ((window.ROOTHTML || '/') + 'assets/admin/codeeditor.css');
    var loading = null;
    var loaded = false;
    var queue = [];

    function getApi() {
        return window.MDCodeEditor && typeof window.MDCodeEditor.init === 'function'
            ? window.MDCodeEditor
            : null;
    }

    function hasEditor(root) {
        return Array.prototype.slice.call((root || document).querySelectorAll('[data-code-editor]')).some(function (wrapper) {
            return wrapper.dataset.codeEditorLazy !== '1';
        });
    }

    function markLoading(root) {
        Array.prototype.slice.call((root || document).querySelectorAll('[data-code-editor]')).forEach(function (wrapper) {
            if (wrapper.dataset.codeEditorLazy === '1' || wrapper.dataset.codeEditorReady === '1') {
                return;
            }
            wrapper.classList.add('is-loading');
        });
    }

    function load() {
        if (loaded) {
            return Promise.resolve(getApi());
        }
        if (loading) {
            return loading;
        }

        loading = new Promise(function (resolve, reject) {
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = styleUrl;
            link.setAttribute('data-md-code-editor-asset', 'style');
            document.head.appendChild(link);

            var script = document.createElement('script');
            script.src = scriptUrl;
            script.async = true;
            script.setAttribute('data-md-code-editor-asset', 'script');
            script.onload = function () {
                loaded = true;
                var api = getApi();
                if (api) {
                    queue.forEach(function (root) {
                        try {
                            api.init(root);
                        } catch (error) {
                            if (window.console && window.console.warn) {
                                window.console.warn(error);
                            }
                        }
                    });
                }
                queue = [];
                resolve(api);
            };
            script.onerror = function () {
                loading = null;
                reject(new Error('Failed to load the code editor'));
            };
            document.head.appendChild(script);
        });

        return loading;
    }

    function init(root) {
        var api = getApi();
        if (loaded && api) {
            return api.init(root);
        }
        markLoading(root);
        if (root) {
            queue.push(root);
        }
        return load();
    }

    window.MDCodeEditor = {
        load: load,
        init: init,
        isLoaded: function () {
            return loaded;
        }
    };

    function boot(root) {
        if (!hasEditor(root)) {
            return;
        }
        markLoading(root);
        load().then(function (api) {
            if (api) {
                api.init(root);
            }
        }).catch(function (error) {
            Array.prototype.slice.call((root || document).querySelectorAll('[data-code-editor].is-loading')).forEach(function (wrapper) {
                wrapper.classList.remove('is-loading');
            });
            if (window.console && window.console.warn) {
                window.console.warn(error);
            }
        });
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('codeeditor-loader', {
            init: boot
        });
    } else {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                boot(document);
            });
        } else {
            boot(document);
        }
    }

    document.addEventListener('md:part-load', function (event) {
        var root = event && event.detail && event.detail.root ? event.detail.root : document;
        boot(root);
    });
})(window, document);
