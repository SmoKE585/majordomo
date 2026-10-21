/*
 * LinkedObject UI
 * --------------------------------------------------------------------------
 * A reactive, legacy-aware editor for the `[#module name="linkedobject"#]`
 * component.
 *
 * Design goals:
 *  - the original native input/select stays the source of truth and keeps its
 *    name, id, inline handlers and jQuery listeners;
 *  - free-form values are always allowed (method calls, custom script names);
 *  - properties/methods are loaded atomically per object, deduplicated and
 *    cached between all instances on the page;
 *  - the dropdown lives in a portal, so old table/overflow/modal layouts
 *    cannot clip it;
 *  - late DOM (partLoad, AJAX rows) is initialised automatically;
 *  - no Tom Select / jQuery dependency inside the component.
 */
(function (window, document) {
    'use strict';

    var FIELD_SELECTOR = '[data-md-linkedobject]';
    var CACHE_TTL = 120000;
    var MAX_RENDER = 80;
    var MAX_CONTEXT_REQUESTS = 3;
    var LISTBOX_ID = 'md-linkedobject-listbox';

    var instances = [];
    var instanceByRoot = new Map();
    var claimedSources = new WeakMap();
    var stashGroups = new WeakMap();
    var parentGroups = new WeakMap();
    var uniqGroups = Object.create(null);
    var groups = [];

    var objectsState = {
        items: null,
        total: 0,
        latest: '',
        loadedAt: 0,
        promise: null,
        error: null,
        seq: 0
    };

    var contextCache = Object.create(null);
    var contextQueue = [];
    var contextActive = 0;

    var activeInstance = null;
    var popup = null;
    var popupList = null;
    var popupCount = null;
    var popupAdd = null;
    var popupBound = false;
    var positionFrame = 0;

    var instanceSeq = 0;
    var initTimer = 0;
    var initNeeded = false;
    var revalidateNeeded = false;
    var observer = null;
    var legacyMode = false;
    var lastBaseUrl = '';

    /* ------------------------------------------------------------------
     * Small helpers
     * ------------------------------------------------------------------ */

    function toText(value) {
        return value === null || typeof value === 'undefined' ? '' : String(value);
    }

    function trim(value) {
        return toText(value).replace(/^\s+|\s+$/g, '');
    }

    function normalizeSearch(value) {
        return trim(value).toLowerCase().replace(/ё/g, 'е');
    }

    function escapeSelector(value) {
        var text = toText(value);
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(text);
        }
        return text.replace(/([^a-zA-Z0-9_-])/g, '\\$1');
    }

    function create(tag, className) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        return node;
    }

    function clearNode(node) {
        if (!node) {
            return;
        }
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function setHidden(node, hidden) {
        if (!node) {
            return;
        }
        if (hidden) {
            node.setAttribute('hidden', 'hidden');
        } else {
            node.removeAttribute('hidden');
        }
    }

    function closest(node, selector) {
        if (!node || node.nodeType !== 1) {
            return null;
        }
        if (node.closest) {
            return node.closest(selector);
        }
        var current = node;
        while (current && current.nodeType === 1) {
            if (current.matches && current.matches(selector)) {
                return current;
            }
            current = current.parentNode;
        }
        return null;
    }

    function eventTarget(event) {
        var target = event ? event.target : null;
        if (target && target.nodeType !== 1) {
            target = target.parentElement;
        }
        return target;
    }

    function fire(node, name, detail) {
        if (!node || !node.dispatchEvent) {
            return;
        }
        var event;
        try {
            event = new CustomEvent(name, {detail: detail, bubbles: true});
        } catch (e) {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent(name, true, false, detail);
        }
        node.dispatchEvent(event);
    }

    function delay(ms) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, ms);
        });
    }

    function truncate(value, length) {
        value = toText(value);
        if (value.length <= length) {
            return value;
        }
        return value.slice(0, length - 1) + '…';
    }

    function plural(count, one, few, many) {
        count = Math.abs(parseInt(count, 10) || 0);
        var mod10 = count % 10;
        var mod100 = count % 100;
        if (mod10 === 1 && mod100 !== 11) {
            return one;
        }
        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) {
            return few;
        }
        return many;
    }

    function makeError(message, code, status, retryable) {
        var error = new Error(message);
        error.code = code || 'error';
        error.status = status || 0;
        error.retryable = !!retryable;
        return error;
    }

    function normalizeError(error) {
        if (!error) {
            return makeError('Не удалось загрузить данные.', 'error', 0, true);
        }
        if (error.code) {
            return error;
        }
        if (error.name === 'AbortError') {
            return makeError('Сервер не ответил вовремя.', 'timeout', 0, true);
        }
        var message = error.message || String(error);
        if (/Failed to fetch|NetworkError|Load failed|network/i.test(message)) {
            return makeError('Нет связи с сервером.', 'network', 0, true);
        }
        return makeError(message, 'error', 0, true);
    }

    function errorText(error) {
        if (!error) {
            return 'Не удалось загрузить данные.';
        }
        if (error.code === 'forbidden') {
            return 'Нет доступа к связанным объектам. Войдите в админку заново.';
        }
        if (error.code === 'network') {
            return 'Нет связи с сервером.';
        }
        if (error.code === 'timeout') {
            return 'Сервер не ответил вовремя.';
        }
        if (error.code === 'bad_json' || error.code === 'empty') {
            return 'Сервер вернул неожиданный ответ.';
        }
        if (error.status === 404) {
            return 'Обработчик связанных объектов не найден.';
        }
        return error.message || 'Не удалось загрузить данные.';
    }

    function parseJsonLoose(text) {
        text = toText(text).replace(/^\uFEFF/, '').replace(/^\s+|\s+$/g, '');
        if (!text) {
            throw makeError('Пустой ответ сервера.', 'empty', 0, true);
        }
        try {
            return JSON.parse(text);
        } catch (e) {
            // Some old modules print a notice before JSON. Try the first
            // object in the response instead of failing the whole component.
        }
        var start = text.indexOf('{');
        var end = text.lastIndexOf('}');
        if (start !== -1 && end > start) {
            try {
                return JSON.parse(text.slice(start, end + 1));
            } catch (e2) {
                // fall through
            }
        }
        throw makeError('Сервер вернул неожиданный ответ.', 'bad_json', 0, true);
    }

    /* ------------------------------------------------------------------
     * Network
     * ------------------------------------------------------------------ */

    function readResponse(response, text) {
        if (!response.ok) {
            var status = response.status;
            var code = status === 401 || status === 403
                ? 'forbidden'
                : (status >= 500 ? 'server' : 'http');
            throw makeError(
                status >= 500 ? 'Ошибка сервера при загрузке данных.' : 'Сервер отклонил запрос.',
                code,
                status,
                status >= 500 || status === 429
            );
        }

        var contentType = '';
        try {
            contentType = response.headers && response.headers.get
                ? toText(response.headers.get('content-type'))
                : '';
        } catch (e) {
            contentType = '';
        }

        var data = parseJsonLoose(text);
        if (data && (data.ERROR || data.error) && !data.OBJECT && !data.OBJECTS && !data.PROPERTIES && !data.METHODS) {
            var payloadError = makeError(
                toText(data.MESSAGE || data.message || data.ERROR || data.error),
                toText(data.ERROR || data.error || 'server'),
                500,
                true
            );
            payloadError.payload = data;
            throw payloadError;
        }

        if (!data && contentType.indexOf('html') !== -1) {
            throw makeError('Сервер вернул страницу вместо данных.', 'bad_json', 0, true);
        }

        return data;
    }

    function xhrRequest(url) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.timeout = 15000;
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function () {
                try {
                    resolve(readResponse({
                        ok: xhr.status >= 200 && xhr.status < 300,
                        status: xhr.status,
                        headers: {
                            get: function (name) {
                                return xhr.getResponseHeader(name);
                            }
                        }
                    }, xhr.responseText));
                } catch (error) {
                    reject(normalizeError(error));
                }
            };
            xhr.onerror = function () {
                reject(makeError('Нет связи с сервером.', 'network', 0, true));
            };
            xhr.ontimeout = function () {
                reject(makeError('Сервер не ответил вовремя.', 'timeout', 0, true));
            };
            xhr.send();
        });
    }

    function requestOnce(url) {
        if (!window.fetch) {
            return xhrRequest(url);
        }

        var controller = window.AbortController ? new AbortController() : null;
        var timer = window.setTimeout(function () {
            if (controller) {
                try {
                    controller.abort();
                } catch (e) {
                    // ignore
                }
            }
        }, 15000);

        return window.fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            return response.text().then(function (text) {
                window.clearTimeout(timer);
                return readResponse(response, text);
            });
        }, function (error) {
            window.clearTimeout(timer);
            throw normalizeError(error);
        });
    }

    function requestJson(url) {
        return requestOnce(url).catch(function (error) {
            error = normalizeError(error);
            if (error.retryable && error.code !== 'forbidden') {
                return delay(350).then(function () {
                    return requestOnce(url);
                });
            }
            throw error;
        });
    }

    function defaultBaseUrl() {
        if (lastBaseUrl) {
            return lastBaseUrl;
        }
        for (var i = 0; i < instances.length; i++) {
            if (instances[i].baseUrl) {
                lastBaseUrl = instances[i].baseUrl;
                return lastBaseUrl;
            }
        }
        return (window.ROOTHTML || '/') + 'panel/linkedobject.html';
    }

    function legacyBaseUrl() {
        var root = toText(window.ROOTHTML || '/');
        if (root.charAt(root.length - 1) !== '/') {
            root += '/';
        }
        return root + 'admin.php?action=linkedobject';
    }

    function buildAjaxUrl(params, inst) {
        if (legacyMode) {
            return legacyBaseUrl() + '&ajax=1&' + params;
        }
        var base = (inst && inst.baseUrl) || defaultBaseUrl();
        lastBaseUrl = base;
        var separator = base.indexOf('?') === -1 ? '?' : '&';
        return base + separator + 'ajax=1&' + params;
    }

    function ajax(params, inst) {
        var url = buildAjaxUrl(params, inst);
        return requestJson(url).catch(function (error) {
            if (!legacyMode && (error.status === 404 || error.code === 'bad_json' || error.code === 'empty' || error.code === 'http')) {
                var alternative = legacyBaseUrl() + '&ajax=1&' + params;
                if (alternative !== url) {
                    return requestJson(alternative).then(function (data) {
                        legacyMode = true;
                        return data;
                    });
                }
            }
            throw error;
        });
    }

    function getAnyInstance() {
        for (var i = 0; i < instances.length; i++) {
            if (instances[i].baseUrl) {
                return instances[i];
            }
        }
        return instances[0] || null;
    }

    /* ------------------------------------------------------------------
     * Data normalisation
     * ------------------------------------------------------------------ */

    function normalizeOption(item, kind) {
        if (!item) {
            return null;
        }
        var title = trim(item.TITLE || item.title || item.VALUE || item.value);
        if (!title) {
            return null;
        }
        var source = toText(item.SOURCE || item.source || '');
        var group = '';
        if (kind === 'object') {
            group = toText(item.CLASS_NAME || item.CLASS_TITLE || item.class_name || '—');
        } else if (source === 'object') {
            group = kind === 'property' ? 'Свойства объекта' : 'Методы объекта';
        } else if (source === 'class') {
            group = kind === 'property' ? 'Свойства класса' : 'Методы класса';
        } else if (source === 'script') {
            group = 'Сценарии';
        }

        return {
            value: title,
            title: title,
            description: toText(item.DESCRIPTION || item.description || ''),
            group: group,
            source: source,
            sourceLabel: source === 'object' ? 'объект' : (source === 'class' ? 'класс' : (source === 'script' ? 'сценарий' : '')),
            special: toText(item.SPECIAL || item.special || ''),
            dataType: item.DATA_TYPE || 0
        };
    }

    function normalizeList(items, kind) {
        var result = [];
        (Array.isArray(items) ? items : []).forEach(function (item) {
            var option = normalizeOption(item, kind);
            if (option) {
                result.push(option);
            }
        });
        return result;
    }

    function normalizeObjectList(data) {
        var rows = Array.isArray(data) ? data : (data && Array.isArray(data.OBJECTS) ? data.OBJECTS : []);
        var items = normalizeList(rows, 'object');
        return {
            items: items,
            total: items.length,
            latest: data && data.LATEST_OBJECT ? toText(data.LATEST_OBJECT) : ''
        };
    }

    function normalizeContext(data, objectName) {
        data = data || {};
        var object = data.OBJECT || {};
        var found = object.FOUND;
        if (typeof found === 'undefined') {
            found = !!objectName;
        }
        return {
            object: {
                title: toText(object.TITLE || objectName),
                description: toText(object.DESCRIPTION || ''),
                className: toText(object.CLASS_NAME || ''),
                classId: object.CLASS_ID || 0,
                deviceId: toText(object.DEVICE_ID || data.DEVICE_ID || ''),
                found: found === true || found === 1 || found === '1',
                special: toText(object.SPECIAL || '')
            },
            properties: normalizeList(data.PROPERTIES, 'property'),
            methods: normalizeList(data.METHODS, 'method'),
            deviceId: toText(data.DEVICE_ID || object.DEVICE_ID || '')
        };
    }

    /* ------------------------------------------------------------------
     * Object list cache
     * ------------------------------------------------------------------ */

    function ensureObjects(force) {
        if (!force && objectsState.items && (Date.now() - objectsState.loadedAt) < CACHE_TTL) {
            return Promise.resolve(objectsState);
        }
        if (!force && objectsState.promise) {
            return objectsState.promise;
        }

        objectsState.seq++;
        var requestSeq = objectsState.seq;
        var promise = ajax('op=objects', getAnyInstance()).then(function (data) {
            if (requestSeq !== objectsState.seq) {
                return objectsState;
            }
            var payload = normalizeObjectList(data);
            objectsState.items = payload.items;
            objectsState.total = payload.total;
            objectsState.latest = payload.latest;
            objectsState.loadedAt = Date.now();
            objectsState.error = null;
            objectsState.promise = null;
            notifyObjects();
            return objectsState;
        }).catch(function (error) {
            error = normalizeError(error);
            if (requestSeq === objectsState.seq) {
                objectsState.error = error;
                objectsState.promise = null;
            }
            throw error;
        });

        objectsState.promise = promise;
        return promise;
    }

    function notifyObjects() {
        instances.forEach(function (inst) {
            if (inst.role !== 'object' || inst.demo) {
                return;
            }
            inst.items = objectsState.items || [];
            inst.itemsTotal = objectsState.total || inst.items.length;
            inst.itemsLoaded = true;
            inst.itemsError = objectsState.error || null;
            inst.objectsLoading = false;
            updateValueUI(inst);
            if (activeInstance === inst && inst.open) {
                renderPopup(inst);
            }
        });
    }

    function readEmbeddedObjects(inst) {
        var node = inst.field.querySelector('[data-md-linkedobject-data="objects"]');
        if (!node) {
            return null;
        }
        try {
            return normalizeObjectList(JSON.parse(node.textContent || '[]'));
        } catch (e) {
            return null;
        }
    }

    function applyObjectItems(inst, payload) {
        inst.items = payload.items || [];
        inst.itemsTotal = payload.total || inst.items.length;
        inst.itemsLoaded = true;
        inst.itemsError = null;
        inst.objectsLoading = false;
        updateValueUI(inst);
        if (activeInstance === inst && inst.open) {
            renderPopup(inst);
        }
    }

    function loadObjectsForInstance(inst) {
        if (inst.objectsLoading) {
            return;
        }
        if (inst.demo) {
            applyObjectItems(inst, {
                items: demoObjects(inst),
                total: demoObjects(inst).length
            });
            return;
        }

        var embedded = readEmbeddedObjects(inst);
        if (embedded) {
            applyObjectItems(inst, embedded);
            return;
        }

        inst.objectsLoading = true;
        if (!inst.items || !inst.items.length) {
            setHint(inst, 'Загружаем список объектов…', 'loading');
        }

        ensureObjects(false).then(function () {
            inst.objectsLoading = false;
            inst.items = objectsState.items || [];
            inst.itemsTotal = objectsState.total || inst.items.length;
            inst.itemsLoaded = true;
            inst.itemsError = null;
            updateValueUI(inst);
            if (activeInstance === inst && inst.open) {
                renderPopup(inst);
            }
        }).catch(function (error) {
            inst.objectsLoading = false;
            inst.itemsLoaded = false;
            inst.itemsError = normalizeError(error);
            updateValueUI(inst);
            if (activeInstance === inst && inst.open) {
                renderPopup(inst);
            }
        });
    }

    /* ------------------------------------------------------------------
     * Context cache / queue
     * ------------------------------------------------------------------ */

    function getContext(objectName, force) {
        objectName = toText(objectName);
        var entry = contextCache[objectName];
        if (!force && entry && entry.data && (Date.now() - entry.at) < CACHE_TTL) {
            return Promise.resolve(entry.data);
        }
        if (!force && entry && entry.promise) {
            return entry.promise;
        }

        if (!entry || force) {
            entry = contextCache[objectName] = {
                data: entry ? entry.data : null,
                at: entry ? entry.at : 0,
                promise: null,
                error: null,
                seq: entry ? entry.seq : 0
            };
        }
        entry.seq++;
        var seq = entry.seq;

        var promise = new Promise(function (resolve, reject) {
            contextQueue.push({
                objectName: objectName,
                force: !!force,
                seq: seq,
                resolve: resolve,
                reject: reject
            });
            drainContextQueue();
        });

        entry.promise = promise;
        return promise;
    }

    function drainContextQueue() {
        while (contextActive < MAX_CONTEXT_REQUESTS && contextQueue.length) {
            (function (job) {
                contextActive++;
                ajax('op=context&object=' + encodeURIComponent(job.objectName), getAnyInstance()).then(function (data) {
                    var context = normalizeContext(data, job.objectName);
                    var entry = contextCache[job.objectName];
                    var isCurrent = entry && entry.seq === job.seq;
                    if (isCurrent) {
                        entry.data = context;
                        entry.at = Date.now();
                        entry.promise = null;
                        entry.error = null;
                        notifyContext(job.objectName, context);
                    }
                    job.resolve(isCurrent ? context : (entry && entry.data ? entry.data : context));
                }).catch(function (error) {
                    error = normalizeError(error);
                    var entry = contextCache[job.objectName];
                    var isCurrent = entry && entry.seq === job.seq;
                    if (isCurrent) {
                        entry.promise = null;
                        entry.error = error;
                        notifyContextError(job.objectName, error);
                    }
                    job.reject(error);
                }).then(function () {
                    contextActive--;
                    drainContextQueue();
                }, function () {
                    contextActive--;
                    drainContextQueue();
                });
            })(contextQueue.shift());
        }
    }

    function notifyContext(objectName, context) {
        groups.forEach(function (group) {
            if (!group.object || toText(group.object.input.value) !== objectName) {
                return;
            }
            group.context = context;
            group.contextObject = objectName;
            group.contextError = null;
            group.loading = false;
            group.initializing = false;
            applyGroupContext(group, context);
        });
    }

    function notifyContextError(objectName, error) {
        groups.forEach(function (group) {
            if (!group.object || toText(group.object.input.value) !== objectName) {
                return;
            }
            group.loading = false;
            group.contextError = error;
            updateGroupUI(group);
            if (activeInstance && activeInstance.group === group && activeInstance.open) {
                renderPopup(activeInstance);
            }
            if (group.object) {
                fire(group.object.field, 'md:linkedobject:error', {
                    object: objectName,
                    error: error
                });
            }
        });
    }

    /* ------------------------------------------------------------------
     * Demo mode (UI Kit)
     * ------------------------------------------------------------------ */

    function getGroupDemoData(group) {
        if (group.demoData) {
            return group.demoData;
        }
        var holders = [group.object, group.property, group.method];
        for (var i = 0; i < holders.length; i++) {
            if (!holders[i]) {
                continue;
            }
            var node = holders[i].field.querySelector('[data-md-linkedobject-data="demo"]');
            if (!node) {
                continue;
            }
            try {
                group.demoData = JSON.parse(node.textContent || '{}');
                return group.demoData;
            } catch (e) {
                // ignore broken demo data
            }
        }
        return null;
    }

    function isDemo(inst) {
        var group = inst.group;
        if (group && group.demoData) {
            return true;
        }
        var holders = group ? [group.object, group.property, group.method, inst] : [inst];
        for (var i = 0; i < holders.length; i++) {
            if (holders[i] && holders[i].field && holders[i].field.getAttribute('data-md-linkedobject-demo') === '1') {
                return true;
            }
        }
        return !!(group && getGroupDemoData(group));
    }

    function demoObjects(inst) {
        var data = getGroupDemoData(inst.group);
        var rows = data && Array.isArray(data.objects) ? data.objects : [];
        return normalizeList(rows, 'object');
    }

    function demoContext(group, objectName) {
        var data = getGroupDemoData(group);
        var context = data && data.contexts ? data.contexts[objectName] : null;

        if (!context && data) {
            var properties = data.properties && data.properties[objectName];
            var methods = data.methods && data.methods[objectName];
            var info = null;
            if (Array.isArray(data.objects)) {
                for (var i = 0; i < data.objects.length; i++) {
                    if (toText(data.objects[i].TITLE) === objectName) {
                        info = data.objects[i];
                        break;
                    }
                }
            }
            if (properties || methods || info) {
                context = {
                    OBJECT: info || {TITLE: objectName, FOUND: true},
                    PROPERTIES: properties || [],
                    METHODS: methods || [],
                    DEVICE_ID: info && info.DEVICE_ID ? info.DEVICE_ID : ''
                };
            }
        }

        if (!context) {
            context = {
                OBJECT: {TITLE: objectName, FOUND: false},
                PROPERTIES: [],
                METHODS: []
            };
        }
        context.OBJECT = context.OBJECT || {TITLE: objectName, FOUND: true};
        if (!context.OBJECT.TITLE) {
            context.OBJECT.TITLE = objectName;
        }
        if (typeof context.OBJECT.FOUND === 'undefined') {
            context.OBJECT.FOUND = true;
        }
        return normalizeContext(context, objectName);
    }

    /* ------------------------------------------------------------------
     * Groups and instances
     * ------------------------------------------------------------------ */

    function newGroup() {
        return {
            roots: [],
            uniq: '',
            anchor: null,
            object: null,
            property: null,
            method: null,
            context: null,
            contextObject: '',
            contextError: null,
            contextRequest: 0,
            loading: false,
            initializing: true,
            demoData: null
        };
    }

    function groupFor(field) {
        var uniq = field.getAttribute('data-md-linkedobject-uniq') || '';
        var stash = closest(field, '[data-md-linkedobject-stash]');
        var anchor = stash ? stash.parentElement : field.parentElement;

        // All object/property/method roots of one module inclusion share the
        // same parent (the module output block). A cloned row gets a different
        // parent, so cloned rows with a duplicated `uniq` do not merge.
        if (anchor) {
            var byAnchor = parentGroups.get(anchor);
            if (byAnchor && (!uniq || byAnchor.uniq === uniq)) {
                return byAnchor;
            }
        }

        if (uniq && uniqGroups[uniq]) {
            var existing = uniqGroups[uniq];
            if (!anchor || !existing.anchor || existing.anchor === anchor) {
                return existing;
            }
            // Same uniq, different DOM parent: this is a cloned/duplicated
            // inclusion. Give it its own group.
        }

        var group = !uniq && stash ? stashGroups.get(stash) : null;
        if (group) {
            return group;
        }

        group = newGroup();
        group.uniq = uniq;
        group.anchor = anchor;
        if (anchor) {
            parentGroups.set(anchor, group);
        }
        if (uniq && !uniqGroups[uniq]) {
            uniqGroups[uniq] = group;
        } else if (!uniq && stash) {
            stashGroups.set(stash, group);
        }
        groups.push(group);
        return group;
    }

    function sourceKind(source) {
        if (!source) {
            return 'none';
        }
        var tag = source.tagName;
        if (tag === 'SELECT') {
            return 'select';
        }
        if (tag === 'TEXTAREA') {
            return 'textarea';
        }
        if (tag === 'INPUT') {
            var type = toText(source.type || 'text').toLowerCase();
            if (type === 'hidden') {
                return 'hidden';
            }
            if (type === 'text' || type === 'search' || type === 'tel' || type === 'url' || type === 'email' || type === 'number') {
                return 'text';
            }
            return 'other';
        }
        return 'other';
    }

    function findSource(inst) {
        var name = inst.fieldName;
        if (!name) {
            return null;
        }

        var candidates = [];
        var scope = document;
        if (!inst.field.isConnected && inst.field.getRootNode) {
            var rootNode = inst.field.getRootNode();
            if (rootNode && rootNode.querySelectorAll) {
                scope = rootNode;
            }
        }
        var selectors = [
            '[id="' + escapeSelector(name) + '"]',
            '[name="' + escapeSelector(name) + '"]'
        ];

        selectors.forEach(function (selector) {
            var nodes;
            try {
                nodes = scope.querySelectorAll(selector);
            } catch (e) {
                nodes = [];
            }
            Array.prototype.forEach.call(nodes, function (node) {
                if (candidates.indexOf(node) === -1) {
                    candidates.push(node);
                }
            });
        });

        candidates = candidates.filter(function (node) {
            if (!node || node === inst.field || inst.field.contains(node)) {
                return false;
            }
            if (node.closest && node.closest('[data-md-linkedobject]')) {
                return false;
            }
            if (node.tagName === 'SCRIPT' || node.tagName === 'TEMPLATE') {
                return false;
            }
            var claimed = claimedSources.get(node);
            if (claimed && claimed !== inst) {
                return false;
            }
            return true;
        });

        if (!candidates.length) {
            return null;
        }

        var before = null;
        var after = null;
        candidates.forEach(function (node) {
            var position = node.compareDocumentPosition(inst.field);
            if (position & Node.DOCUMENT_POSITION_FOLLOWING) {
                before = node;
            } else if (position & Node.DOCUMENT_POSITION_PRECEDING) {
                if (!after) {
                    after = node;
                }
            }
        });

        return before || after || candidates[0];
    }

    function createInstance(field) {
        var role = field.getAttribute('data-md-linkedobject-role') || '';
        if (!role) {
            return null;
        }

        var inst = {
            id: ++instanceSeq,
            field: field,
            role: role,
            fieldName: field.getAttribute('data-md-linkedobject-field-id') || '',
            baseUrl: field.getAttribute('data-md-linkedobject-base-url') || '',
            label: field.getAttribute('data-md-linkedobject-label') || '',
            placeholder: field.getAttribute('data-md-linkedobject-placeholder') || '',
            emptyText: field.getAttribute('data-md-linkedobject-empty') || '',
            searchPlaceholder: field.getAttribute('data-md-linkedobject-search-placeholder') || '',
            initialValue: field.getAttribute('data-md-linkedobject-value') || '',
            group: groupFor(field),
            source: null,
            sourceKind: 'none',
            input: null,
            shell: field.querySelector('.md-linkedobject__shell'),
            slot: field.querySelector('[data-md-linkedobject-input-slot]'),
            labelEl: field.querySelector('[data-md-linkedobject-label-node]'),
            headEl: field.querySelector('.md-linkedobject__head'),
            hintEl: field.querySelector('[data-md-linkedobject-hint]'),
            badgeEl: field.querySelector('[data-md-linkedobject-badge]'),
            statusEl: field.querySelector('[data-md-linkedobject-status]'),
            clearBtn: field.querySelector('[data-md-linkedobject-action="clear"]'),
            toggleBtn: field.querySelector('[data-md-linkedobject-action="toggle"]'),
            actions: {
                openObject: field.querySelector('[data-md-linkedobject-action="open-object"]'),
                openProperties: field.querySelector('[data-md-linkedobject-action="open-properties"]'),
                openMethods: field.querySelector('[data-md-linkedobject-action="open-methods"]'),
                openDevice: field.querySelector('[data-md-linkedobject-action="open-device"]'),
                refresh: field.querySelector('[data-md-linkedobject-action="refresh"]')
            },
            listeners: [],
            sourceListeners: [],
            items: [],
            itemsTotal: 0,
            itemsLoaded: false,
            itemsError: null,
            objectsLoading: false,
            context: null,
            contextError: null,
            loading: false,
            open: false,
            openWatch: null,
            activeIndex: -1,
            activeFromKeyboard: false,
            visibleItems: [],
            searchTokens: [],
            renderLimit: MAX_RENDER,
            query: '',
            lastCommitted: '',
            userEditing: false,
            pendingObjectChange: false,
            suppressCount: 0,
            pointerFocus: false,
            externalLabel: null,
            demo: false,
            valueHook: false,
            ownedSource: false,
            bound: false,
            disabled: false,
            readonly: false
        };

        if (!inst.shell || !inst.slot || !inst.hintEl) {
            return null;
        }

        var group = inst.group;
        if (role === 'object') {
            if (!group.object || !group.object.field.isConnected) {
                group.object = inst;
            }
        } else if (role === 'property') {
            if (!group.property || !group.property.field.isConnected) {
                group.property = inst;
            }
        } else if (role === 'method') {
            if (!group.method || !group.method.field.isConnected) {
                group.method = inst;
            }
        }

        inst.demo = isDemo(inst);
        group.roots.push(inst);
        instances.push(inst);
        instanceByRoot.set(field, inst);
        return inst;
    }

    function prepareInput(inst, input) {
        if (!input) {
            return;
        }
        if (!input.id && inst.fieldName) {
            input.id = document.getElementById(inst.fieldName)
                ? (inst.fieldName + '__' + inst.id)
                : inst.fieldName;
        }
        input.classList.add('md-input', 'md-linkedobject__input');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-haspopup', 'listbox');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', LISTBOX_ID);
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('spellcheck', 'false');
        if (!input.getAttribute('placeholder') && inst.placeholder) {
            input.setAttribute('placeholder', inst.placeholder);
        }
        if (input.tagName === 'INPUT' && input.type === 'number') {
            input.type = 'text';
        }
        if (input.style && input.style.width) {
            input.style.width = '100%';
        }
        inst.disabled = !!input.disabled;
        inst.readonly = !!input.readOnly;
        if (input.required) {
            input.setAttribute('aria-required', 'true');
        }
        if (inst.hintEl && inst.hintEl.id) {
            input.setAttribute('aria-describedby', inst.hintEl.id);
        }
    }

    function createFallbackInput(inst, source, kind) {
        var input = create('input', 'md-input md-linkedobject__input');
        input.type = 'text';

        if (!source) {
            input.name = inst.fieldName;
            input.id = inst.fieldName && !document.getElementById(inst.fieldName)
                ? inst.fieldName
                : ('md-linkedobject-input-' + inst.id);
        } else {
            input.id = (inst.fieldName || 'md-linkedobject') + '__ui' + inst.id;
        }

        if (source) {
            if (source.disabled) {
                input.disabled = true;
            }
            if (source.readOnly) {
                input.readOnly = true;
            }
            if (source.required) {
                input.required = true;
            }
            if (source.getAttribute('placeholder') && !inst.placeholder) {
                input.setAttribute('placeholder', source.getAttribute('placeholder'));
            }
            if (source.getAttribute('title')) {
                input.setAttribute('title', source.getAttribute('title'));
            }
            if (source.getAttribute('maxlength')) {
                input.setAttribute('maxlength', source.getAttribute('maxlength'));
            }
        }

        prepareInput(inst, input);
        return input;
    }

    function hideSource(source) {
        if (!source) {
            return;
        }
        source.hidden = true;
        source.setAttribute('aria-hidden', 'true');
        source.classList.add('md-linkedobject__native-hidden');
        if (source.tabIndex >= 0) {
            source.tabIndex = -1;
        }
    }

    function moveRootAfterSource(inst, source) {
        if (!source || !source.parentNode) {
            return;
        }
        var anchor = source;
        var wrappingLabel = closest(source, 'label');
        if (wrappingLabel) {
            anchor = wrappingLabel;
        }
        var parent = anchor.parentNode;
        if (parent && (parent.tagName === 'TR' || parent.tagName === 'TBODY' || parent.tagName === 'TABLE')) {
            var cell = closest(source, 'td,th');
            if (cell) {
                anchor = cell;
            }
        }
        if (anchor.parentNode) {
            anchor.parentNode.insertBefore(inst.field, anchor.nextSibling);
        }
    }

    function detectLabel(inst) {
        var external = null;
        var source = inst.source;

        if (source && source.id) {
            var selector = 'label[for="' + escapeSelector(source.id) + '"]';
            var labelScope = source.getRootNode && source.getRootNode().querySelectorAll ? source.getRootNode() : document;
            try {
                external = labelScope.querySelector(selector);
            } catch (e) {
                external = null;
            }
            if (external && inst.field.contains(external)) {
                external = null;
            }
        }

        if (!external && source) {
            var container = closest(source, '.form-group, .md-field, .mb-3, .form-row, td');
            if (container) {
                var labels = container.querySelectorAll('label, .md-field__label');
                for (var i = 0; i < labels.length; i++) {
                    if (inst.field.contains(labels[i])) {
                        continue;
                    }
                    if (labels[i].htmlFor && source.id && labels[i].htmlFor !== source.id) {
                        continue;
                    }
                    if (trim(labels[i].textContent) !== '') {
                        external = labels[i];
                        break;
                    }
                }
            }
        }

        if (!external && source) {
            var wrappingLabel = closest(source, 'label');
            if (wrappingLabel && !inst.field.contains(wrappingLabel)) {
                external = wrappingLabel;
            }
        }

        if (external && trim(external.textContent) !== '') {
            inst.externalLabel = external;
            inst.field.classList.add('md-linkedobject--external-label');
            setHidden(inst.labelEl, true);
            if (!external.id) {
                external.id = 'md-linkedobject-label-' + inst.id;
            }
            if (inst.input) {
                inst.input.setAttribute('aria-labelledby', external.id);
            }
            if (inst.input && !external.htmlFor && !external.querySelector('input,select,textarea')) {
                addListener(inst, external, 'click', function () {
                    if (inst.input && !inst.disabled) {
                        inst.input.focus();
                    }
                });
            }
        } else {
            if (inst.labelEl) {
                setHidden(inst.labelEl, false);
                if (inst.input && inst.input.id) {
                    inst.labelEl.htmlFor = inst.input.id;
                }
                if (!inst.labelEl.id) {
                    inst.labelEl.id = 'md-linkedobject-label-' + inst.id;
                }
                if (inst.input) {
                    inst.input.setAttribute('aria-labelledby', inst.labelEl.id);
                }
            }
            if (inst.input && inst.label) {
                inst.input.setAttribute('aria-label', inst.label);
            }
        }

        syncHead(inst);

        if (inst.input) {
            if (!inst.input.getAttribute('aria-label') && !inst.input.getAttribute('aria-labelledby')) {
                inst.input.setAttribute('aria-label', inst.label || inst.fieldName || 'Связанное значение');
            }
        }
    }

    function mount(inst) {
        var source = findSource(inst);
        var kind = sourceKind(source);

        if (source && kind === 'text' && !(source.style && source.style.display === 'none')) {
            inst.source = source;
            inst.sourceKind = 'text';
            inst.input = source;
            claimedSources.set(source, inst);
            source.classList.add('md-input', 'md-linkedobject__input');
            if (!source.getAttribute('name') && inst.fieldName) {
                source.setAttribute('name', inst.fieldName);
            }
            prepareInput(inst, source);
            moveRootAfterSource(inst, source);
            inst.slot.appendChild(source);
        } else if (source) {
            inst.source = source;
            inst.sourceKind = kind;
            claimedSources.set(source, inst);
            hideSource(source);
            inst.input = createFallbackInput(inst, source, kind);
            moveRootAfterSource(inst, source);
            inst.slot.appendChild(inst.input);
        } else {
            inst.input = createFallbackInput(inst, null, 'none');
            inst.source = inst.input;
            inst.sourceKind = 'text';
            inst.ownedSource = true;
            claimedSources.set(inst.input, inst);
            inst.slot.appendChild(inst.input);
        }

        var value = readValue(inst);
        if (!value && inst.initialValue) {
            writeValue(inst, inst.initialValue);
            value = inst.initialValue;
        }
        if (inst.input !== inst.source) {
            inst.input.value = value;
        }
        inst.lastCommitted = value;
        inst.field.hidden = false;

        var widthValue = toText(inst.field.getAttribute('data-md-linkedobject-width'));
        var compactWidth = /^\d+(?:\.\d+)?px$/i.test(widthValue) && parseFloat(widthValue) < 300;
        if (compactWidth || closest(inst.field, 'td,th')) {
            inst.field.classList.add('md-linkedobject--compact');
        }

        if (closest(inst.field, '[data-md-linkedobject-layout="table"]')) {
            inst.field.classList.add('md-linkedobject--table');
            if (!inst.input.getAttribute('aria-label') && !inst.input.getAttribute('aria-labelledby')) {
                inst.input.setAttribute('aria-label', inst.field.getAttribute('data-md-linkedobject-label') || inst.role);
            }
        }

        detectLabel(inst);
        bindInstance(inst);
        updateValueUI(inst);
    }

    /* ------------------------------------------------------------------
     * Value sync and events
     * ------------------------------------------------------------------ */

    function readValue(inst) {
        if (!inst) {
            return '';
        }
        if (inst.source) {
            return toText(inst.source.value);
        }
        return inst.input ? toText(inst.input.value) : '';
    }

    function setSelectValue(select, value) {
        if (!select) {
            return;
        }
        var option = null;
        var temporary = [];
        Array.prototype.forEach.call(select.options, function (item) {
            if (item.getAttribute('data-md-linkedobject-temp') === '1') {
                temporary.push(item);
            }
            if (item.value === value) {
                option = item;
            }
        });

        temporary.forEach(function (item) {
            if (item !== option) {
                item.parentNode.removeChild(item);
            }
        });

        if (!option && value !== '') {
            option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            option.setAttribute('data-md-linkedobject-temp', '1');
            select.appendChild(option);
        }

        select.value = value;
    }

    function writeValue(inst, value) {
        value = toText(value);
        if (!inst.source) {
            if (inst.input) {
                inst.input.value = value;
            }
            return;
        }
        if (inst.sourceKind === 'select') {
            setSelectValue(inst.source, value);
        } else {
            inst.source.value = value;
        }
        if (inst.input && inst.input !== inst.source) {
            inst.input.value = value;
        }
    }

    function clearGroupDependents(group) {
        if (!group) {
            return;
        }
        [group.property, group.method].forEach(function (dependent) {
            if (!dependent) {
                return;
            }
            if (readValue(dependent)) {
                setValue(dependent, '', {reason: 'object', emit: false, dispatch: true});
            }
            dependent.lastCommitted = '';
            dependent.items = [];
            dependent.itemsLoaded = false;
            dependent.itemsError = null;
            updateValueUI(dependent);
        });
    }

    function setValue(inst, value, options) {
        options = options || {};
        value = toText(value);

        if (options.silent) {
            inst.suppressCount++;
            try {
                writeValue(inst, value);
            } finally {
                inst.suppressCount--;
            }
            inst.lastCommitted = value;
            updateValueUI(inst);
            return;
        }

        if (inst.role === 'object' && inst.group && value !== readValue(inst)) {
            clearGroupDependents(inst.group);
        }

        inst.suppressCount++;
        try {
            writeValue(inst, value);
            if (options.dispatch !== false && inst.source && inst.source.dispatchEvent) {
                try {
                    inst.source.dispatchEvent(new Event('input', {bubbles: true}));
                } catch (e) {
                    // ignore
                }
                try {
                    inst.source.dispatchEvent(new Event('change', {bubbles: true}));
                } catch (e) {
                    // ignore
                }
            }
        } finally {
            inst.suppressCount--;
        }

        inst.userEditing = false;
        if (options.emit === false) {
            inst.lastCommitted = value;
            updateValueUI(inst);
            return;
        }
        updateValueUI(inst);
        handleCommitted(inst, options.reason || 'programmatic');
    }

    function handleCommitted(inst, reason) {
        var value = readValue(inst);
        var previous = inst.lastCommitted;
        inst.lastCommitted = value;
        inst.pendingObjectChange = false;

        if (value === previous && reason !== 'force') {
            updateValueUI(inst);
            return;
        }

        updateValueUI(inst);

        if (inst.role === 'object') {
            onObjectCommitted(inst, value, previous, reason);
        }

        fire(inst.field, 'md:linkedobject:change', {
            value: value,
            previous: previous,
            role: inst.role,
            field: inst.fieldName,
            reason: reason
        });
    }

    function onObjectCommitted(inst, value, previous, reason) {
        var group = inst.group;
        if (!group) {
            return;
        }

        if (group.contextObject === value && group.context && reason !== 'force') {
            updateValueUI(inst);
            return;
        }

        var dependents = [];
        if (group.property) {
            dependents.push(group.property);
        }
        if (group.method) {
            dependents.push(group.method);
        }

        group.contextObject = value;
        group.context = null;
        group.contextError = null;
        group.initializing = false;
        group.loading = false;

        // Values are cleared before the object change event is dispatched (see
        // setValue/onInput), so a legacy onchange handler can populate the new
        // property/method without being overwritten afterwards.
        dependents.forEach(function (dependent) {
            dependent.items = [];
            dependent.itemsLoaded = false;
            dependent.itemsError = null;
            updateValueUI(dependent);
        });

        if (!value) {
            updateValueUI(inst);
            return;
        }

        if (!dependents.length) {
            updateValueUI(inst);
            return;
        }

        startGroupContext(group, value, false);
    }

    function startGroupContext(group, objectName, force) {
        objectName = toText(objectName);
        if (!objectName) {
            group.context = null;
            group.contextObject = '';
            group.contextError = null;
            group.loading = false;
            updateGroupUI(group);
            return;
        }

        if (!force && group.contextObject === objectName && group.context) {
            applyGroupContext(group, group.context);
            return;
        }

        group.contextObject = objectName;
        group.context = null;
        group.contextError = null;
        group.loading = true;
        group.contextRequest = (group.contextRequest || 0) + 1;
        var requestId = group.contextRequest;
        updateGroupUI(group);
        if (activeInstance && activeInstance.group === group && activeInstance.open) {
            renderPopup(activeInstance);
        }

        var loader;
        if (group.object && isDemo(group.object)) {
            loader = delay(180).then(function () {
                return demoContext(group, objectName);
            });
        } else {
            loader = getContext(objectName, !!force);
        }

        loader.then(function (context) {
            if (group.contextObject !== objectName || group.contextRequest !== requestId) {
                return;
            }
            group.loading = false;
            group.context = context;
            group.contextError = null;
            group.initializing = false;
            applyGroupContext(group, context);
            var holder = group.object || group.property || group.method;
            if (holder) {
                fire(holder.field, 'md:linkedobject:context', {
                    object: objectName,
                    properties: context.properties.length,
                    methods: context.methods.length,
                    deviceId: context.deviceId
                });
            }
        }).catch(function (error) {
            if (group.contextObject !== objectName || group.contextRequest !== requestId) {
                return;
            }
            error = normalizeError(error);
            group.loading = false;
            group.contextError = error;
            updateGroupUI(group);
            if (activeInstance && activeInstance.group === group && activeInstance.open) {
                renderPopup(activeInstance);
            }
            var holder = group.object || group.property || group.method;
            if (holder) {
                fire(holder.field, 'md:linkedobject:error', {
                    object: objectName,
                    error: error
                });
            }
        });
    }

    function applyGroupContext(group, context) {
        group.context = context;
        [group.object, group.property, group.method].forEach(function (inst) {
            if (inst) {
                inst.context = context;
            }
        });

        if (group.property) {
            group.property.items = context.properties;
            group.property.itemsTotal = context.properties.length;
            group.property.itemsLoaded = true;
            group.property.itemsError = null;
        }
        if (group.method) {
            group.method.items = context.methods;
            group.method.itemsTotal = context.methods.length;
            group.method.itemsLoaded = true;
            group.method.itemsError = null;
        }

        updateGroupUI(group);

        if (activeInstance && activeInstance.group === group && activeInstance.open) {
            renderPopup(activeInstance);
        }
    }

    function updateGroupUI(group) {
        if (!group) {
            return;
        }
        if (group.object) {
            updateValueUI(group.object);
        }
        if (group.property) {
            updateValueUI(group.property);
        }
        if (group.method) {
            updateValueUI(group.method);
        }
    }

    function addListener(inst, node, type, handler, options) {
        if (!node) {
            return;
        }
        node.addEventListener(type, handler, options);
        inst.listeners.push({node: node, type: type, handler: handler, options: options});
    }

    function addSourceListener(inst, node, type, handler) {
        if (!node) {
            return;
        }
        node.addEventListener(type, handler);
        inst.sourceListeners.push({node: node, type: type, handler: handler});
    }

    function removeSourceListeners(inst) {
        inst.sourceListeners.forEach(function (item) {
            try {
                item.node.removeEventListener(item.type, item.handler);
            } catch (e) {
                // ignore
            }
        });
        inst.sourceListeners = [];
    }

    function offAll(inst) {
        inst.listeners.forEach(function (item) {
            try {
                item.node.removeEventListener(item.type, item.handler, item.options);
            } catch (e) {
                // ignore
            }
        });
        inst.listeners = [];
        removeSourceListeners(inst);
        if (inst.valueHook && inst.source) {
            try {
                delete inst.source.value;
            } catch (e) {
                // ignore
            }
            inst.valueHook = false;
        }
    }

    function installValueHook(inst) {
        if (inst.valueHook || !inst.source || !Object.getOwnPropertyDescriptor) {
            return;
        }
        if (Object.prototype.hasOwnProperty.call(inst.source, 'value')) {
            return;
        }
        try {
            var descriptor = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(inst.source), 'value');
            if (!descriptor || !descriptor.get || !descriptor.set) {
                return;
            }
            Object.defineProperty(inst.source, 'value', {
                configurable: true,
                enumerable: false,
                get: function () {
                    return descriptor.get.call(this);
                },
                set: function (newValue) {
                    descriptor.set.call(this, newValue);
                    if (inst.suppressCount > 0) {
                        return;
                    }
                    window.setTimeout(function () {
                        if (inst.suppressCount > 0) {
                            return;
                        }
                        syncFromSource(inst);
                        handleCommitted(inst, 'external');
                    }, 0);
                }
            });
            inst.valueHook = true;
        } catch (e) {
            inst.valueHook = false;
        }
    }

    function bindSourceEvents(inst) {
        removeSourceListeners(inst);
        if (!inst.source || inst.source === inst.input) {
            installValueHook(inst);
            return;
        }
        addSourceListener(inst, inst.source, 'input', function () {
            if (inst.suppressCount > 0) {
                return;
            }
            if (inst.input) {
                inst.input.value = readValue(inst);
            }
            inst.userEditing = true;
            updateValueUI(inst, {typing: true});
        });
        addSourceListener(inst, inst.source, 'change', function () {
            if (inst.suppressCount > 0) {
                return;
            }
            if (inst.input) {
                inst.input.value = readValue(inst);
            }
            inst.userEditing = true;
            handleCommitted(inst, 'user');
        });
        installValueHook(inst);
    }

    function bindInstance(inst) {
        if (inst.bound) {
            return;
        }
        inst.bound = true;

        var input = inst.input;
        addListener(inst, input, 'input', function () {
            if (inst.suppressCount > 0) {
                return;
            }
            if (input !== inst.source) {
                inst.suppressCount++;
                try {
                    writeValue(inst, input.value);
                } finally {
                    inst.suppressCount--;
                }
            }
            if (inst.role === 'object' && inst.group && input.value !== inst.lastCommitted && !inst.pendingObjectChange) {
                inst.pendingObjectChange = true;
                clearGroupDependents(inst.group);
            }
            inst.userEditing = true;
            inst.query = input.value;
            inst.renderLimit = MAX_RENDER;
            inst.activeIndex = 0;
            inst.activeFromKeyboard = false;
            if (!inst.open) {
                openPopup(inst, {keepQuery: true});
            } else {
                renderPopup(inst);
            }
            updateValueUI(inst, {typing: true});
        });

        addListener(inst, input, 'change', function () {
            if (inst.suppressCount > 0) {
                return;
            }
            inst.userEditing = true;
            handleCommitted(inst, 'user');
        });

        addListener(inst, input, 'focus', function () {
            updateValueUI(inst);
            if (inst.pointerFocus) {
                inst.pointerFocus = false;
                openPopup(inst);
            }
        });

        addListener(inst, input, 'blur', function () {
            window.setTimeout(function () {
                if (!inst.open) {
                    return;
                }
                var focused = document.activeElement;
                if (focused === inst.input || (popup && popup.contains(focused))) {
                    return;
                }
                closePopup(inst);
            }, 90);
        });

        addListener(inst, input, 'keydown', function (event) {
            onKeydown(inst, event);
        });

        addListener(inst, input, 'pointerdown', function () {
            inst.pointerFocus = true;
            updateValueUI(inst);
        });

        addListener(inst, input, 'click', function () {
            ensureSource(inst);
            if (!inst.open) {
                openPopup(inst);
            }
        });

        if (inst.clearBtn) {
            addListener(inst, inst.clearBtn, 'pointerdown', function (event) {
                event.preventDefault();
            });
            addListener(inst, inst.clearBtn, 'click', function (event) {
                event.preventDefault();
                if (inst.disabled || inst.readonly) {
                    return;
                }
                setValue(inst, '', {reason: 'user'});
                if (inst.input) {
                    inst.input.focus();
                }
                openPopup(inst);
            });
        }

        if (inst.toggleBtn) {
            addListener(inst, inst.toggleBtn, 'pointerdown', function (event) {
                event.preventDefault();
            });
            addListener(inst, inst.toggleBtn, 'click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                if (inst.open) {
                    closePopup(inst);
                } else if (inst.input) {
                    inst.input.focus();
                    openPopup(inst);
                }
            });
        }

        if (inst.actions.refresh) {
            addListener(inst, inst.actions.refresh, 'click', function (event) {
                event.preventDefault();
                refresh(inst);
            });
        }

        bindSourceEvents(inst);

        var form = (inst.source && inst.source.form) || closest(inst.field, 'form');
        if (form) {
            addListener(inst, form, 'reset', function () {
                window.setTimeout(function () {
                    syncFromSource(inst);
                    handleCommitted(inst, 'reset');
                }, 0);
            });
            addListener(inst, form, 'submit', function () {
                closePopup(inst);
            });
        }

        installValueHook(inst);
    }

    function syncFromSource(inst) {
        var value = readValue(inst);
        if (inst.input && inst.input !== inst.source && inst.input.value !== value) {
            inst.input.value = value;
        }
        inst.userEditing = false;
        updateValueUI(inst);
    }

    function ensureSource(inst) {
        if (!inst.field.isConnected) {
            return;
        }
        if (inst.source && inst.source.isConnected) {
            return;
        }

        var previous = inst.lastCommitted || (inst.input ? inst.input.value : '');
        offAll(inst);
        inst.bound = false;

        if (inst.source && claimedSources.get(inst.source) === inst) {
            claimedSources.delete(inst.source);
        }

        var newSource = findSource(inst);
        if (newSource) {
            inst.source = newSource;
            inst.sourceKind = sourceKind(newSource);
            inst.ownedSource = false;
            claimedSources.set(newSource, inst);
            hideSource(newSource);

            if (!inst.input || !inst.input.isConnected || inst.input === inst.source) {
                inst.input = createFallbackInput(inst, newSource, inst.sourceKind);
                clearNode(inst.slot);
                inst.slot.appendChild(inst.input);
                prepareInput(inst, inst.input);
            } else {
                inst.input.removeAttribute('name');
            }
            inst.input.value = previous;
        } else {
            var fallback = createFallbackInput(inst, null, 'none');
            fallback.name = inst.fieldName;
            fallback.id = inst.fieldName && !document.getElementById(inst.fieldName)
                ? inst.fieldName
                : ('md-linkedobject-input-' + inst.id);
            inst.source = fallback;
            inst.sourceKind = 'text';
            inst.input = fallback;
            inst.ownedSource = true;
            claimedSources.set(fallback, inst);
            clearNode(inst.slot);
            inst.slot.appendChild(fallback);
            prepareInput(inst, fallback);
            fallback.value = previous;
        }

        if (!readValue(inst) && previous) {
            inst.suppressCount++;
            try {
                writeValue(inst, previous);
            } finally {
                inst.suppressCount--;
            }
        }

        bindInstance(inst);
        updateValueUI(inst);
    }

    /* ------------------------------------------------------------------
     * Hints, badges and actions
     * ------------------------------------------------------------------ */

    function setHint(inst, text, state, retry) {
        if (!inst || !inst.hintEl) {
            return;
        }
        clearNode(inst.hintEl);
        var className = 'md-field__hint md-linkedobject__hint';
        if (state === 'error') {
            className += ' is-error';
        } else if (state === 'warning') {
            className += ' is-warning';
        }
        inst.hintEl.className = className;

        if (state === 'loading') {
            var spinner = create('span', 'md-spinner');
            spinner.setAttribute('aria-hidden', 'true');
            inst.hintEl.appendChild(spinner);
        } else if (state === 'error' || state === 'warning') {
            var icon = create('i', 'ti ' + (state === 'error' ? 'ti-alert-circle' : 'ti-alert-triangle'));
            icon.setAttribute('aria-hidden', 'true');
            inst.hintEl.appendChild(icon);
        }

        var label = create('span');
        label.textContent = text;
        inst.hintEl.appendChild(label);

        if (retry) {
            var retryButton = create('button', 'md-linkedobject__retry');
            retryButton.type = 'button';
            retryButton.textContent = 'Повторить';
            retryButton.addEventListener('click', function (event) {
                event.preventDefault();
                retry();
            });
            inst.hintEl.appendChild(retryButton);
        }

        if (inst.statusEl) {
            inst.statusEl.textContent = text;
        }
    }

    function setBadge(inst, text, tone) {
        if (!inst || !inst.badgeEl) {
            return;
        }
        if (!text) {
            inst.badgeEl.textContent = '';
            setHidden(inst.badgeEl, true);
            syncHead(inst);
            return;
        }
        inst.badgeEl.className = 'md-badge md-badge--' + (tone || 'outline') + ' md-linkedobject__badge';
        inst.badgeEl.textContent = text;
        setHidden(inst.badgeEl, false);
        syncHead(inst);
    }

    function syncHead(inst) {
        if (!inst || !inst.headEl) {
            return;
        }
        var labelVisible = !!(inst.labelEl && !inst.labelEl.hasAttribute('hidden') && trim(inst.labelEl.textContent) !== '');
        var badgeVisible = !!(inst.badgeEl && !inst.badgeEl.hasAttribute('hidden'));
        setHidden(inst.headEl, !(labelVisible || badgeVisible));
    }

    function findItem(items, value) {
        value = toText(value);
        if (!value) {
            return null;
        }
        for (var i = 0; i < (items || []).length; i++) {
            if (toText(items[i].value) === value) {
                return items[i];
            }
        }
        return null;
    }

    function hasDependents(group) {
        return !!(group && (group.property || group.method));
    }

    function updateValueUI(inst, options) {
        options = options || {};
        if (!inst || !inst.input) {
            return;
        }
        inst.disabled = !!(inst.input.disabled || (inst.source && inst.source.disabled));
        inst.readonly = !!(inst.input.readOnly || (inst.source && inst.source.readOnly));
        var value = readValue(inst);
        if (inst.clearBtn) {
            setHidden(inst.clearBtn, !value || inst.disabled || inst.readonly);
        }
        inst.input.classList.toggle('md-linkedobject--filled', !!value);
        inst.field.classList.toggle('md-linkedobject--filled', !!value);
        inst.field.classList.toggle('md-linkedobject--loading', !!inst.loading || !!(inst.group && inst.group.loading));
        inst.input.setAttribute('aria-expanded', inst.open ? 'true' : 'false');
        if (inst.toggleBtn) {
            inst.toggleBtn.setAttribute('aria-expanded', inst.open ? 'true' : 'false');
        }
        updateActions(inst);
        if (!options.typing) {
            updateHint(inst);
        }
    }

    function updateHint(inst) {
        if (!inst || !inst.hintEl) {
            return;
        }
        if (inst.role === 'object') {
            updateObjectHint(inst);
        } else {
            updateDependentHint(inst);
        }
    }

    function updateObjectHint(inst) {
        var value = readValue(inst);
        var group = inst.group;

        if (inst.itemsError && !inst.itemsLoaded) {
            setBadge(inst, '', null);
            setHint(inst, errorText(inst.itemsError), 'error', function () {
                refresh(inst);
            });
            return;
        }

        if (!inst.itemsLoaded && !inst.demo) {
            setBadge(inst, '', null);
            setHint(inst, 'Загружаем список объектов…', 'loading');
            return;
        }

        if (!value) {
            setBadge(inst, '', null);
            var count = (inst.items || []).length;
            if (count) {
                setHint(inst, 'Доступно ' + count + ' ' + plural(count, 'объект', 'объекта', 'объектов') + '. Начните вводить имя или выберите из списка.', 'normal');
            } else {
                setHint(inst, 'Объекты не найдены. Можно ввести значение вручную.', 'warning');
            }
            return;
        }

        if (group && group.loading && hasDependents(group)) {
            setBadge(inst, '', null);
            setHint(inst, 'Загружаем привязки…', 'loading');
            return;
        }

        if (group && group.contextError) {
            setBadge(inst, '', null);
            setHint(inst, errorText(group.contextError), 'error', function () {
                startGroupContext(group, value, true);
            });
            return;
        }

        if (group && group.context && group.context.object) {
            var object = group.context.object;
            if (!object.found) {
                setBadge(inst, 'не найден', 'warning');
                setHint(inst, 'Объект не найден — значение сохранится как есть.', 'warning');
                return;
            }
            var parts = [];
            if (object.special === 'scripts') {
                parts.push('Сценарии');
                parts.push('доступно: ' + group.context.methods.length);
            } else {
                if (object.className && object.className !== '—') {
                    parts.push('Класс: ' + object.className);
                }
                parts.push('свойств: ' + group.context.properties.length);
                parts.push('методов: ' + group.context.methods.length);
            }
            if (object.deviceId) {
                parts.push('устройство #' + object.deviceId);
            }
            setBadge(inst, '', null);
            setHint(inst, parts.join(' · '), 'normal');
            return;
        }

        var known = findItem(inst.items, value);
        if (known) {
            setBadge(inst, '', null);
            var knownText;
            if (known.special === 'scripts') {
                knownText = 'Сценарии — доступны методы-сценарии';
            } else if (known.description) {
                knownText = known.description + (known.group && known.group !== '—' ? ' · класс: ' + known.group : '');
            } else {
                knownText = known.group && known.group !== '—' ? 'Класс: ' + known.group : 'Объект выбран';
            }
            setHint(inst, knownText, 'normal');
        } else {
            setBadge(inst, 'не найден', 'warning');
            setHint(inst, 'Объект не найден в списке — значение сохранится как есть.', 'warning');
        }
    }

    function updateDependentHint(inst) {
        var group = inst.group;
        var objectInstance = group ? group.object : null;
        var objectValue = objectInstance ? readValue(objectInstance) : '';
        var value = readValue(inst);
        var isProperty = inst.role === 'property';
        var label = isProperty ? 'свойства' : 'методы';

        if (!objectInstance || !objectValue) {
            setBadge(inst, '', null);
            setHint(inst, 'Сначала выберите объект.', 'normal');
            return;
        }

        if (group.loading) {
            setBadge(inst, '', null);
            setHint(inst, 'Загружаем ' + label + '…', 'loading');
            return;
        }

        if (group.contextError) {
            setBadge(inst, '', null);
            setHint(inst, errorText(group.contextError), 'error', function () {
                startGroupContext(group, objectValue, true);
            });
            return;
        }

        if (!group.context) {
            setBadge(inst, '', null);
            setHint(inst, 'Список ' + label + ' ещё не загружен.', 'normal');
            return;
        }

        var object = group.context.object;
        if (!object || !object.found) {
            setBadge(inst, value ? 'вручную' : '', value ? 'info' : null);
            setHint(inst, 'Объект не найден — можно ввести значение вручную.', 'warning');
            return;
        }

        var items = isProperty ? group.context.properties : group.context.methods;
        var count = items.length;
        var found = findItem(items, value);
        var custom = !!value && !found;

        if (object.special === 'scripts' && isProperty) {
            setBadge(inst, '', null);
            setHint(inst, 'Для AllScripts свойства недоступны — сценарии выбираются в поле метода.', 'normal');
            return;
        }

        var text;
        var itemWord = isProperty
            ? plural(count, 'свойство', 'свойства', 'свойств')
            : plural(count, 'метод', 'метода', 'методов');
        if (!value) {
            text = count === 0
                ? 'У объекта нет ' + (isProperty ? 'свойств' : 'методов') + '. Можно ввести значение вручную.'
                : 'Доступно ' + count + ' ' + itemWord + '. Начните вводить или выберите из списка.';
        } else if (custom) {
            text = 'Своё значение — сохранится как есть.';
        } else {
            text = (isProperty ? 'Свойство: ' : 'Метод: ') + value + (count > 1 ? ' · всего: ' + count : '');
        }

        setBadge(inst, custom ? 'вручную' : '', custom ? 'info' : null);
        setHint(inst, text, custom ? 'warning' : 'normal');
    }

    function redirectUrl(inst, query) {
        var base = inst.baseUrl || defaultBaseUrl();
        if (legacyMode) {
            base = legacyBaseUrl();
        }
        var separator = base.indexOf('?') === -1 ? '?' : '&';
        return base + separator + 'op=redirect&' + query;
    }

    function setAction(node, visible, href) {
        if (!node) {
            return;
        }
        setHidden(node, !visible);
        if (visible && href) {
            node.setAttribute('href', href);
        } else {
            node.removeAttribute('href');
        }
    }

    function updateActions(inst) {
        if (!inst) {
            return;
        }
        var group = inst.group;
        var objectInstance = group ? group.object : null;
        var objectValue = objectInstance
            ? (objectInstance.lastCommitted || '')
            : (inst.role === 'object' ? (inst.lastCommitted || '') : '');
        var objectPending = !!(objectInstance && readValue(objectInstance) !== (objectInstance.lastCommitted || ''));
        var context = group ? group.context : null;
        var object = context ? context.object : null;
        var objectKnown = false;

        if (object) {
            objectKnown = !!object.found;
        } else if (objectInstance) {
            objectKnown = !!findItem(objectInstance.items, objectValue);
        }

        if (inst.role === 'object') {
            var selfValue = inst.lastCommitted || '';
            var visible = !!selfValue && objectKnown && !objectPending;
            var special = object && object.special === 'scripts';
            setAction(inst.actions.openObject, visible && !special, visible ? redirectUrl(inst, 'object=' + encodeURIComponent(selfValue)) : '');
            setAction(inst.actions.openProperties, visible && !special, visible ? redirectUrl(inst, 'object=' + encodeURIComponent(selfValue) + '&sub=properties') : '');
            setAction(inst.actions.openMethods, visible && !special, visible ? redirectUrl(inst, 'object=' + encodeURIComponent(selfValue) + '&sub=methods') : '');
            setAction(inst.actions.openDevice, visible && object && object.deviceId, object && object.deviceId
                ? redirectUrl(inst, 'device_id=' + encodeURIComponent(object.deviceId) + '&object=' + encodeURIComponent(selfValue))
                : '');
        } else {
            var dependentVisible = !!objectValue && objectKnown && !objectPending;
            var sub = inst.role === 'property' ? 'properties' : 'methods';
            var action = inst.role === 'property' ? inst.actions.openProperties : inst.actions.openMethods;
            setAction(action, dependentVisible, dependentVisible ? redirectUrl(inst, 'object=' + encodeURIComponent(objectValue) + '&sub=' + sub) : '');
        }

        if (inst.actions.refresh) {
            setHidden(inst.actions.refresh, false);
        }
    }

    function refresh(inst) {
        var group = inst.group;
        var objectValue = group && group.object ? readValue(group.object) : '';

        if (inst.role === 'object') {
            inst.itemsLoaded = false;
            inst.itemsError = null;
            if (inst.demo) {
                applyObjectItems(inst, {items: demoObjects(inst), total: demoObjects(inst).length});
            } else {
                inst.objectsLoading = true;
                ensureObjects(true).then(function () {
                    inst.objectsLoading = false;
                    inst.itemsLoaded = true;
                    inst.itemsError = null;
                    updateValueUI(inst);
                    if (activeInstance === inst && inst.open) {
                        renderPopup(inst);
                    }
                }).catch(function (error) {
                    inst.objectsLoading = false;
                    inst.itemsLoaded = false;
                    inst.itemsError = normalizeError(error);
                    updateValueUI(inst);
                    if (activeInstance === inst && inst.open) {
                        renderPopup(inst);
                    }
                });
            }
            if (readValue(inst) && hasDependents(group)) {
                startGroupContext(group, readValue(inst), true);
            }
            updateValueUI(inst);
            return;
        }

        if (objectValue) {
            startGroupContext(group, objectValue, true);
        } else {
            updateValueUI(inst);
        }
    }

    function openObjectsPage(inst) {
        var url = (window.ROOTHTML || '/') + 'panel/objects.html';
        closePopup(inst);
        window.open(url, '_blank', 'noopener');
    }

    /* ------------------------------------------------------------------
     * Dropdown
     * ------------------------------------------------------------------ */

    function ensurePopup() {
        if (popup && popup.isConnected) {
            return;
        }
        if (popup && !popup.isConnected) {
            popup = null;
            popupList = null;
            popupCount = null;
            popupAdd = null;
        }

        popup = create('div', 'md-linkedobject-popover');
        popup.id = 'md-linkedobject-popover';
        popup.hidden = true;
        popup.setAttribute('role', 'presentation');

        popupList = create('div', 'md-linkedobject-popover__list');
        popupList.id = LISTBOX_ID;
        popupList.setAttribute('role', 'listbox');
        popupList.setAttribute('aria-label', 'Варианты');
        popup.appendChild(popupList);

        var footer = create('div', 'md-linkedobject-popover__footer');
        popupCount = create('span', 'md-linkedobject-popover__count');
        footer.appendChild(popupCount);

        var keys = create('span', 'md-linkedobject-popover__keys');
        keys.textContent = '↑↓ · Enter · Esc';
        footer.appendChild(keys);

        popupAdd = create('button', 'md-linkedobject-popover__add');
        popupAdd.type = 'button';
        popupAdd.hidden = true;
        var addIcon = create('i', 'ti ti-plus');
        addIcon.setAttribute('aria-hidden', 'true');
        popupAdd.appendChild(addIcon);
        var addLabel = create('span');
        addLabel.textContent = 'Объекты';
        popupAdd.appendChild(addLabel);
        footer.appendChild(popupAdd);

        popup.appendChild(footer);
        document.body.appendChild(popup);

        if (!popupBound) {
            popupBound = true;
            document.addEventListener('pointerdown', onDocumentPointerDown, true);
            document.addEventListener('focusin', onDocumentFocusIn, true);
            window.addEventListener('scroll', onWindowScroll, true);
            window.addEventListener('resize', schedulePosition);
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', schedulePosition);
                window.visualViewport.addEventListener('scroll', schedulePosition);
            }
        }

        popup.addEventListener('pointerdown', function (event) {
            var target = eventTarget(event);
            if (target && (target.closest('[data-md-linkedobject-index]') || target.closest('button'))) {
                event.preventDefault();
            }
        });

        popup.addEventListener('click', onPopupClick);
    }

    function onDocumentPointerDown(event) {
        if (!activeInstance) {
            return;
        }
        var target = eventTarget(event);
        if (!target) {
            return;
        }
        if (popup && popup.contains(target)) {
            return;
        }
        if (activeInstance.field.contains(target)) {
            return;
        }
        closePopup(activeInstance);
    }

    function onDocumentFocusIn(event) {
        if (!activeInstance) {
            return;
        }
        var target = eventTarget(event);
        if (!target) {
            return;
        }
        if (target === activeInstance.input || activeInstance.field.contains(target)) {
            return;
        }
        if (popup && popup.contains(target)) {
            return;
        }
        closePopup(activeInstance);
    }

    function onWindowScroll(event) {
        if (popup && event && event.target && popup.contains(event.target)) {
            return;
        }
        schedulePosition();
    }

    function schedulePosition() {
        if (positionFrame) {
            return;
        }
        positionFrame = window.requestAnimationFrame(function () {
            positionFrame = 0;
            positionPopup();
        });
    }

    function positionPopup() {
        if (!activeInstance || !activeInstance.open || !popup || popup.hidden) {
            return;
        }
        var shell = activeInstance.shell;
        if (!shell) {
            return;
        }
        var rect = shell.getBoundingClientRect();
        if (rect.width === 0 && rect.height === 0) {
            closePopup(activeInstance);
            return;
        }

        var visualViewport = window.visualViewport;
        var viewLeft = visualViewport ? visualViewport.offsetLeft : 0;
        var viewTop = visualViewport ? visualViewport.offsetTop : 0;
        var viewWidth = visualViewport ? visualViewport.width : window.innerWidth;
        var viewHeight = visualViewport ? visualViewport.height : window.innerHeight;

        var width = Math.max(rect.width, 280);
        width = Math.min(width, viewWidth - 16);
        var left = Math.min(Math.max(viewLeft + 8, rect.left), viewLeft + viewWidth - width - 8);
        var spaceBelow = (viewTop + viewHeight) - rect.bottom - 8;
        var spaceAbove = rect.top - viewTop - 8;
        var maxHeight = Math.max(160, Math.min(360, Math.max(spaceBelow, spaceAbove) - 6));

        popup.style.width = width + 'px';
        popup.style.maxHeight = maxHeight + 'px';
        popup.style.left = left + 'px';

        var openAbove = spaceBelow < 180 && spaceAbove > spaceBelow;
        var height = popup.offsetHeight;
        var top = openAbove ? rect.top - height - 6 : rect.bottom + 6;
        if (top < viewTop + 8) {
            top = viewTop + 8;
        }
        if (top + height > viewTop + viewHeight - 8) {
            top = Math.max(viewTop + 8, viewTop + viewHeight - height - 8);
        }

        popup.style.top = top + 'px';
        popup.style.visibility = 'visible';
        popup.classList.toggle('md-linkedobject-popover--above', openAbove);
    }

    function buildVisibleItems(inst) {
        var items = (inst.items || []).slice();
        var query = normalizeSearch(inst.query);
        var tokens = query ? query.split(/\s+/) : [];
        inst.searchTokens = tokens;
        var currentValue = inst.lastCommitted || '';
        var result = [];
        var groupOrder = 0;
        var groupOrderMap = Object.create(null);

        items.forEach(function (item, index) {
            item._index = index;
            item._search = normalizeSearch(item.value + ' ' + item.title + ' ' + item.description + ' ' + item.group + ' ' + item.sourceLabel);
            if (typeof groupOrderMap[item.group] === 'undefined') {
                groupOrderMap[item.group] = groupOrder++;
            }
            item._groupOrder = groupOrderMap[item.group];

            if (tokens.length) {
                for (var i = 0; i < tokens.length; i++) {
                    if (item._search.indexOf(tokens[i]) === -1) {
                        return;
                    }
                }
                item._score = scoreItem(item, query);
                result.push(item);
            } else {
                result.push(item);
            }
        });

        if (tokens.length) {
            result.sort(function (a, b) {
                if (a._groupOrder !== b._groupOrder) {
                    return a._groupOrder - b._groupOrder;
                }
                if (a._score !== b._score) {
                    return a._score - b._score;
                }
                return a._index - b._index;
            });
        }

        if (currentValue && (!query || normalizeSearch(currentValue).indexOf(query) !== -1)) {
            var currentExists = false;
            for (var i = 0; i < result.length; i++) {
                if (result[i].value === currentValue) {
                    currentExists = true;
                    break;
                }
            }
            if (!currentExists) {
                result.unshift({
                    value: currentValue,
                    title: currentValue,
                    description: 'Текущее значение',
                    group: 'Текущее значение',
                    source: '',
                    sourceLabel: '',
                    special: '',
                    pinned: true,
                    badge: 'нет в списке',
                    badgeTone: 'warning',
                    _search: normalizeSearch(currentValue),
                    _groupOrder: -1,
                    _score: 0,
                    _index: -1
                });
            }
        }

        return result;
    }

    function scoreItem(item, query) {
        var value = normalizeSearch(item.value);
        if (value === query) {
            return 0;
        }
        if (value.indexOf(query) === 0) {
            return 1;
        }
        if (value.indexOf(' ' + query) !== -1) {
            return 2;
        }
        var title = normalizeSearch(item.title);
        if (title.indexOf(query) === 0) {
            return 3;
        }
        return 4;
    }

    function appendHighlighted(parent, text, tokens) {
        text = toText(text);
        if (!tokens || !tokens.length) {
            parent.appendChild(document.createTextNode(text));
            return;
        }
        var lower = normalizeSearch(text);
        var ranges = [];
        tokens.forEach(function (token) {
            if (!token) {
                return;
            }
            var start = 0;
            var index;
            while ((index = lower.indexOf(token, start)) !== -1) {
                ranges.push([index, index + token.length]);
                start = index + token.length;
            }
        });
        if (!ranges.length) {
            parent.appendChild(document.createTextNode(text));
            return;
        }
        ranges.sort(function (a, b) {
            return a[0] - b[0];
        });
        var merged = [];
        ranges.forEach(function (range) {
            var last = merged[merged.length - 1];
            if (last && range[0] <= last[1]) {
                last[1] = Math.max(last[1], range[1]);
            } else {
                merged.push([range[0], range[1]]);
            }
        });
        var cursor = 0;
        merged.forEach(function (range) {
            if (range[0] > cursor) {
                parent.appendChild(document.createTextNode(text.slice(cursor, range[0])));
            }
            var mark = create('mark');
            mark.textContent = text.slice(range[0], range[1]);
            parent.appendChild(mark);
            cursor = range[1];
        });
        if (cursor < text.length) {
            parent.appendChild(document.createTextNode(text.slice(cursor)));
        }
    }

    function renderLoading() {
        var wrapper = create('div', 'md-linkedobject-popover__loading');
        for (var i = 0; i < 3; i++) {
            var row = create('div', 'md-linkedobject-popover__skeleton');
            var line = create('span', 'md-skeleton md-skeleton--text');
            line.style.setProperty('--md-skeleton-width', (68 + i * 8) + '%');
            row.appendChild(line);
            wrapper.appendChild(row);
        }
        return wrapper;
    }

    function renderEmpty(inst, visibleCount) {
        var wrapper = create('div', 'md-linkedobject-popover__empty');
        var icon = create('i', 'ti ' + (inst.query ? 'ti-search-off' : 'ti-inbox'));
        icon.setAttribute('aria-hidden', 'true');
        wrapper.appendChild(icon);

        var title = create('div', 'md-linkedobject-popover__empty-title');
        var text = create('div', 'md-linkedobject-popover__empty-text');
        var action = null;

        if (inst.role === 'object' && inst.itemsError) {
            title.textContent = 'Список не загрузился';
            text.textContent = errorText(inst.itemsError);
            action = {label: 'Повторить', handler: function () { refresh(inst); }};
        } else if (inst.query) {
            title.textContent = 'Ничего не найдено';
            text.textContent = 'Можно ввести значение вручную и нажать Enter.';
        } else if (inst.role !== 'object' && !(inst.group && inst.group.object && readValue(inst.group.object))) {
            title.textContent = 'Сначала выберите объект';
            text.textContent = 'Список свойств и методов зависит от объекта.';
            action = {label: 'Выбрать объект', handler: function () {
                var objectInstance = inst.group ? inst.group.object : null;
                if (objectInstance) {
                    closePopup(inst);
                    objectInstance.input.focus();
                    openPopup(objectInstance);
                }
            }};
        } else if (inst.group && inst.group.contextError) {
            title.textContent = 'Список не загрузился';
            text.textContent = errorText(inst.group.contextError);
            action = {label: 'Повторить', handler: function () {
                startGroupContext(inst.group, readValue(inst.group.object), true);
            }};
        } else {
            title.textContent = inst.emptyText || 'Нет доступных вариантов';
            text.textContent = 'Можно ввести значение вручную и нажать Enter.';
        }

        wrapper.appendChild(title);
        wrapper.appendChild(text);

        if (action) {
            var button = create('button', 'md-linkedobject-popover__empty-action');
            button.type = 'button';
            button.textContent = action.label;
            button.addEventListener('click', function (event) {
                event.preventDefault();
                action.handler();
            });
            wrapper.appendChild(button);
        }

        return wrapper;
    }

    function renderPopup(inst) {
        if (!popup || activeInstance !== inst || !inst.open) {
            return;
        }

        var visible = buildVisibleItems(inst);
        inst.visibleItems = visible;
        var currentValue = inst.lastCommitted || '';
        var listScrollTop = popupList.scrollTop;

        clearNode(popupList);

        var isLoading = inst.loading ||
            (inst.role === 'object' && !inst.demo && !inst.itemsLoaded && !inst.itemsError) ||
            (inst.role !== 'object' && inst.group && inst.group.loading && (!inst.items || !inst.items.length));

        if (isLoading) {
            popupList.appendChild(renderLoading());
            popupCount.textContent = 'Загрузка…';
            popupAdd.hidden = true;
            return;
        }

        if (!visible.length) {
            popupList.appendChild(renderEmpty(inst, 0));
            popupCount.textContent = '0 вариантов';
            popupAdd.hidden = inst.role !== 'object' || inst.demo;
            return;
        }

        var fragment = document.createDocumentFragment();
        var lastGroup = null;
        var rendered = 0;
        var limit = Math.min(inst.renderLimit, visible.length);

        for (var i = 0; i < limit; i++) {
            var item = visible[i];

            if (item.group && item.group !== '—' && item.group !== lastGroup) {
                var header = create('div', 'md-linkedobject-popover__group');
                header.textContent = item.group;
                fragment.appendChild(header);
                lastGroup = item.group;
            }

            var option = create('div', 'md-linkedobject-popover__option');
            option.setAttribute('role', 'option');
            option.id = 'md-linkedobject-option-' + inst.id + '-' + i;
            option.setAttribute('data-md-linkedobject-index', String(i));
            option.setAttribute('aria-selected', item.value === currentValue ? 'true' : 'false');
            if (i === inst.activeIndex) {
                option.classList.add('is-active');
            }
            if (item.value === currentValue) {
                option.classList.add('is-selected');
            }
            if (item.pinned) {
                option.classList.add('is-pinned');
            }

            var main = create('span', 'md-linkedobject-popover__option-main');
            var title = create('span', 'md-linkedobject-popover__option-title');
            appendHighlighted(title, item.title || item.value, inst.searchTokens);
            main.appendChild(title);

            if (item.description) {
                var description = create('span', 'md-linkedobject-popover__option-meta');
                appendHighlighted(description, truncate(item.description, 160), inst.searchTokens);
                main.appendChild(description);
            }

            if (item.badge || item.sourceLabel) {
                var sub = create('span', 'md-linkedobject-popover__option-sub');
                if (item.badge) {
                    var badge = create('span', 'md-badge md-badge--' + (item.badgeTone || 'outline') + ' md-linkedobject-popover__badge');
                    badge.textContent = item.badge;
                    sub.appendChild(badge);
                }
                if (item.sourceLabel) {
                    var source = create('span', 'md-linkedobject-popover__source');
                    source.textContent = item.sourceLabel;
                    sub.appendChild(source);
                }
                main.appendChild(sub);
            }

            option.appendChild(main);

            var check = create('span', 'md-linkedobject-popover__check');
            var checkIcon = create('i', 'ti ti-check');
            checkIcon.setAttribute('aria-hidden', 'true');
            check.appendChild(checkIcon);
            option.appendChild(check);

            fragment.appendChild(option);
            rendered++;
        }

        popupList.appendChild(fragment);

        if (visible.length > limit) {
            var more = create('button', 'md-linkedobject-popover__more');
            more.type = 'button';
            more.setAttribute('data-md-linkedobject-more', '1');
            more.textContent = 'Показать ещё ' + Math.min(MAX_RENDER, visible.length - limit) + ' из ' + (visible.length - limit);
            popupList.appendChild(more);
        }

        if (inst.activeIndex >= limit) {
            inst.activeIndex = limit - 1;
        }
        if (inst.activeIndex < 0 && limit) {
            inst.activeIndex = 0;
        }

        popupCount.textContent = visible.length + ' ' + plural(visible.length, 'вариант', 'варианта', 'вариантов');
        popupAdd.hidden = inst.role !== 'object' || inst.demo;

        popupList.scrollTop = Math.min(listScrollTop, popupList.scrollHeight);
        updateActiveOption(inst, false);
    }

    function updateActiveOption(inst, scroll) {
        if (!popupList || activeInstance !== inst) {
            return;
        }
        var options = popupList.querySelectorAll('[data-md-linkedobject-index]');
        for (var i = 0; i < options.length; i++) {
            var index = parseInt(options[i].getAttribute('data-md-linkedobject-index'), 10);
            options[i].classList.toggle('is-active', index === inst.activeIndex);
        }
        var active = null;
        for (var j = 0; j < options.length; j++) {
            if (parseInt(options[j].getAttribute('data-md-linkedobject-index'), 10) === inst.activeIndex) {
                active = options[j];
                break;
            }
        }
        if (active) {
            inst.input.setAttribute('aria-activedescendant', active.id);
            if (scroll) {
                scrollWithinList(active);
            }
        } else {
            inst.input.removeAttribute('aria-activedescendant');
        }
    }

    function scrollWithinList(option) {
        if (!popupList || !option) {
            return;
        }
        var listRect = popupList.getBoundingClientRect();
        var optionRect = option.getBoundingClientRect();
        if (optionRect.top < listRect.top) {
            popupList.scrollTop -= (listRect.top - optionRect.top);
        } else if (optionRect.bottom > listRect.bottom) {
            popupList.scrollTop += (optionRect.bottom - listRect.bottom);
        }
    }

    function openPopup(inst, options) {
        options = options || {};
        if (!inst || !inst.input || inst.input.disabled) {
            return;
        }
        if (activeInstance && activeInstance !== inst) {
            closePopup(activeInstance);
        }

        ensurePopup();
        activeInstance = inst;
        inst.open = true;
        inst.query = options.keepQuery ? inst.input.value : '';
        inst.renderLimit = MAX_RENDER;
        inst.activeFromKeyboard = false;

        popup.hidden = false;
        popup.style.visibility = 'hidden';
        popup.setAttribute('aria-hidden', 'false');
        popupList.scrollTop = 0;
        inst.input.setAttribute('aria-expanded', 'true');
        if (inst.toggleBtn) {
            inst.toggleBtn.setAttribute('aria-expanded', 'true');
        }

        if (inst.role === 'object' && !inst.demo && !inst.itemsLoaded) {
            loadObjectsForInstance(inst);
        }

        renderPopup(inst);

        var visible = inst.visibleItems || [];
        if (!options.keepQuery) {
            var currentValue = readValue(inst);
            var index = -1;
            for (var i = 0; i < visible.length; i++) {
                if (visible[i].value === currentValue) {
                    index = i;
                    break;
                }
            }
            inst.activeIndex = index >= 0 ? index : (visible.length ? 0 : -1);
        } else {
            inst.activeIndex = visible.length ? 0 : -1;
        }

        updateActiveOption(inst, true);
        positionPopup();

        if (!inst.openWatch) {
            inst.openWatch = window.setInterval(function () {
                if (!inst.open) {
                    return;
                }
                if (!inst.field.isConnected) {
                    closePopup(inst);
                    return;
                }
                var rect = inst.field.getBoundingClientRect();
                if (rect.width === 0 && rect.height === 0) {
                    closePopup(inst);
                }
            }, 350);
        }
    }

    function closePopup(inst) {
        if (!inst) {
            return;
        }
        var isActive = activeInstance === inst || (!activeInstance && inst.open);
        inst.open = false;
        if (isActive) {
            activeInstance = null;
            if (popup) {
                popup.hidden = true;
                popup.style.visibility = 'hidden';
                popup.setAttribute('aria-hidden', 'true');
            }
        }
        if (inst.input) {
            inst.input.setAttribute('aria-expanded', 'false');
            inst.input.removeAttribute('aria-activedescendant');
        }
        if (inst.toggleBtn) {
            inst.toggleBtn.setAttribute('aria-expanded', 'false');
        }
        if (inst.openWatch) {
            window.clearInterval(inst.openWatch);
            inst.openWatch = null;
        }
        inst.visibleItems = [];
        inst.searchTokens = [];
    }

    function moveActive(inst, delta) {
        if (!inst.open) {
            openPopup(inst);
            return;
        }
        var count = Math.min(inst.visibleItems.length, inst.renderLimit);
        if (!count) {
            return;
        }
        if (inst.activeIndex < 0) {
            inst.activeIndex = 0;
        } else {
            inst.activeIndex = Math.max(0, Math.min(count - 1, inst.activeIndex + delta));
        }
        inst.activeFromKeyboard = true;
        updateActiveOption(inst, true);
    }

    function setActive(inst, index) {
        if (!inst.open) {
            return;
        }
        inst.activeIndex = index;
        updateActiveOption(inst, true);
    }

    function chooseActive(inst) {
        var item = inst.visibleItems[inst.activeIndex];
        if (item) {
            chooseOption(inst, item);
        }
    }

    function chooseOption(inst, item) {
        if (!item || inst.readonly || inst.disabled) {
            return;
        }
        setValue(inst, item.value, {reason: 'select'});
        closePopup(inst);
        if (inst.input) {
            inst.input.focus();
            var length = toText(inst.input.value).length;
            try {
                inst.input.setSelectionRange(length, length);
            } catch (e) {
                // ignore
            }
        }
    }

    function onKeydown(inst, event) {
        if (event.isComposing) {
            return;
        }
        var key = event.key;

        if (key === 'ArrowDown') {
            event.preventDefault();
            if (!inst.open) {
                openPopup(inst);
            } else {
                moveActive(inst, 1);
            }
            return;
        }

        if (key === 'ArrowUp') {
            event.preventDefault();
            if (!inst.open) {
                openPopup(inst);
            } else {
                moveActive(inst, -1);
            }
            return;
        }

        if (key === 'Enter') {
            if (inst.open) {
                event.preventDefault();
                if (inst.activeFromKeyboard && inst.activeIndex >= 0) {
                    chooseActive(inst);
                } else {
                    inst.userEditing = true;
                    handleCommitted(inst, 'user');
                    closePopup(inst);
                }
            }
            return;
        }

        if (key === 'Escape') {
            if (inst.open) {
                event.preventDefault();
                event.stopPropagation();
                closePopup(inst);
            }
            return;
        }

        if (key === 'Tab') {
            if (inst.open) {
                closePopup(inst);
            }
            return;
        }

        if (!inst.open) {
            return;
        }

        if (key === 'Home') {
            event.preventDefault();
            setActive(inst, 0);
            return;
        }

        if (key === 'End') {
            event.preventDefault();
            if (inst.visibleItems.length) {
                setActive(inst, Math.min(inst.visibleItems.length, inst.renderLimit) - 1);
            }
            return;
        }

        if (key === 'PageDown') {
            event.preventDefault();
            moveActive(inst, 8);
            return;
        }

        if (key === 'PageUp') {
            event.preventDefault();
            moveActive(inst, -8);
            return;
        }
    }

    function onPopupClick(event) {
        var target = eventTarget(event);
        if (!target || !activeInstance) {
            return;
        }
        var inst = activeInstance;

        var option = target.closest('[data-md-linkedobject-index]');
        if (option) {
            event.preventDefault();
            var index = parseInt(option.getAttribute('data-md-linkedobject-index'), 10);
            var item = inst.visibleItems[index];
            if (item) {
                chooseOption(inst, item);
            }
            return;
        }

        if (target.closest('[data-md-linkedobject-more]')) {
            event.preventDefault();
            inst.renderLimit += MAX_RENDER;
            renderPopup(inst);
            positionPopup();
            return;
        }

        if (target.closest('.md-linkedobject-popover__add')) {
            event.preventDefault();
            openObjectsPage(inst);
            return;
        }

        if (target.closest('.md-linkedobject-popover__empty-action')) {
            return;
        }
    }

    /* ------------------------------------------------------------------
     * Initialisation / lifecycle
     * ------------------------------------------------------------------ */

    function initializeObject(inst) {
        inst.demo = isDemo(inst);
        if (inst.demo) {
            var demoItems = demoObjects(inst);
            applyObjectItems(inst, {items: demoItems, total: demoItems.length});
        } else {
            var embedded = readEmbeddedObjects(inst);
            if (embedded) {
                applyObjectItems(inst, embedded);
            } else {
                inst.itemsLoaded = false;
                loadObjectsForInstance(inst);
            }
        }

        var value = readValue(inst);
        inst.lastCommitted = value;
        updateValueUI(inst);

        if (value && hasDependents(inst.group)) {
            inst.group.initializing = true;
            startGroupContext(inst.group, value, false);
        }
    }

    function initializeDependent(inst) {
        var group = inst.group;
        inst.demo = isDemo(inst);
        inst.lastCommitted = readValue(inst);

        if (group.context) {
            applyGroupContext(group, group.context);
        } else if (group.object && readValue(group.object) && hasDependents(group) && !group.loading && !group.contextError) {
            group.initializing = true;
            startGroupContext(group, readValue(group.object), false);
        }

        updateValueUI(inst);
    }

    function init(root) {
        prune();

        var scope = root && root.querySelectorAll ? root : document;
        var fields = [];

        if (scope.nodeType === 1 && scope.matches && scope.matches(FIELD_SELECTOR)) {
            fields.push(scope);
        }
        if (scope.querySelectorAll) {
            Array.prototype.forEach.call(scope.querySelectorAll(FIELD_SELECTOR), function (node) {
                if (fields.indexOf(node) === -1) {
                    fields.push(node);
                }
            });
        }

        var created = [];
        fields.forEach(function (field) {
            if (instanceByRoot.has(field)) {
                return;
            }
            try {
                var inst = createInstance(field);
                if (inst) {
                    created.push(inst);
                }
            } catch (error) {
                warn(error);
            }
        });

        created.forEach(function (inst) {
            try {
                mount(inst);
            } catch (error) {
                warn(error);
                setHidden(inst.field, true);
            }
        });

        created.sort(function (a, b) {
            var order = {object: 0, property: 1, method: 2};
            return (order[a.role] || 9) - (order[b.role] || 9);
        });

        created.forEach(function (inst) {
            try {
                if (inst.role === 'object') {
                    initializeObject(inst);
                } else {
                    initializeDependent(inst);
                }
                fire(inst.field, 'md:linkedobject:ready', {
                    role: inst.role,
                    field: inst.fieldName,
                    value: readValue(inst)
                });
            } catch (error) {
                warn(error);
            }
        });
    }

    function warn(error) {
        if (window.console && window.console.warn) {
            window.console.warn('[linkedobject]', error);
        }
    }

    function prune() {
        for (var i = instances.length - 1; i >= 0; i--) {
            var field = instances[i].field;
            // Keep components that live in a detached fragment/modal: they may
            // be attached later. Only fully removed nodes are destroyed.
            if (!field.isConnected && !field.parentNode) {
                destroyInstance(instances[i]);
            }
        }
    }

    function revalidateSources() {
        instances.forEach(function (inst) {
            if (inst.field.isConnected) {
                ensureSource(inst);
            }
        });
    }

    function destroyInstance(inst) {
        if (!inst) {
            return;
        }
        if (activeInstance === inst) {
            closePopup(inst);
        }
        if (inst.openWatch) {
            window.clearInterval(inst.openWatch);
            inst.openWatch = null;
        }
        offAll(inst);

        if (inst.source && claimedSources.get(inst.source) === inst) {
            claimedSources.delete(inst.source);
        }

        if (inst.group) {
            var group = inst.group;
            if (group.object === inst) {
                group.object = null;
            }
            if (group.property === inst) {
                group.property = null;
            }
            if (group.method === inst) {
                group.method = null;
            }
            var index = group.roots.indexOf(inst);
            if (index !== -1) {
                group.roots.splice(index, 1);
            }
            if (!group.roots.length) {
                var groupIndex = groups.indexOf(group);
                if (groupIndex !== -1) {
                    groups.splice(groupIndex, 1);
                }
            }
        }

        instanceByRoot.delete(inst.field);
        var instanceIndex = instances.indexOf(inst);
        if (instanceIndex !== -1) {
            instances.splice(instanceIndex, 1);
        }

        if (inst.field.isConnected) {
            if (inst.source && !inst.ownedSource) {
                if (inst.source.classList) {
                    inst.source.classList.remove('md-linkedobject__input', 'md-linkedobject__native-hidden', 'md-linkedobject--filled');
                }
                inst.source.hidden = false;
                inst.source.removeAttribute('aria-hidden');
                if (inst.source.tabIndex < 0) {
                    inst.source.removeAttribute('tabindex');
                }
                if (inst.source.parentNode === inst.slot) {
                    inst.field.parentNode.insertBefore(inst.source, inst.field);
                }
            }
            if (inst.field.parentNode) {
                inst.field.parentNode.removeChild(inst.field);
            }
        }
    }

    function scheduleWork() {
        if (initTimer) {
            return;
        }
        initTimer = window.setTimeout(function () {
            initTimer = 0;
            prune();
            if (revalidateNeeded) {
                revalidateNeeded = false;
                revalidateSources();
            }
            if (initNeeded) {
                initNeeded = false;
                init(document);
            }
        }, 120);
    }

    function startObserver() {
        if (observer || !window.MutationObserver || !document.body) {
            return;
        }
        observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                if (mutation.type !== 'childList') {
                    return;
                }
                // Typing in the popup re-renders its option list; that must not
                // schedule a component re-scan on every key press.
                if (popup && popup.contains(mutation.target)) {
                    return;
                }
                if (mutation.removedNodes && mutation.removedNodes.length && instances.length) {
                    revalidateNeeded = true;
                }
                Array.prototype.forEach.call(mutation.addedNodes || [], function (node) {
                    if (node.nodeType !== 1) {
                        return;
                    }
                    if ((node.matches && node.matches(FIELD_SELECTOR)) || (node.querySelector && node.querySelector(FIELD_SELECTOR))) {
                        initNeeded = true;
                    }
                });
            });
            if (initNeeded || revalidateNeeded) {
                scheduleWork();
            }
        });
        observer.observe(document.body, {childList: true, subtree: true});
    }

    function resolveInstance(target) {
        if (!target) {
            return instances[0] || null;
        }
        if (typeof target === 'string') {
            for (var i = 0; i < instances.length; i++) {
                if (instances[i].fieldName === target || (instances[i].field && instances[i].field.id === target)) {
                    return instances[i];
                }
            }
            var node = document.getElementById(target);
            if (node) {
                return instanceByRoot.get(node) || instanceByRoot.get(closest(node, FIELD_SELECTOR));
            }
            return null;
        }
        if (target.nodeType === 1) {
            return instanceByRoot.get(target) || instanceByRoot.get(closest(target, FIELD_SELECTOR));
        }
        return null;
    }

    window.MDLinkedObjectUI = {
        version: '2.0',
        init: init,
        refresh: function (target) {
            var inst = resolveInstance(target);
            if (inst) {
                refresh(inst);
            }
            return !!inst;
        },
        getInstance: resolveInstance,
        getValue: function (target) {
            var inst = resolveInstance(target);
            return inst ? readValue(inst) : '';
        },
        setValue: function (target, value) {
            var inst = resolveInstance(target);
            if (!inst) {
                return false;
            }
            setValue(inst, value, {reason: 'api'});
            return true;
        },
        open: function (target) {
            var inst = resolveInstance(target);
            if (!inst) {
                return false;
            }
            if (inst.input) {
                inst.input.focus();
            }
            openPopup(inst);
            return true;
        },
        close: function () {
            if (activeInstance) {
                closePopup(activeInstance);
            }
        },
        destroy: function (target) {
            var inst = resolveInstance(target);
            if (!inst) {
                return false;
            }
            destroyInstance(inst);
            return true;
        }
    };

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('linkedobject', {
            init: init
        });
    }

    function onReady() {
        startObserver();
        init(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady);
    } else {
        onReady();
    }

    window.addEventListener('pageshow', function () {
        init(document);
    });
})(window, document);
