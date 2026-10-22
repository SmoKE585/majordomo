(() => {
    'use strict';
    const init = (root = document) => {
        root.querySelectorAll('[data-md-zigbee]').forEach(page => {
            if (page.dataset.zigbeeReady) return;
            page.dataset.zigbeeReady = '1';
            page.addEventListener('click', event => {
                const remove = event.target.closest('[data-md-zigbee-remove-pair]');
                if (remove) {
                    const row = remove.closest('[data-md-zigbee-pair]');
                    const next = row.nextElementSibling || row.previousElementSibling;
                    row.remove();
                    next?.querySelector('input')?.focus();
                }
                const add = event.target.closest('[data-md-zigbee-add-pair]');
                if (!add) return;
                const rule = add.closest('[data-md-zigbee-rule]');
                const list = rule.querySelector('[data-md-zigbee-pairs]');
                if (list.children.length >= 64) return;
                const source = list.firstElementChild || rule.pairTemplate;
                if (!source) return;
                const row = source.cloneNode(true);
                row.querySelectorAll('input').forEach(input => {
                    const oldId = input.id;
                    input.value = '';
                    input.id = `${rule.dataset.mdZigbeeRule}-${crypto.randomUUID()}`;
                    row.querySelectorAll('label').forEach(label => { if (label.htmlFor === oldId) label.htmlFor = input.id; });
                });
                list.append(row);
                row.querySelector('input')?.focus();
            });
            page.querySelectorAll('[data-md-zigbee-rule]').forEach(rule => {
                rule.pairTemplate = rule.querySelector('[data-md-zigbee-pair]')?.cloneNode(true);
            });
        });
    };
    if (window.MDJAdminUI) window.MDJAdminUI.registerModuleUI('zigbee', { init });
    else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init());
    else init();
})();
