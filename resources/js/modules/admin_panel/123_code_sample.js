(function (window, document) {
    'use strict';

    /**
     * Read-only code samples (MDJUI::codeSample()).
     *
     * Tabs and copy live in the shell bundle so they work before the
     * highlighter chunk arrives. highlight.js is downloaded lazily only when
     * the page contains at least one sample.
     */
    var config = window.MDCodeHighlightConfig || {};
    var scriptUrl = config.script || ((window.ROOTHTML || '/') + 'assets/admin/codehighlight.js');
    var styleUrl = config.style || ((window.ROOTHTML || '/') + 'assets/admin/codehighlight.css');
    var loading = null;

    function getApi() {
        return window.MDCodeHighlight && typeof window.MDCodeHighlight.highlight === 'function'
            ? window.MDCodeHighlight
            : null;
    }

    function hasSamples(root) {
        return !!(root || document).querySelector('[data-md-code-sample]');
    }

    function load() {
        var api = getApi();
        if (api) {
            return Promise.resolve(api);
        }
        if (loading) {
            return loading;
        }

        loading = new Promise(function (resolve, reject) {
            if (!document.querySelector('[data-md-code-sample-asset="style"]')) {
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = styleUrl;
                link.setAttribute('data-md-code-sample-asset', 'style');
                document.head.appendChild(link);
            }

            var script = document.createElement('script');
            script.src = scriptUrl;
            script.async = true;
            script.setAttribute('data-md-code-sample-asset', 'script');
            script.onload = function () {
                var loadedApi = getApi();
                if (loadedApi) {
                    resolve(loadedApi);
                } else {
                    loading = null;
                    reject(new Error('Code sample highlighter is not available'));
                }
            };
            script.onerror = function () {
                loading = null;
                reject(new Error('Failed to load the code sample highlighter'));
            };
            document.head.appendChild(script);
        });

        return loading;
    }

    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.top = '0';
        textarea.style.left = '-9999px';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
        } catch (error) {
            // Clipboard is unavailable; the button still gives feedback.
        }
        document.body.removeChild(textarea);
        return Promise.resolve();
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).catch(function () {
                return fallbackCopy(text);
            });
        }
        return fallbackCopy(text);
    }

    function activePanel(root) {
        return root.querySelector('[data-md-code-sample-panel]:not([hidden])')
            || root.querySelector('[data-md-code-sample-panel]');
    }

    function setCopyFeedback(button, copied) {
        var label = button.querySelector('[data-md-code-sample-copy-label]');
        var icon = button.querySelector('i');
        if (!button.dataset.mdCopyLabel) {
            button.dataset.mdCopyLabel = label ? label.textContent : 'Копировать';
        }
        if (copied) {
            button.classList.add('is-copied');
            button.setAttribute('aria-label', 'Скопировано');
            if (label) {
                label.textContent = 'Скопировано';
            }
            if (icon) {
                icon.className = 'ti ti-check';
            }
        } else {
            button.classList.remove('is-copied');
            button.setAttribute('aria-label', 'Скопировать код');
            if (label) {
                label.textContent = button.dataset.mdCopyLabel;
            }
            if (icon) {
                icon.className = 'ti ti-copy';
            }
        }
    }

    function bindCopy(root) {
        Array.prototype.slice.call(root.querySelectorAll('[data-md-code-sample-copy]')).forEach(function (button) {
            if (button.dataset.mdCopyReady === '1') {
                return;
            }
            button.dataset.mdCopyReady = '1';
            button.addEventListener('click', function () {
                var sample = button.closest('[data-md-code-sample]');
                var panel = sample ? activePanel(sample) : null;
                var code = panel ? panel.querySelector('code') : null;
                if (!code) {
                    return;
                }
                var text = code.textContent || '';
                copyText(text).then(function () {
                    setCopyFeedback(button, true);
                    window.setTimeout(function () {
                        setCopyFeedback(button, false);
                    }, 1800);
                });
            });
        });
    }

    function activateTab(root, index) {
        var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-md-code-sample-tab]'));
        var panels = Array.prototype.slice.call(root.querySelectorAll('[data-md-code-sample-panel]'));
        tabs.forEach(function (tab) {
            var active = tab.getAttribute('data-md-code-sample-tab') === String(index);
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
        });
        panels.forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-md-code-sample-panel') !== String(index);
        });
    }

    function bindTabs(root) {
        Array.prototype.slice.call(root.querySelectorAll('[data-md-code-sample]')).forEach(function (sample) {
            if (sample.dataset.mdTabsReady === '1') {
                return;
            }
            var tabs = Array.prototype.slice.call(sample.querySelectorAll('[data-md-code-sample-tab]'));
            if (tabs.length < 2) {
                return;
            }
            sample.dataset.mdTabsReady = '1';

            tabs.forEach(function (tab, tabIndex) {
                tab.addEventListener('click', function () {
                    activateTab(sample, tab.getAttribute('data-md-code-sample-tab'));
                });
                tab.addEventListener('keydown', function (event) {
                    var next = null;
                    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                        next = (tabIndex + 1) % tabs.length;
                    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                        next = (tabIndex - 1 + tabs.length) % tabs.length;
                    } else if (event.key === 'Home') {
                        next = 0;
                    } else if (event.key === 'End') {
                        next = tabs.length - 1;
                    }
                    if (next === null) {
                        return;
                    }
                    event.preventDefault();
                    activateTab(sample, tabs[next].getAttribute('data-md-code-sample-tab'));
                    tabs[next].focus();
                });
            });
        });
    }

    function highlight(root) {
        if (!hasSamples(root)) {
            return;
        }
        load().then(function (api) {
            api.highlight(root);
        }).catch(function (error) {
            if (window.console && window.console.warn) {
                window.console.warn(error);
            }
        });
    }

    function init(root) {
        var scope = root || document;
        bindTabs(scope);
        bindCopy(scope);
        highlight(scope);
    }

    window.MDCodeSample = {
        init: init,
        load: load
    };

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('code-sample', {
            init: init
        });
    } else {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () {
                init(document);
            });
        } else {
            init(document);
        }
    }

    document.addEventListener('md:part-load', function (event) {
        var root = event && event.detail && event.detail.root ? event.detail.root : document;
        init(root);
    });
})(window, document);
