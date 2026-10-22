(() => {
    'use strict';
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
                showFields(rule, '[data-trigger-fields]', rule.querySelector('[data-rule-trigger]').value);
                rule.querySelectorAll('[data-mode-condition]').forEach(updateCondition);
            };
            const refresh = () => {
                const items = rules ? [...rules.querySelectorAll('[data-mode-rule]')] : [];
                items.forEach((rule, index) => { rule.querySelector('[data-rule-number]').textContent = String(index + 1); updateRule(rule); });
                const empty = page.querySelector('[data-rules-empty]');
                if (empty) empty.hidden = items.length > 0;
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
                if (event.target.closest('[data-add-rule]') && rules && rules.children.length < 32) {
                    const index = String(++sequence);
                    rules.append(clone('[data-rule-template]', { '__R__': index }));
                    rules.lastElementChild.dataset.ruleIndex = index;
                    refresh();
                    rules.lastElementChild.querySelector('select')?.focus();
                }
                const addCondition = event.target.closest('[data-add-condition]');
                if (addCondition) {
                    const rule = addCondition.closest('[data-mode-rule]');
                    const list = rule.querySelector('[data-mode-conditions]');
                    if (list.children.length >= 16) return;
                    const ruleIndex = rule.dataset.ruleIndex || rule.querySelector('[data-rule-trigger]').name.match(/^rules\[([^\]]+)\]/)[1];
                    list.append(clone('[data-condition-template]', { '__R__': ruleIndex, '__C__': String(++sequence) }));
                    updateCondition(list.lastElementChild);
                    list.lastElementChild.querySelector('select')?.focus();
                }
                const removeRule = event.target.closest('[data-remove-rule]');
                if (removeRule) { removeRule.closest('[data-mode-rule]').remove(); refresh(); page.querySelector('[data-add-rule]')?.focus(); }
                const removeCondition = event.target.closest('[data-remove-condition]');
                if (removeCondition) {
                    const rule = removeCondition.closest('[data-mode-rule]');
                    removeCondition.closest('[data-mode-condition]').remove();
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
