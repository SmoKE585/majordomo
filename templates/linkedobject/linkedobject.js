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
            state.propertyTomSelect.setValue(state.propertyInput ? (state.propertyInput.value || '') : currentValue, true);
            state.propertyTomSelect.control_input.disabled = !state.propertyItems.length;
            if (!state.propertyItems.length) {
                state.propertyTomSelect.clear(true);
            }
            state.propertyTomSelect.lock();
            if (state.propertyItems.length) {
                state.propertyTomSelect.unlock();
            }
        }
    }

    function syncMethodField(state) {
        if (!state.methodField || !state.methodSelect) {
            return;
        }

        var currentValue = state.methodInput ? state.methodInput.value : '';
        buildPlainSelect(state.methodSelect, state.methodItems, state.methodInput ? state.methodInput.value : '');
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
            state.methodTomSelect.setValue(state.methodInput ? (state.methodInput.value || '') : currentValue, true);
            state.methodTomSelect.control_input.disabled = !state.methodItems.length;
            if (!state.methodItems.length) {
                state.methodTomSelect.clear(true);
            }
            state.methodTomSelect.lock();
            if (state.methodItems.length) {
                state.methodTomSelect.unlock();
            }
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

        return fetchJson(state.baseUrl + '?ajax=1&op=properties&object=' + encodeURIComponent(state.objectInput.value)).then(function (data) {
            state.deviceId = data.DEVICE_ID || '';
            state.propertyItems = normalizeCollection(data.PROPERTIES);
            syncPropertyField(state);
            syncObjectMeta(state);
        }).catch(function () {
            state.deviceId = '';
            state.propertyItems = [];
            syncPropertyField(state);
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

        return fetchJson(state.baseUrl + '?ajax=1&op=methods&object=' + encodeURIComponent(state.objectInput.value)).then(function (data) {
            state.methodItems = normalizeCollection(data.METHODS);
            syncMethodField(state);
            syncObjectMeta(state);
        }).catch(function () {
            state.methodItems = [];
            syncMethodField(state);
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

        state.propertyTomSelect = initLinkedTomSelect(state.propertySelect, {
            searchField: ['text', 'value'],
            placeholder: state.propertyField.getAttribute('data-md-linkedobject-label') || 'Свойство',
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

        state.methodTomSelect = initLinkedTomSelect(state.methodSelect, {
            searchField: ['text', 'value'],
            placeholder: state.methodField.getAttribute('data-md-linkedobject-label') || 'Метод',
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
            buildObjectSelect(state.objectSelect, data.OBJECTS || [], currentValue);
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
