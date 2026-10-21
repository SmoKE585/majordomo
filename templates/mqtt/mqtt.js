/*
 * MQTT workspace: sidebar, settings toggles, publish panel and live values.
 * Server rendering owns the data; this file only handles local interactions.
 */
(function (window, document) {
    'use strict';

    function initSidebar(scope) {
        scope.querySelectorAll('[data-md-mqtt-sidebar]').forEach(function (sidebar) {
            var toggle = sidebar.querySelector('[data-md-mqtt-sidebar-toggle]');
            if (!toggle || toggle.dataset.mdMqttReady === '1') {
                return;
            }
            toggle.dataset.mdMqttReady = '1';
            toggle.addEventListener('click', function () {
                var isOpen = sidebar.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        });
    }

    function initGroupSearch(scope) {
        var input = scope.querySelector('[data-md-mqtt-group-search]');
        var list = scope.querySelector('[data-md-mqtt-group-list]');
        if (!input || !list || input.dataset.mdMqttReady === '1') {
            return;
        }
        input.dataset.mdMqttReady = '1';

        var empty = list.querySelector('[data-md-mqtt-group-empty]');
        var groups = Array.prototype.slice.call(list.querySelectorAll('[data-md-mqtt-group]'));

        function filter() {
            var query = input.value.trim().toLowerCase();
            var visible = 0;
            groups.forEach(function (group) {
                var text = (group.textContent || '').toLowerCase();
                var show = query === '' || text.indexOf(query) !== -1;
                group.hidden = !show;
                if (show) {
                    visible++;
                }
            });
            if (empty) {
                empty.hidden = visible !== 0;
            }
        }

        input.addEventListener('input', filter);
        filter();
    }

    function initSettings(scope) {
        var auth = scope.querySelector('[data-md-mqtt-auth]');
        var authPanel = scope.querySelector('[data-md-mqtt-auth-panel]');
        if (auth && authPanel) {
            var syncAuth = function () {
                authPanel.hidden = !auth.checked;
            };
            if (auth.dataset.mdMqttReady !== '1') {
                auth.dataset.mdMqttReady = '1';
                auth.addEventListener('change', syncAuth);
            }
            syncAuth();
        }

        var tls = scope.querySelector('[data-md-mqtt-tls]');
        var tlsPanel = scope.querySelector('[data-md-mqtt-tls-panel]');
        if (tls && tlsPanel) {
            var syncTls = function () {
                tlsPanel.hidden = !tls.checked;
            };
            if (tls.dataset.mdMqttReady !== '1') {
                tls.dataset.mdMqttReady = '1';
                tls.addEventListener('change', syncTls);
            }
            syncTls();
        }

        scope.querySelectorAll('[data-md-mqtt-password-toggle]').forEach(function (button) {
            if (button.dataset.mdMqttReady === '1') {
                return;
            }
            button.dataset.mdMqttReady = '1';
            button.addEventListener('click', function () {
                var input = button.parentNode.querySelector('[data-md-mqtt-password]');
                if (!input) {
                    return;
                }
                var reveal = input.type === 'password';
                input.type = reveal ? 'text' : 'password';
                button.setAttribute('aria-label', reveal ? 'Скрыть пароль' : 'Показать пароль');
                button.setAttribute('title', reveal ? 'Скрыть пароль' : 'Показать пароль');
                var icon = button.querySelector('i');
                if (icon) {
                    icon.className = reveal ? 'ti ti-eye-off' : 'ti ti-eye';
                }
            });
        });
    }

    function initEdit(scope) {
        var publishToggle = scope.querySelector('[data-md-mqtt-publish-toggle]');
        var publishPanel = scope.querySelector('[data-md-mqtt-publish-panel]');
        if (publishToggle && publishPanel) {
            var syncPublish = function () {
                publishPanel.hidden = !publishToggle.checked;
            };
            if (publishToggle.dataset.mdMqttReady !== '1') {
                publishToggle.dataset.mdMqttReady = '1';
                publishToggle.addEventListener('change', function () {
                    syncPublish();
                    if (publishToggle.checked) {
                        var input = publishPanel.querySelector('input[name="new_value"]');
                        if (input) {
                            input.focus();
                        }
                    }
                });
            }
            syncPublish();
        }

        var replaceInput = scope.querySelector('#mdMqttReplace');
        if (replaceInput) {
            scope.querySelectorAll('[data-md-mqtt-replace-value]').forEach(function (button) {
                if (button.dataset.mdMqttReady === '1') {
                    return;
                }
                button.dataset.mdMqttReady = '1';
                button.addEventListener('click', function () {
                    replaceInput.value = button.getAttribute('data-md-mqtt-replace-value') || '';
                    replaceInput.focus();
                    replaceInput.dispatchEvent(new Event('input', { bubbles: true }));
                    replaceInput.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });
        }
    }

    function initLive(scope) {
        var button = scope.querySelector('[data-md-mqtt-live]');
        var values = scope.querySelectorAll('[data-md-mqtt-value]');
        if (!button || !values.length || !window.fetch) {
            return;
        }

        var state = window.MD_MqttLive || { timer: null, enabled: true };
        window.MD_MqttLive = state;
        if (state.timer) {
            window.clearTimeout(state.timer);
            state.timer = null;
        }
        state.enabled = !button.classList.contains('is-paused');

        function setButtonState() {
            button.classList.toggle('is-active', state.enabled);
            button.classList.toggle('is-paused', !state.enabled);
            button.setAttribute('aria-pressed', state.enabled ? 'true' : 'false');
        }

        function schedule() {
            if (state.timer) {
                window.clearTimeout(state.timer);
            }
            if (!state.enabled) {
                return;
            }
            state.timer = window.setTimeout(poll, 5000);
        }

        function collectIds() {
            var ids = [];
            values.forEach(function (node) {
                var id = node.getAttribute('data-md-mqtt-value');
                if (id && ids.indexOf(id) === -1) {
                    ids.push(id);
                }
            });
            return ids;
        }

        function flash(node) {
            node.classList.remove('is-changed');
            // Force a reflow so the animation restarts when the value changes again.
            void node.offsetWidth;
            node.classList.add('is-changed');
            window.setTimeout(function () {
                node.classList.remove('is-changed');
            }, 900);
        }

        function poll() {
            state.timer = null;
            if (!state.enabled) {
                return;
            }
            if (document.hidden) {
                schedule();
                return;
            }

            var ids = collectIds();
            if (!ids.length) {
                schedule();
                return;
            }

            var params = new URLSearchParams();
            params.set('ajax', '1');
            params.set('op', 'values');
            ids.forEach(function (id) {
                params.append('ids[]', id);
            });

            fetch(window.location.pathname + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('MQTT values request failed');
                    }
                    return response.json();
                })
                .then(function (payload) {
                    (payload.DATA || []).forEach(function (item) {
                        var id = String(item.ID);
                        scope.querySelectorAll('[data-md-mqtt-value="' + id + '"]').forEach(function (node) {
                            var value = item.VALUE === undefined || item.VALUE === null || item.VALUE === '' ? '—' : String(item.VALUE);
                            var previous = node.textContent;
                            if (previous !== value) {
                                node.textContent = value;
                                if (item.VALUE) {
                                    node.setAttribute('title', String(item.VALUE).slice(0, 600));
                                }
                                flash(node);
                            }
                        });
                        scope.querySelectorAll('[data-md-mqtt-updated="' + id + '"]').forEach(function (node) {
                            node.textContent = item.UPDATED_HUMAN || '';
                        });
                    });
                })
                .catch(function () {
                    // Keep the last rendered values; the next tick retries.
                })
                .finally(schedule);
        }

        if (button.dataset.mdMqttReady !== '1') {
            button.dataset.mdMqttReady = '1';
            button.addEventListener('click', function () {
                state.enabled = !state.enabled;
                setButtonState();
                if (state.enabled) {
                    poll();
                } else if (state.timer) {
                    window.clearTimeout(state.timer);
                    state.timer = null;
                }
            });
        }

        setButtonState();
        poll();
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        initSidebar(scope);
        initGroupSearch(scope);
        initSettings(scope);
        initEdit(scope);
        initLive(scope);
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('mqtt', { init: init });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window, document);
