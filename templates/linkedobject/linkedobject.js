(function (window, document) {
    'use strict';

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function fetchJson(url) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Request failed: ' + response.status);
            }
            return response.json();
        });
    }

    function parseObjectOptions(select) {
        var groups = [];
        if (!select) {
            return groups;
        }

        Array.prototype.slice.call(select.children).forEach(function (node) {
            if (node.tagName === 'OPTGROUP') {
                var group = {
                    title: node.label || '',
                    options: []
                };
                Array.prototype.slice.call(node.children).forEach(function (option) {
                    if (!option.value) {
                        return;
                    }
                    group.options.push({
                        value: option.value,
                        title: option.value,
                        label: option.textContent.trim(),
                        description: option.getAttribute('data-md-description') || '',
                        group: group.title,
                        search: [option.value, option.textContent, group.title, option.getAttribute('data-md-description') || ''].join(' ').toLowerCase()
                    });
                });
                if (group.options.length) {
                    groups.push(group);
                }
            }
        });

        return groups;
    }

    function rebuildObjectSource(select, objects) {
        if (!select) {
            return [];
        }

        var map = {};
        select.innerHTML = '<option value=""></option>';

        (objects || []).forEach(function (item) {
            var groupTitle = item.CLASS_NAME || 'Objects';
            if (!map[groupTitle]) {
                var group = document.createElement('optgroup');
                group.label = groupTitle;
                map[groupTitle] = group;
                select.appendChild(group);
            }
            var option = document.createElement('option');
            option.value = item.TITLE || '';
            option.textContent = item.DESCRIPTION ? (item.TITLE + ' - ' + item.DESCRIPTION) : (item.TITLE || '');
            option.setAttribute('data-md-description', item.DESCRIPTION || '');
            map[groupTitle].appendChild(option);
        });

        return parseObjectOptions(select);
    }

    function setActionVisible(element, visible, href) {
        if (!element) {
            return;
        }
        if (href) {
            element.setAttribute('href', href);
        }
        element.hidden = !visible;
    }

    function moveInputIntoField(fieldNode) {
        if (!fieldNode) {
            return null;
        }

        var fieldId = fieldNode.getAttribute('data-md-linkedobject-field-id');
        if (!fieldId) {
            fieldNode.remove();
            return null;
        }

        var nativeInput = fieldNode.querySelector('[data-md-linkedobject-native]');
        if (nativeInput) {
            nativeInput.classList.add('md-linkedobject-field__source-input');
            if (nativeInput.type !== 'hidden') {
                nativeInput.type = 'hidden';
            }
            return nativeInput;
        }

        var sourceInput = document.getElementById(fieldId);
        if (!sourceInput) {
            fieldNode.remove();
            return null;
        }

        var slot = fieldNode.querySelector('[data-md-linkedobject-hidden-slot]');
        sourceInput.classList.add('md-linkedobject-field__source-input');
        if (sourceInput.type !== 'hidden') {
            sourceInput.type = 'hidden';
        }

        sourceInput.replaceWith(fieldNode);
        if (slot) {
            slot.appendChild(sourceInput);
        }

        return sourceInput;
    }

    function createOptionMarkup(item) {
        var meta = item.group || '';
        if (item.description) {
            meta += (meta ? ' · ' : '') + item.description;
        }
        return '<button type="button" class="md-linkedobject-object-option" data-md-linkedobject-object-option="' + escapeHtml(item.value) + '">' +
            '<span class="md-linkedobject-object-option__title">' + escapeHtml(item.title) + '</span>' +
            '<span class="md-linkedobject-object-option__meta">' + escapeHtml(meta) + '</span>' +
        '</button>';
    }

    function renderObjectResults(state) {
        var results = state.objectResults;
        if (!results) {
            return;
        }

        var term = String(state.objectSearch ? state.objectSearch.value : '').trim().toLowerCase();
        var html = '';
        var total = 0;

        state.objectGroups.forEach(function (group) {
            var items = group.options.filter(function (item) {
                return !term || item.search.indexOf(term) !== -1;
            });
            if (!items.length) {
                return;
            }
            total += items.length;
            html += '<section class="md-linkedobject-object-group">' +
                '<div class="md-linkedobject-object-group__title">' + escapeHtml(group.title) + '</div>' +
                items.map(createOptionMarkup).join('') +
            '</section>';
        });

        if (!total) {
            html = '<div class="md-linkedobject-state">' + escapeHtml(state.objectField.getAttribute('data-md-linkedobject-empty') || 'Ничего не найдено') + '</div>';
        }

        results.innerHTML = html;
        state.filteredObjectCount = total;
    }

    function findObjectByValue(state, value) {
        var match = null;
        state.objectGroups.some(function (group) {
            return group.options.some(function (item) {
                if (item.value === value) {
                    match = item;
                    return true;
                }
                return false;
            });
        });
        return match;
    }

    function updateObjectSummary(state) {
        var current = findObjectByValue(state, state.objectInput ? state.objectInput.value : '');
        var valueNode = state.objectField.querySelector('[data-md-linkedobject-object-value]');
        var metaNode = state.objectField.querySelector('[data-md-linkedobject-object-meta]');
        var baseUrl = state.baseUrl;
        var objectValue = state.objectInput ? state.objectInput.value : '';

        if (valueNode) {
            valueNode.textContent = current ? current.title : (state.objectField.getAttribute('data-md-linkedobject-placeholder') || 'Выберите объект');
        }

        if (metaNode) {
            if (current) {
                var meta = current.group || '';
                if (state.propertyItems.length || state.methodItems.length) {
                    meta += (meta ? ' · ' : '') + 'Свойства: ' + state.propertyItems.length + ' · Методы: ' + state.methodItems.length;
                }
                metaNode.textContent = meta || current.label;
            } else {
                metaNode.textContent = 'Выберите объект и загрузите привязки';
            }
        }

        setActionVisible(state.openObjectAction, !!objectValue, baseUrl + '?op=redirect&object=' + encodeURIComponent(objectValue));
        setActionVisible(state.openPropertyAction, !!objectValue, baseUrl + '?op=redirect&object=' + encodeURIComponent(objectValue) + '&sub=properties');
        setActionVisible(state.openMethodAction, !!objectValue, baseUrl + '?op=redirect&object=' + encodeURIComponent(objectValue) + '&sub=methods');
        setActionVisible(state.openDeviceAction, !!state.deviceId, baseUrl + '?op=redirect&device_id=' + encodeURIComponent(state.deviceId));
    }

    function buildSelectOptions(select, items, selectedValue) {
        if (!select) {
            return;
        }
        select.innerHTML = '<option value=""></option>';
        items.forEach(function (item) {
            var option = document.createElement('option');
            option.value = item.value;
            option.textContent = item.label;
            if (selectedValue && selectedValue === item.value) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }

    function applySelectFilter(state, role) {
        var items = role === 'property' ? state.propertyItems : state.methodItems;
        var field = role === 'property' ? state.propertyField : state.methodField;
        var input = role === 'property' ? state.propertyInput : state.methodInput;
        var select = role === 'property' ? state.propertySelect : state.methodSelect;
        var filter = role === 'property' ? state.propertyFilter : state.methodFilter;
        var hint = role === 'property' ? state.propertyHint : state.methodHint;
        var currentValue = input ? input.value : '';
        var term = String(filter ? filter.value : '').trim().toLowerCase();
        var filtered = items.filter(function (item) {
            return !term || item.search.indexOf(term) !== -1;
        });

        if (!field || !select) {
            return;
        }

        buildSelectOptions(select, filtered, currentValue);
        select.disabled = !filtered.length;
        if (filter) {
            filter.disabled = !items.length;
        }
        if (field) {
            field.classList.toggle('is-empty', !items.length);
        }
        if (hint) {
            hint.textContent = items.length ? ('Показано ' + filtered.length + ' из ' + items.length) : (field.getAttribute('data-md-linkedobject-empty') || 'Нет данных');
        }

        if (!filtered.some(function (item) { return item.value === currentValue; }) && input) {
            input.value = '';
        }
    }

    function normalizeCollection(items) {
        return (items || []).map(function (item) {
            var title = item.TITLE || '';
            var description = item.DESCRIPTION || '';
            return {
                value: title,
                label: description ? (title + ' - ' + description) : title,
                search: (title + ' ' + description).toLowerCase()
            };
        });
    }

    function loadProperties(state) {
        if (!state.propertyField || !state.objectInput || !state.objectInput.value) {
            state.propertyItems = [];
            state.deviceId = '';
            applySelectFilter(state, 'property');
            updateObjectSummary(state);
            return Promise.resolve();
        }

        return fetchJson(state.baseUrl + '?ajax=1&op=properties&object=' + encodeURIComponent(state.objectInput.value)).then(function (data) {
            state.deviceId = data.DEVICE_ID || '';
            state.propertyItems = normalizeCollection(data.PROPERTIES);
            applySelectFilter(state, 'property');
            updateObjectSummary(state);
        }).catch(function () {
            state.propertyItems = [];
            state.deviceId = '';
            applySelectFilter(state, 'property');
            updateObjectSummary(state);
        });
    }

    function loadMethods(state) {
        if (!state.methodField || !state.objectInput || !state.objectInput.value) {
            state.methodItems = [];
            applySelectFilter(state, 'method');
            updateObjectSummary(state);
            return Promise.resolve();
        }

        return fetchJson(state.baseUrl + '?ajax=1&op=methods&object=' + encodeURIComponent(state.objectInput.value)).then(function (data) {
            state.methodItems = normalizeCollection(data.METHODS);
            applySelectFilter(state, 'method');
            updateObjectSummary(state);
        }).catch(function () {
            state.methodItems = [];
            applySelectFilter(state, 'method');
            updateObjectSummary(state);
        });
    }

    function closeObjectPanel(state) {
        if (!state.objectPanel) {
            return;
        }
        state.objectPanel.hidden = true;
        if (state.objectTrigger) {
            state.objectTrigger.setAttribute('aria-expanded', 'false');
        }
    }

    function openObjectPanel(state) {
        if (!state.objectPanel) {
            return;
        }
        state.objectPanel.hidden = false;
        if (state.objectTrigger) {
            state.objectTrigger.setAttribute('aria-expanded', 'true');
        }
        renderObjectResults(state);
        if (state.objectSearch) {
            state.objectSearch.focus();
            state.objectSearch.select();
        }
    }

    function selectObject(state, value) {
        if (!state.objectInput) {
            return;
        }
        state.objectInput.value = value || '';
        state.deviceId = '';
        closeObjectPanel(state);
        updateObjectSummary(state);
        Promise.all([loadProperties(state), loadMethods(state)]).then(function () {
            updateObjectSummary(state);
        });
    }

    function refreshObjects(state) {
        return fetchJson(state.baseUrl + '?ajax=1&op=objects').then(function (data) {
            state.objectGroups = rebuildObjectSource(state.objectSource, data.OBJECTS || []);
            renderObjectResults(state);
            updateObjectSummary(state);
            if (state.objectInput && state.objectInput.value) {
                selectObject(state, state.objectInput.value);
            }
        });
    }

    function watchPopupAndRefresh(state, popup) {
        if (!popup) {
            return;
        }
        var timer = window.setInterval(function () {
            if (popup.closed) {
                window.clearInterval(timer);
                refreshObjects(state);
            }
        }, 600);
    }

    function initGroup(objectField) {
        var uniq = objectField.getAttribute('data-md-linkedobject-uniq');
        var nodes = Array.prototype.slice.call(document.querySelectorAll('[data-md-linkedobject-uniq="' + uniq + '"]'));
        if (!nodes.length) {
            return;
        }

        var state = {
            uniq: uniq,
            baseUrl: objectField.getAttribute('data-md-linkedobject-base-url') || (window.ROOTHTML || '/') + 'panel/linkedobject.html',
            objectField: nodes.find(function (node) { return node.getAttribute('data-md-linkedobject-role') === 'object'; }) || null,
            propertyField: nodes.find(function (node) { return node.getAttribute('data-md-linkedobject-role') === 'property'; }) || null,
            methodField: nodes.find(function (node) { return node.getAttribute('data-md-linkedobject-role') === 'method'; }) || null,
            objectGroups: [],
            propertyItems: [],
            methodItems: [],
            deviceId: ''
        };

        if (!state.objectField || state.objectField.dataset.mdLinkedobjectBound === '1') {
            return;
        }

        state.objectInput = moveInputIntoField(state.objectField);
        if (!state.objectInput) {
            return;
        }
        state.objectField.dataset.mdLinkedobjectBound = '1';

        if (state.propertyField) {
            state.propertyInput = moveInputIntoField(state.propertyField);
            if (!state.propertyInput) {
                state.propertyField = null;
            }
        }
        if (state.methodField) {
            state.methodInput = moveInputIntoField(state.methodField);
            if (!state.methodInput) {
                state.methodField = null;
            }
        }

        state.objectSource = state.objectField.querySelector('[data-md-linkedobject-source="objects"]');
        state.objectTrigger = state.objectField.querySelector('[data-md-linkedobject-object-trigger]');
        state.objectPanel = state.objectField.querySelector('[data-md-linkedobject-object-panel]');
        state.objectSearch = state.objectField.querySelector('[data-md-linkedobject-object-search]');
        state.objectResults = state.objectField.querySelector('[data-md-linkedobject-object-results]');
        state.openObjectAction = state.objectField.querySelector('[data-md-linkedobject-action="open-object"]');
        state.openDeviceAction = state.objectField.querySelector('[data-md-linkedobject-action="open-device"]');
        state.clearObjectAction = state.objectField.querySelector('[data-md-linkedobject-action="clear-object"]');
        state.refreshObjectsAction = state.objectField.querySelector('[data-md-linkedobject-action="refresh-objects"]');
        state.addObjectAction = state.objectField.querySelector('[data-md-linkedobject-action="add-object"]');

        if (state.propertyField && state.propertyInput) {
            state.propertySelect = state.propertyField.querySelector('[data-md-linkedobject-select="property"]');
            state.propertyFilter = state.propertyField.querySelector('[data-md-linkedobject-filter="property"]');
            state.propertyHint = state.propertyField.querySelector('[data-md-linkedobject-hint="property"]');
            state.openPropertyAction = state.propertyField.querySelector('[data-md-linkedobject-action="open-property"]');
            state.clearPropertyAction = state.propertyField.querySelector('[data-md-linkedobject-action="clear-property"]');
        }

        if (state.methodField && state.methodInput) {
            state.methodSelect = state.methodField.querySelector('[data-md-linkedobject-select="method"]');
            state.methodFilter = state.methodField.querySelector('[data-md-linkedobject-filter="method"]');
            state.methodHint = state.methodField.querySelector('[data-md-linkedobject-hint="method"]');
            state.openMethodAction = state.methodField.querySelector('[data-md-linkedobject-action="open-method"]');
            state.clearMethodAction = state.methodField.querySelector('[data-md-linkedobject-action="clear-method"]');
        }

        state.objectGroups = parseObjectOptions(state.objectSource);
        renderObjectResults(state);
        updateObjectSummary(state);

        if (state.objectTrigger && state.objectTrigger.dataset.mdBound !== '1') {
            state.objectTrigger.dataset.mdBound = '1';
            state.objectTrigger.addEventListener('click', function () {
                if (state.objectPanel.hidden) {
                    openObjectPanel(state);
                } else {
                    closeObjectPanel(state);
                }
            });
        }

        if (state.objectSearch && state.objectSearch.dataset.mdBound !== '1') {
            state.objectSearch.dataset.mdBound = '1';
            state.objectSearch.addEventListener('input', function () {
                renderObjectResults(state);
            });
        }

        if (state.objectResults && state.objectResults.dataset.mdBound !== '1') {
            state.objectResults.dataset.mdBound = '1';
            state.objectResults.addEventListener('click', function (event) {
                var option = event.target.closest('[data-md-linkedobject-object-option]');
                if (!option) {
                    return;
                }
                selectObject(state, option.getAttribute('data-md-linkedobject-object-option') || '');
            });
        }

        if (state.clearObjectAction && state.clearObjectAction.dataset.mdBound !== '1') {
            state.clearObjectAction.dataset.mdBound = '1';
            state.clearObjectAction.addEventListener('click', function () {
                if (state.objectSearch) {
                    state.objectSearch.value = '';
                }
                selectObject(state, '');
            });
        }

        if (state.refreshObjectsAction && state.refreshObjectsAction.dataset.mdBound !== '1') {
            state.refreshObjectsAction.dataset.mdBound = '1';
            state.refreshObjectsAction.addEventListener('click', function () {
                refreshObjects(state);
            });
        }

        if (state.addObjectAction && state.addObjectAction.dataset.mdBound !== '1') {
            state.addObjectAction.dataset.mdBound = '1';
            state.addObjectAction.addEventListener('click', function () {
                var popup = window.open(state.objectField.getAttribute('data-md-linkedobject-add-url') || '', 'mdLinkedObjectAdd', 'width=1200,height=800,resizable=yes,scrollbars=yes');
                watchPopupAndRefresh(state, popup);
            });
        }

        if (state.propertySelect && state.propertySelect.dataset.mdBound !== '1') {
            state.propertySelect.dataset.mdBound = '1';
            state.propertySelect.addEventListener('change', function () {
                if (state.propertyInput) {
                    state.propertyInput.value = state.propertySelect.value || '';
                }
                updateObjectSummary(state);
            });
        }

        if (state.propertyFilter && state.propertyFilter.dataset.mdBound !== '1') {
            state.propertyFilter.dataset.mdBound = '1';
            state.propertyFilter.addEventListener('input', function () {
                applySelectFilter(state, 'property');
            });
        }

        if (state.clearPropertyAction && state.clearPropertyAction.dataset.mdBound !== '1') {
            state.clearPropertyAction.dataset.mdBound = '1';
            state.clearPropertyAction.addEventListener('click', function () {
                if (state.propertyInput) {
                    state.propertyInput.value = '';
                }
                if (state.propertyFilter) {
                    state.propertyFilter.value = '';
                }
                applySelectFilter(state, 'property');
            });
        }

        if (state.methodSelect && state.methodSelect.dataset.mdBound !== '1') {
            state.methodSelect.dataset.mdBound = '1';
            state.methodSelect.addEventListener('change', function () {
                if (state.methodInput) {
                    state.methodInput.value = state.methodSelect.value || '';
                }
                updateObjectSummary(state);
            });
        }

        if (state.methodFilter && state.methodFilter.dataset.mdBound !== '1') {
            state.methodFilter.dataset.mdBound = '1';
            state.methodFilter.addEventListener('input', function () {
                applySelectFilter(state, 'method');
            });
        }

        if (state.clearMethodAction && state.clearMethodAction.dataset.mdBound !== '1') {
            state.clearMethodAction.dataset.mdBound = '1';
            state.clearMethodAction.addEventListener('click', function () {
                if (state.methodInput) {
                    state.methodInput.value = '';
                }
                if (state.methodFilter) {
                    state.methodFilter.value = '';
                }
                applySelectFilter(state, 'method');
            });
        }

        if (state.objectField.dataset.mdOutsideBound !== '1') {
            state.objectField.dataset.mdOutsideBound = '1';
            document.addEventListener('click', function (event) {
                if (!state.objectField.contains(event.target)) {
                    closeObjectPanel(state);
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeObjectPanel(state);
                }
            });
        }

        if (state.objectInput.value) {
            selectObject(state, state.objectInput.value);
        } else {
            applySelectFilter(state, 'property');
            applySelectFilter(state, 'method');
        }
    }

    function init(root) {
        (root || document).querySelectorAll('[data-md-linkedobject-role="object"]').forEach(initGroup);
    }

    window.MDLinkedObjectUI = {
        init: init
    };

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('linkedobject', window.MDLinkedObjectUI);
    } else {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    }
})(window, document);
