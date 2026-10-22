/**
 * Клиент служебного API панели (/api/v1/panel).
 *
 * 2026-10-22 — единая точка для fetch-запросов админки: дашборд, поиск,
 * консоль, уведомления, WebSocket-билет и X-Ray. Старые admin.php?ajax_panel=1
 * адреса остаются для совместимости, но новый код ходит сюда.
 */
(function (window) {
    'use strict';

    function rootHtml() {
        var root = window.ROOTHTML || '/';
        return root.charAt(root.length - 1) === '/' ? root : root + '/';
    }

    function baseUrl() {
        return rootHtml() + 'api/v1/panel';
    }

    function encode(value) {
        return encodeURIComponent(String(value));
    }

    function buildUrl(path, query) {
        var url = baseUrl() + (path.charAt(0) === '/' ? path : '/' + path);
        if (!query) {
            return url;
        }
        var parts = [];
        Object.keys(query).forEach(function (key) {
            var value = query[key];
            if (value === null || typeof value === 'undefined' || value === '') {
                return;
            }
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    if (item !== null && typeof item !== 'undefined' && item !== '') {
                        parts.push(encode(key) + '=' + encode(item));
                    }
                });
                return;
            }
            parts.push(encode(key) + '=' + encode(value));
        });
        return parts.length ? url + '?' + parts.join('&') : url;
    }

    function apiError(message, status, code) {
        var error = new Error(message || 'Ошибка запроса');
        error.status = status || 0;
        error.code = code || 'request_failed';
        return error;
    }

    function parsePayload(text) {
        if (!text) {
            return null;
        }
        try {
            return JSON.parse(text);
        } catch (error) {
            return null;
        }
    }

    /**
     * Общий запрос. Возвращает уже развёрнутое поле data из конверта v1.
     * @param {string} path
     * @param {{method?:string, query?:object, body?:object, signal?:AbortSignal, headers?:object}} options
     */
    function request(path, options) {
        options = options || {};
        var method = String(options.method || 'GET').toUpperCase();
        var headers = {
            Accept: 'application/json'
        };
        if (method !== 'GET' && method !== 'HEAD') {
            headers['Content-Type'] = 'application/json';
        }
        if (options.headers) {
            Object.keys(options.headers).forEach(function (name) {
                headers[name] = options.headers[name];
            });
        }
        var init = {
            method: method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: headers,
            signal: options.signal
        };
        if (method !== 'GET' && method !== 'HEAD') {
            init.body = JSON.stringify(options.body || {});
        }
        return fetch(buildUrl(path, options.query), init).then(function (response) {
            return response.text().then(function (text) {
                var payload = parsePayload(text);
                if (!response.ok || (payload && payload.ok === false)) {
                    var error = payload && payload.error ? payload.error : {};
                    throw apiError(error.message || ('HTTP ' + response.status), response.status, error.code);
                }
                return payload ? payload.data : null;
            });
        });
    }

    function get(path, query, signal) {
        return request(path, {method: 'GET', query: query, signal: signal});
    }

    function post(path, body, signal) {
        return request(path, {method: 'POST', body: body || {}, signal: signal});
    }

    function remove(path, body, signal) {
        return request(path, {method: 'DELETE', body: body || {}, signal: signal});
    }

    var api = {
        baseUrl: baseUrl,
        request: request,
        get: get,
        post: post,
        delete: remove,
        dashboard: function (fresh) {
            return get('/dashboard', {fresh: fresh ? '1' : ''});
        },
        search: function (term, options) {
            options = options || {};
            return get('/search', {q: term, limit: options.limit || ''}, options.signal);
        },
        console: function (command) {
            return post('/console', {command: String(command || '')}).then(function (data) {
                return data && typeof data.output !== 'undefined' ? data.output : '';
            });
        },
        websocketTicket: function () {
            return get('/websocket-ticket');
        },
        dismissNotification: function (id) {
            return post('/notifications/dismiss', {id: id});
        },
        dismissModuleNotifications: function (module) {
            return post('/notifications/dismiss', {module: module});
        },
        propertyHistory: function (objectId, propertyId, range, page, signal) {
            return get('/objects/' + encode(objectId) + '/properties/' + encode(propertyId) + '/history', {
                range: range,
                page: page
            }, signal);
        },
        propertyTrace: function (objectId, propertyId, range, page, signal) {
            return get('/objects/' + encode(objectId) + '/properties/' + encode(propertyId) + '/trace', {
                range: range,
                page: page
            }, signal);
        },
        updates: function (force) {
            return get('/updates', {force: force ? '1' : ''});
        },
        xray: {
            content: function (params) {
                return get('/xray/content', params || {});
            },
            services: function (mode) {
                return get('/xray/services', {mode: mode || ''});
            },
            service: function (cycle, signal) {
                return get('/xray/services/' + encode(cycle), null, signal);
            },
            command: function (cycle, command, signal) {
                return post('/xray/services/' + encode(cycle) + '/' + encode(command), {}, signal);
            },
            cycleLog: function (cycle, signal) {
                return get('/xray/services/' + encode(cycle) + '/log', null, signal);
            },
            clearCycleLog: function (cycle) {
                return remove('/xray/services/' + encode(cycle) + '/log');
            },
            clearLogs: function (files) {
                return post('/xray/logs/clear', {files: Array.isArray(files) ? files : []});
            },
            database: function (table, action) {
                return post('/xray/database/' + encode(table) + '/' + encode(action));
            },
            stopTimer: function (timer) {
                return remove('/xray/timers/' + encode(timer));
            }
        }
    };

    window.MDJAdminApi = api;
})(window);
