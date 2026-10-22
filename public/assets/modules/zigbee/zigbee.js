(() => {
    'use strict';
    const init = (root = document) => {
        root.querySelectorAll('[data-md-zigbee]').forEach(page => {
            if (page.dataset.zigbeeReady) return;
            page.dataset.zigbeeReady = '1';
            page.querySelectorAll('[data-md-zigbee-photo]').forEach(img => {
                const loaded = () => img.closest('[data-md-zigbee-image]').classList.add('md-zigbee-image--loaded');
                const failed = () => { img.hidden = true; img.closest('[data-md-zigbee-image]').classList.remove('md-zigbee-image--loaded'); };
                img.addEventListener('load', loaded, { once: true });
                img.addEventListener('error', failed, { once: true });
                if (img.complete) { if (img.naturalWidth > 0) loaded(); else failed(); }
            });
            page.querySelectorAll('[data-md-zigbee-adopt]').forEach(form => {
                const all = form.querySelector('[data-md-zigbee-select-all]');
                const boxes = [...form.querySelectorAll('input[name="device_ids[]"]')];
                form.addEventListener('change', event => {
                    if (event.target === all) boxes.forEach(box => { box.checked = all.checked; });
                    const selected = boxes.filter(box => box.checked).length;
                    all.checked = selected === boxes.length && selected > 0;
                    all.indeterminate = selected > 0 && selected < boxes.length;
                });
            });
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
