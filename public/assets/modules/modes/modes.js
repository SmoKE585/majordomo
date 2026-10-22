(() => {
    'use strict';
    const MAX_RULES = 32;
    const MAX_CONDITIONS = 16;
    const init = (root = document) => {
        const pages = [...root.querySelectorAll('[data-md-modes]')];
        if (root.matches?.('[data-md-modes]')) pages.unshift(root);
        pages.forEach(page => {
            if (page.dataset.modesReady) return;
            page.dataset.modesReady = '1';
            let sequence = 1000;
            const rules = page.querySelector('[data-mode-rules]');
            const showFields = (container, selector, value) => {
                container.querySelectorAll(selector).forEach(section => {
                    section.hidden = section.getAttribute(selector.slice(1, -1)) !== value;
                    section.querySelectorAll('input,select,textarea').forEach(control => { control.disabled = section.hidden; });
                });
            };
            const updateCondition = condition => showFields(condition, '[data-condition-fields]', condition.querySelector('[data-condition-type]').value);
            const updateRule = rule => {
                const trigger = rule.querySelector('[data-rule-trigger]');
                if (trigger.value === 'time') {
                    const at = rule.querySelector('[name$="[at]"]');
                    if (at && !at.value) at.value = '23:00';
                }
                showFields(rule, '[data-trigger-fields]', trigger.value);
                rule.querySelectorAll('[data-mode-condition]').forEach(updateCondition);
            };
            const ruleItems = () => rules ? [...rules.querySelectorAll('[data-mode-rule]')] : [];
            const syncLimits = () => {
                const items = ruleItems();
                const addRule = page.querySelector('[data-add-rule]');
                if (addRule) addRule.disabled = items.length >= MAX_RULES;
                items.forEach(rule => {
                    const addCondition = rule.querySelector('[data-add-condition]');
                    if (addCondition) addCondition.disabled = rule.querySelectorAll('[data-mode-condition]').length >= MAX_CONDITIONS;
                });
            };
            const refresh = () => {
                const items = ruleItems();
                items.forEach((rule, index) => {
                    const number = rule.querySelector('[data-rule-number]');
                    if (number) number.textContent = String(index + 1);
                    updateRule(rule);
                });
                const empty = page.querySelector('[data-rules-empty]');
                if (empty) empty.hidden = items.length > 0;
                syncLimits();
            };
            const clone = (selector, substitutions) => {
                const fragment = page.querySelector(selector).content.cloneNode(true);
                fragment.querySelectorAll('*').forEach(element => {
                    [...element.attributes].forEach(attribute => {
                        let value = attribute.value;
                        Object.entries(substitutions).forEach(([from, to]) => { value = value.replaceAll(from, to); });
                        element.setAttribute(attribute.name, value);
                    });
                });
                return fragment;
            };
            const bootWidgets = element => window.MDJAdminUI?.initBootstrapWidgets?.(element);
            page.querySelectorAll('[data-mode-action]').forEach(action => showFields(action, '[data-action-fields]', action.querySelector('[data-action-type]').value));
            page.addEventListener('change', event => {
                if (event.target.matches('[data-rule-trigger]')) updateRule(event.target.closest('[data-mode-rule]'));
                if (event.target.matches('[data-condition-type]')) updateCondition(event.target.closest('[data-mode-condition]'));
                if (event.target.matches('[data-action-type]')) {
                    const action = event.target.closest('[data-mode-action]');
                    showFields(action, '[data-action-fields]', event.target.value);
                    window.MDCodeEditor?.init(action);
                }
            });
            page.addEventListener('click', event => {
                if (event.target.closest('[data-add-rule]') && rules && ruleItems().length < MAX_RULES) {
                    const index = String(++sequence);
                    rules.append(clone('[data-rule-template]', { '__R__': index }));
                    rules.lastElementChild.dataset.ruleIndex = index;
                    refresh();
                    bootWidgets(rules.lastElementChild);
                    rules.lastElementChild.querySelector('select')?.focus();
                }
                const addCondition = event.target.closest('[data-add-condition]');
                if (addCondition) {
                    const rule = addCondition.closest('[data-mode-rule]');
                    const list = rule.querySelector('[data-mode-conditions]');
                    if (list.children.length >= MAX_CONDITIONS) return;
                    const ruleIndex = rule.dataset.ruleIndex || rule.querySelector('[data-rule-trigger]').name.match(/^rules\[([^\]]+)\]/)[1];
                    list.append(clone('[data-condition-template]', { '__R__': ruleIndex, '__C__': String(++sequence) }));
                    updateCondition(list.lastElementChild);
                    syncLimits();
                    bootWidgets(list.lastElementChild);
                    list.lastElementChild.querySelector('select')?.focus();
                }
                const removeRule = event.target.closest('[data-remove-rule]');
                if (removeRule) {
                    removeRule.closest('[data-mode-rule]').remove();
                    refresh();
                    page.querySelector('[data-add-rule]')?.focus();
                }
                const removeCondition = event.target.closest('[data-remove-condition]');
                if (removeCondition) {
                    const rule = removeCondition.closest('[data-mode-rule]');
                    removeCondition.closest('[data-mode-condition]').remove();
                    syncLimits();
                    rule.querySelector('[data-add-condition]')?.focus();
                }
            });
            refresh();
        });
    };
    if (window.MDJAdminUI?.registerModuleUI) window.MDJAdminUI.registerModuleUI('modes', { init });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init());
    else init();
})();
