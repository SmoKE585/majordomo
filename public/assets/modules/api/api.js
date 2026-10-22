(function (window, document) {
    'use strict';
    function init(root) {
        var pages = [];
        if (root.matches && root.matches('[data-md-api]')) pages.push(root);
        pages = pages.concat(Array.from(root.querySelectorAll('[data-md-api]')));
        pages.forEach(function (page) {
            if (page.dataset.apiBound) return;
            page.dataset.apiBound = '1';
            var search = page.querySelector('[data-api-search]');
            search.addEventListener('input', function () {
                var needle = search.value.trim().toLocaleLowerCase();
                var visible = 0;
                page.querySelectorAll('[data-api-operation]').forEach(function (operation) {
                    operation.hidden = !operation.dataset.apiOperation.includes(needle);
                    if (!operation.hidden) visible++;
                });
                page.querySelector('[data-api-empty]').hidden = visible > 0;
            });
            var modalElement = page.querySelector('#md-api-confirm');
            var modal = window.bootstrap.Modal.getOrCreateInstance(modalElement);
            var pending = null;
            var busy = false;
            var feedback = page.querySelector('[data-api-feedback]');
            var secret = page.querySelector('[data-api-secret]');
            async function perform(form) {
                if (busy) return;
                busy = true;
                feedback.hidden = true;
                var button = form.querySelector('[type="submit"]');
                button.disabled = true;
                button.classList.add('is-loading');
                button.setAttribute('aria-busy', 'true');
                try {
                    var response = await fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}});
                    var payload = await response.json();
                    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Операция не выполнена.');
                    page.querySelector('[data-api-token-list]').innerHTML = payload.list_html;
                    secret.innerHTML = payload.secret_html || '';
                    secret.hidden = !payload.secret_html;
                    if (payload.secret_html) {
                        secret.focus();
                        secret.scrollIntoView({block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'});
                    }
                    if (form.elements.operation.value === 'issue') form.reset();
                    if (window.MDJAdminUI) window.MDJAdminUI.boot(page);
                    if (window.MDJUIToast) window.MDJUIToast.show({type: 'success', title: 'Готово', text: 'Доступ к API обновлён.'});
                } catch (error) {
                    feedback.textContent = error.message || 'Не удалось связаться с сервером.';
                    feedback.className = 'md-ui-note';
                    feedback.hidden = false;
                    feedback.setAttribute('tabindex', '-1');
                    feedback.focus();
                } finally {
                    busy = false;
                    button.disabled = false;
                    button.classList.remove('is-loading');
                    button.removeAttribute('aria-busy');
                }
            }
            page.addEventListener('submit', function (event) {
                var form = event.target.closest('[data-api-action]');
                if (!form) return;
                event.preventDefault();
                if (busy) return;
                if (form.dataset.apiConfirm) {
                    pending = form;
                    modalElement.querySelector('[data-api-confirm-text]').textContent = form.dataset.apiConfirm;
                    modal.show();
                } else perform(form);
            });
            modalElement.querySelector('[data-api-confirm-submit]').addEventListener('click', function () {
                var form = pending;
                pending = null;
                modal.hide();
                if (form) perform(form);
            });
            modalElement.addEventListener('hide.bs.modal', function () { pending = null; });
            window.addEventListener('pagehide', function () { secret.replaceChildren(); secret.hidden = true; }, {once: true});
        });
    }
    if (window.MDJAdminUI) window.MDJAdminUI.registerModuleUI('api', {init: init});
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(document); }, {once: true});
    else init(document);
}(window, document));
