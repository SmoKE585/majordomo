(function (window, document) {
    'use strict';

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
        var sourceInput = findSourceInput(fieldNode, fieldId);
        var slot = fieldNode.querySelector('[data-md-linkedobject-hidden-slot]');

        if (nativeInput) {
            nativeInput.classList.add('md-linkedobject-field__source-input');
            if (nativeInput.type !== 'hidden') {
                nativeInput.type = 'hidden';
            }

            if (sourceInput && sourceInput !== nativeInput && !fieldNode.contains(sourceInput)) {
                if (!nativeInput.value && typeof sourceInput.value !== 'undefined') {
                    nativeInput.value = sourceInput.value || '';
                }
                sourceInput.replaceWith(fieldNode);
            }

            return nativeInput;
        }

        if (!sourceInput) {
            fieldNode.remove();
            return null;
        }

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

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/["\\]/g, '\\$&');
    }

    function findSourceInput(fieldNode, fieldId) {
        var selector = '[id="' + cssEscape(fieldId) + '"], [name="' + cssEscape(fieldId) + '"]';
        var candidates = Array.prototype.slice.call(document.querySelectorAll(selector));

        var external = candidates.find(function (node) {
            return !fieldNode.contains(node);
        });

        if (external) {
            return external;
        }

        return document.getElementById(fieldId);
    }

    function isFieldStillInStash(fieldNode) {
        return !!(fieldNode && fieldNode.parentElement && fieldNode.parentElement.classList && fieldNode.parentElement.classList.contains('md-linkedobject-stash'));
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

    function normalizeCollection(items) {
        return (items || []).map(function (item) {
            var title = item.TITLE || '';
            var description = item.DESCRIPTION || '';
            return {
                value: title,
                label: description ? (title + ' - ' + description) : title
            };
        });
    }

    function extractObjectsPayload(data) {
        if (Array.isArray(data)) {
            return data;
        }
        if (data && Array.isArray(data.OBJECTS)) {
            return data.OBJECTS;
        }
        return [];
    }

    function extractPropertiesPayload(data) {
        if (Array.isArray(data)) {
            return {
                items: data,
                deviceId: ''
            };
        }
        return {
            items: data && Array.isArray(data.PROPERTIES) ? data.PROPERTIES : [],
            deviceId: data && data.DEVICE_ID ? data.DEVICE_ID : ''
        };
    }

    function extractMethodsPayload(data) {
        if (Array.isArray(data)) {
            return data;
        }
        if (data && Array.isArray(data.METHODS)) {
            return data.METHODS;
        }
        return [];
    }

    function buildPlainSelect(select, items, selectedValue) {
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

    function buildObjectSelect(select, objects, selectedValue) {
        if (!select) {
            return;
        }

        var groups = {};
        select.innerHTML = '<option value=""></option>';

        (objects || []).forEach(function (item) {
            var groupTitle = item.CLASS_NAME || 'Objects';
            if (!groups[groupTitle]) {
                var optgroup = document.createElement('optgroup');
                optgroup.label = groupTitle;
                groups[groupTitle] = optgroup;
                select.appendChild(optgroup);
            }

            var option = document.createElement('option');
            option.value = item.TITLE || '';
            option.textContent = item.DESCRIPTION ? (item.TITLE + ' - ' + item.DESCRIPTION) : (item.TITLE || '');
            option.setAttribute('data-md-description', item.DESCRIPTION || '');
            if (selectedValue && selectedValue === option.value) {
                option.selected = true;
            }
            groups[groupTitle].appendChild(option);
        });
    }

    function syncSelectSelectedValue(select, value) {
        if (!select) {
            return;
        }

        var normalizedValue = value || '';
        var hasMatch = false;

        Array.prototype.slice.call(select.options).forEach(function (option) {
            var selected = option.value === normalizedValue;
            option.selected = selected;
            if (selected) {
                hasMatch = true;
            }
        });

        if (!hasMatch) {
            select.value = '';
        } else {
            select.value = normalizedValue;
        }
    }

    function syncTomSelectOptions(instance, items, selectedValue, enabled) {
        if (!instance) {
            return;
        }

        instance.clear(true);
        instance.clearOptions();
        instance.addOptions((items || []).map(function (item) {
            return {
                value: item.value,
                text: item.label
            };
        }));
        instance.refreshOptions(false);

        if (enabled) {
            instance.enable();
        } else {
            instance.disable();
        }

        if (selectedValue && (items || []).some(function (item) { return item.value === selectedValue; })) {
            instance.setValue(selectedValue, true);
        } else {
            instance.clear(true);
        }
    }

    function setFieldLoading(field, hint, loading, text) {
        if (!field) {
            return;
        }

        field.classList.toggle('is-loading', !!loading);
        if (hint && text) {
            hint.textContent = text;
        }
        if (hint) {
            hint.classList.toggle('is-loading', !!loading);
        }
    }

    function renderObjectHint(state) {
        if (!state.objectHint) {
            return;
        }

        if (!state.objectInput || !state.objectInput.value) {
            state.objectHint.textContent = 'Выберите объект и загрузите привязки';
            return;
        }

        var parts = [];
        if (state.currentObjectGroup) {
            parts.push(state.currentObjectGroup);
        }
        if (state.propertyItems.length || state.methodItems.length) {
            parts.push('Свойства: ' + state.propertyItems.length + ' · Методы: ' + state.methodItems.length);
        }
        state.objectHint.textContent = parts.join(' · ') || state.objectInput.value;
    }

    function syncObjectActions(state) {
        var objectValue = state.objectInput ? state.objectInput.value : '';
        var baseUrl = state.baseUrl;

        setActionVisible(state.openObjectAction, !!objectValue, baseUrl + '?op=redirect&object=' + encodeURIComponent(objectValue));
        setActionVisible(state.openPropertyAction, !!objectValue, baseUrl + '?op=redirect&object=' + encodeURIComponent(objectValue) + '&sub=properties');
        setActionVisible(state.openMethodAction, !!objectValue, baseUrl + '?op=redirect&object=' + encodeURIComponent(objectValue) + '&sub=methods');
        setActionVisible(state.openDeviceAction, !!state.deviceId, baseUrl + '?op=redirect&device_id=' + encodeURIComponent(state.deviceId));
    }

    function syncObjectMeta(state) {
        var selectedOption = state.objectSelect ? state.objectSelect.options[state.objectSelect.selectedIndex] : null;
        var parentGroup = selectedOption && selectedOption.parentElement && selectedOption.parentElement.tagName === 'OPTGROUP'
            ? selectedOption.parentElement.label
            : '';

        state.currentObjectGroup = parentGroup || '';
        renderObjectHint(state);
        syncObjectActions(state);
    }

    function syncPropertyField(state) {
        if (!state.propertyField || !state.propertySelect) {
            return;
        }

        var currentValue = state.propertyInput ? state.propertyInput.value : '';
        buildPlainSelect(state.propertySelect, state.propertyItems, state.propertyInput ? state.propertyInput.value : '');
        syncSelectSelectedValue(state.propertySelect, state.propertyInput ? state.propertyInput.value : currentValue);
        state.propertySelect.disabled = !state.propertyItems.length;
        state.propertyField.classList.toggle('is-empty', !state.propertyItems.length);
        if (state.propertyHint) {
            state.propertyHint.textContent = state.propertyItems.length
                ? ('Доступно свойств: ' + state.propertyItems.length)
                : (state.propertyField.getAttribute('data-md-linkedobject-empty') || 'Нет данных');
        }

        if (state.propertyInput && !state.propertyItems.some(function (item) { return item.value === state.propertyInput.value; })) {
            state.propertyInput.value = '';
        }

        initPropertyTomSelect(state);
        if (state.propertyTomSelect) {
            syncTomSelectOptions(
                state.propertyTomSelect,
                state.propertyItems,
                state.propertyInput ? (state.propertyInput.value || '') : currentValue,
                !!state.propertyItems.length
            );
        }
    }

    function syncMethodField(state) {
        if (!state.methodField || !state.methodSelect) {
            return;
        }

        var currentValue = state.methodInput ? state.methodInput.value : '';
        buildPlainSelect(state.methodSelect, state.methodItems, state.methodInput ? state.methodInput.value : '');
        syncSelectSelectedValue(state.methodSelect, state.methodInput ? state.methodInput.value : currentValue);
        state.methodSelect.disabled = !state.methodItems.length;
        state.methodField.classList.toggle('is-empty', !state.methodItems.length);
        if (state.methodHint) {
            state.methodHint.textContent = state.methodItems.length
                ? ('Доступно методов: ' + state.methodItems.length)
                : (state.methodField.getAttribute('data-md-linkedobject-empty') || 'Нет данных');
        }

        if (state.methodInput && !state.methodItems.some(function (item) { return item.value === state.methodInput.value; })) {
            state.methodInput.value = '';
        }

        initMethodTomSelect(state);
        if (state.methodTomSelect) {
            syncTomSelectOptions(
                state.methodTomSelect,
                state.methodItems,
                state.methodInput ? (state.methodInput.value || '') : currentValue,
                !!state.methodItems.length
            );
        }
    }

    function updateAllSummaries(state) {
        syncPropertyField(state);
        syncMethodField(state);
        syncObjectMeta(state);
    }

    function loadProperties(state) {
        if (!state.propertyField || !state.objectInput || !state.objectInput.value) {
            state.propertyItems = [];
            state.deviceId = '';
            syncPropertyField(state);
            syncObjectMeta(state);
            return Promise.resolve();
        }

        setFieldLoading(state.propertyField, state.propertyHint, true, 'Загрузка свойств...');
        return fetchJson(state.baseUrl + '?ajax=1&op=properties&object=' + encodeURIComponent(state.objectInput.value)).then(function (data) {
            var payload = extractPropertiesPayload(data);
            state.deviceId = payload.deviceId || '';
            state.propertyItems = normalizeCollection(payload.items);
            syncPropertyField(state);
            setFieldLoading(state.propertyField, state.propertyHint, false);
            syncObjectMeta(state);
        }).catch(function () {
            state.deviceId = '';
            state.propertyItems = [];
            syncPropertyField(state);
            setFieldLoading(state.propertyField, state.propertyHint, false);
            syncObjectMeta(state);
        });
    }

    function loadMethods(state) {
        if (!state.methodField || !state.objectInput || !state.objectInput.value) {
            state.methodItems = [];
            syncMethodField(state);
            syncObjectMeta(state);
            return Promise.resolve();
        }

        setFieldLoading(state.methodField, state.methodHint, true, 'Загрузка методов...');
        return fetchJson(state.baseUrl + '?ajax=1&op=methods&object=' + encodeURIComponent(state.objectInput.value)).then(function (data) {
            state.methodItems = normalizeCollection(extractMethodsPayload(data));
            syncMethodField(state);
            setFieldLoading(state.methodField, state.methodHint, false);
            syncObjectMeta(state);
        }).catch(function () {
            state.methodItems = [];
            syncMethodField(state);
            setFieldLoading(state.methodField, state.methodHint, false);
            syncObjectMeta(state);
        });
    }

    function handleObjectChange(state, value) {
        if (!state.objectInput) {
            return;
        }

        if ((state.objectInput.value || '') === (value || '') && state.lastLoadedObjectValue === (value || '')) {
            syncObjectMeta(state);
            return;
        }

        state.objectInput.value = value || '';
        state.lastLoadedObjectValue = value || '';
        state.deviceId = '';
        if (!value && state.propertyInput) {
            state.propertyInput.value = '';
        }
        if (!value && state.methodInput) {
            state.methodInput.value = '';
        }

        Promise.all([loadProperties(state), loadMethods(state)]).then(function () {
            syncObjectMeta(state);
        });
    }

    function renderTomOption(data, escape) {
        var option = data.$option;
        var description = option ? (option.getAttribute('data-md-description') || '') : '';
        var group = data.optgroup || '';
        var meta = '';

        if (group) {
            meta = group;
        }
        if (description) {
            meta += (meta ? ' · ' : '') + description;
        }

        return '<div class="md-linkedobject-option">' +
            '<span class="md-linkedobject-option__title">' + escape(data.value || data.text || '') + '</span>' +
            (meta ? '<span class="md-linkedobject-option__meta">' + escape(meta) + '</span>' : '') +
        '</div>';
    }

    function renderSimpleTomOption(data, escape) {
        return '<div class="md-linkedobject-option">' +
            '<span class="md-linkedobject-option__title">' + escape(data.value || data.text || '') + '</span>' +
        '</div>';
    }

    function initLinkedTomSelect(select, config) {
        if (!select || typeof window.TomSelect !== 'function') {
            return;
        }

        if (select.tomselect) {
            select.tomselect.destroy();
        }

        return new window.TomSelect(select, {
            maxItems: 1,
            allowEmptyOption: true,
            closeAfterSelect: true,
            plugins: ['clear_button'],
            ...config
        });
    }

    function initObjectTomSelect(state) {
        syncSelectSelectedValue(state.objectSelect, state.objectInput ? state.objectInput.value : '');
        state.objectTomSelect = initLinkedTomSelect(state.objectSelect, {
            searchField: ['text', 'value'],
            placeholder: state.objectField.getAttribute('data-md-linkedobject-placeholder') || 'Выберите объект',
            render: {
                option: renderTomOption
            },
            onChange: function (value) {
                handleObjectChange(state, value);
            }
        });
    }

    function initPropertyTomSelect(state) {
        if (!state.propertySelect) {
            return;
        }

        syncSelectSelectedValue(state.propertySelect, state.propertyInput ? state.propertyInput.value : '');
        state.propertyTomSelect = initLinkedTomSelect(state.propertySelect, {
            searchField: ['text', 'value'],
            placeholder: state.propertyField.getAttribute('data-md-linkedobject-placeholder') || state.propertyField.getAttribute('data-md-linkedobject-label') || 'Свойство',
            render: {
                option: renderSimpleTomOption
            },
            onChange: function (value) {
                if (state.propertyInput) {
                    state.propertyInput.value = value || '';
                }
                syncObjectMeta(state);
            }
        });
    }

    function initMethodTomSelect(state) {
        if (!state.methodSelect) {
            return;
        }

        syncSelectSelectedValue(state.methodSelect, state.methodInput ? state.methodInput.value : '');
        state.methodTomSelect = initLinkedTomSelect(state.methodSelect, {
            searchField: ['text', 'value'],
            placeholder: state.methodField.getAttribute('data-md-linkedobject-placeholder') || state.methodField.getAttribute('data-md-linkedobject-label') || 'Метод',
            render: {
                option: renderSimpleTomOption
            },
            onChange: function (value) {
                if (state.methodInput) {
                    state.methodInput.value = value || '';
                }
                syncObjectMeta(state);
            }
        });
    }

    function refreshObjects(state) {
        var currentValue = state.objectInput ? state.objectInput.value : '';

        return fetchJson(state.baseUrl + '?ajax=1&op=objects').then(function (data) {
            buildObjectSelect(state.objectSelect, extractObjectsPayload(data), currentValue);
            initObjectTomSelect(state);
            if (state.objectTomSelect) {
                state.objectTomSelect.setValue(currentValue || '', true);
            }
            syncObjectMeta(state);
            if (currentValue) {
                handleObjectChange(state, currentValue);
            }
        });
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
            propertyItems: [],
            methodItems: [],
            deviceId: '',
            currentObjectGroup: ''
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

        state.objectSelect = state.objectField.querySelector('[data-md-linkedobject-object-select]');
        state.objectHint = state.objectField.querySelector('[data-md-linkedobject-hint="object"]');
        state.openObjectAction = state.objectField.querySelector('[data-md-linkedobject-action="open-object"]');
        state.openDeviceAction = state.objectField.querySelector('[data-md-linkedobject-action="open-device"]');
        state.clearObjectAction = state.objectField.querySelector('[data-md-linkedobject-action="clear-object"]');
        state.refreshObjectsAction = state.objectField.querySelector('[data-md-linkedobject-action="refresh-objects"]');

        if (state.propertyField && state.propertyInput) {
            state.propertySelect = state.propertyField.querySelector('[data-md-linkedobject-select="property"]');
            state.propertyHint = state.propertyField.querySelector('[data-md-linkedobject-hint="property"]');
            state.openPropertyAction = state.propertyField.querySelector('[data-md-linkedobject-action="open-property"]');
            state.clearPropertyAction = state.propertyField.querySelector('[data-md-linkedobject-action="clear-property"]');
        }

        if (state.methodField && state.methodInput) {
            state.methodSelect = state.methodField.querySelector('[data-md-linkedobject-select="method"]');
            state.methodHint = state.methodField.querySelector('[data-md-linkedobject-hint="method"]');
            state.openMethodAction = state.methodField.querySelector('[data-md-linkedobject-action="open-method"]');
            state.clearMethodAction = state.methodField.querySelector('[data-md-linkedobject-action="clear-method"]');
        }

        initObjectTomSelect(state);
        initPropertyTomSelect(state);
        initMethodTomSelect(state);

        if (state.objectSelect && state.objectSelect.dataset.mdBound !== '1') {
            state.objectSelect.dataset.mdBound = '1';
            state.objectSelect.addEventListener('change', function () {
                handleObjectChange(state, state.objectSelect.value || '');
            });
        }

        if (state.objectTomSelect) {
            state.objectTomSelect.setValue(state.objectInput.value || '', true);
        } else if (state.objectSelect) {
            state.objectSelect.value = state.objectInput.value || '';
        }

        if (state.clearObjectAction && state.clearObjectAction.dataset.mdBound !== '1') {
            state.clearObjectAction.dataset.mdBound = '1';
            state.clearObjectAction.addEventListener('click', function () {
                if (state.objectTomSelect) {
                    state.objectTomSelect.clear(true);
                } else if (state.objectSelect) {
                    state.objectSelect.value = '';
                }
                handleObjectChange(state, '');
            });
        }

        if (state.refreshObjectsAction && state.refreshObjectsAction.dataset.mdBound !== '1') {
            state.refreshObjectsAction.dataset.mdBound = '1';
            state.refreshObjectsAction.addEventListener('click', function () {
                refreshObjects(state);
            });
        }

        if (state.propertySelect && state.propertySelect.dataset.mdBound !== '1') {
            state.propertySelect.dataset.mdBound = '1';
            state.propertySelect.addEventListener('change', function () {
                if (state.propertyInput) {
                    state.propertyInput.value = state.propertySelect.value || '';
                }
                syncObjectMeta(state);
            });
        }

        if (state.clearPropertyAction && state.clearPropertyAction.dataset.mdBound !== '1') {
            state.clearPropertyAction.dataset.mdBound = '1';
            state.clearPropertyAction.addEventListener('click', function () {
                if (state.propertyInput) {
                    state.propertyInput.value = '';
                }
                syncPropertyField(state);
            });
        }

        if (state.methodSelect && state.methodSelect.dataset.mdBound !== '1') {
            state.methodSelect.dataset.mdBound = '1';
            state.methodSelect.addEventListener('change', function () {
                if (state.methodInput) {
                    state.methodInput.value = state.methodSelect.value || '';
                }
                syncObjectMeta(state);
            });
        }

        if (state.clearMethodAction && state.clearMethodAction.dataset.mdBound !== '1') {
            state.clearMethodAction.dataset.mdBound = '1';
            state.clearMethodAction.addEventListener('click', function () {
                if (state.methodInput) {
                    state.methodInput.value = '';
                }
                syncMethodField(state);
            });
        }

        updateAllSummaries(state);

        if (state.objectInput.value) {
            handleObjectChange(state, state.objectInput.value);
        }

        ensureMounted(state, 12);
    }

    function ensureMounted(state, attemptsLeft) {
        if (!state || attemptsLeft <= 0) {
            return;
        }

        var needsRetry = false;

        if (isFieldStillInStash(state.objectField)) {
            state.objectInput = moveInputIntoField(state.objectField) || state.objectInput;
            needsRetry = true;
        }

        if (state.propertyField && isFieldStillInStash(state.propertyField)) {
            state.propertyInput = moveInputIntoField(state.propertyField) || state.propertyInput;
            needsRetry = true;
        }

        if (state.methodField && isFieldStillInStash(state.methodField)) {
            state.methodInput = moveInputIntoField(state.methodField) || state.methodInput;
            needsRetry = true;
        }

        if (needsRetry) {
            window.setTimeout(function () {
                ensureMounted(state, attemptsLeft - 1);
            }, 120);
        }
    }

    function init(root) {
        Array.prototype.slice.call((root || document).querySelectorAll('[data-md-linkedobject-role="object"]')).forEach(initGroup);
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
