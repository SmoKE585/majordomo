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
            var empty = page.querySelector('[data-api-empty]');
            var result = page.querySelector('[data-api-result]');
            var totalLabel = result ? result.getAttribute('data-total-label') : '';
            var total = page.querySelectorAll('[data-api-operation]').length;
            var groups = Array.prototype.slice.call(page.querySelectorAll('[data-api-group]'));

            function filter() {
                if (!search) return;
                var needle = search.value.trim().toLocaleLowerCase();
                var visible = 0;
                groups.forEach(function (group) {
                    var groupVisible = 0;
                    group.querySelectorAll('[data-api-operation]').forEach(function (operation) {
                        var matches = !needle || operation.dataset.apiOperation.indexOf(needle) !== -1;
                        operation.hidden = !matches;
                        if (matches) groupVisible++;
                    });
                    group.hidden = groupVisible === 0;
                    var nav = page.querySelector('[data-api-nav="' + group.dataset.apiGroup + '"]');
                    if (nav) {
                        nav.hidden = groupVisible === 0;
                        var count = nav.querySelector('[data-api-nav-count]');
                        if (count) count.textContent = String(groupVisible);
                    }
                    visible += groupVisible;
                });
                var active = page.querySelector('[data-api-nav].is-active');
                if (needle && active && active.hidden) {
                    active.classList.remove('is-active');
                    active.removeAttribute('aria-current');
                    var firstVisible = page.querySelector('[data-api-nav]:not([hidden])');
                    if (firstVisible) {
                        firstVisible.classList.add('is-active');
                        firstVisible.setAttribute('aria-current', 'true');
                    }
                }
                if (empty) empty.hidden = visible !== 0;
                if (result) {
                    if (!needle) result.textContent = totalLabel || total + ' методов';
                    else if (visible === 0) result.textContent = 'Ничего не найдено';
                    else result.textContent = 'Найдено ' + visible + ' из ' + total;
                }
            }

            if (search) {
                search.addEventListener('input', filter);
                filter();
            }

            var tabs = Array.prototype.slice.call(page.querySelectorAll('[data-api-tab]'));
            tabs.forEach(function (tab) {
                tab.addEventListener('shown.bs.tab', function () {
                    var name = tab.getAttribute('data-api-tab');
                    var url = new URL(window.location.href);
                    if (name === 'docs') url.searchParams.delete('tab');
                    else url.searchParams.set('tab', name);
                    window.history.replaceState(null, '', url.pathname + url.search + url.hash);
                    if (window.scrollY > 0) window.scrollTo({top: 0, behavior: 'auto'});
                });
            });

            var navLinks = Array.prototype.slice.call(page.querySelectorAll('[data-api-nav]'));
            function activate(groupIndex) {
                navLinks.forEach(function (link) {
                    var active = link.getAttribute('data-api-nav') === String(groupIndex);
                    link.classList.toggle('is-active', active);
                    if (active) link.setAttribute('aria-current', 'true');
                    else link.removeAttribute('aria-current');
                });
            }
            if ('IntersectionObserver' in window && groups.length) {
                var observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) activate(entry.target.getAttribute('data-api-group'));
                    });
                }, {rootMargin: '-96px 0px -68% 0px', threshold: 0});
                groups.forEach(function (group) { observer.observe(group); });
            }
            if (navLinks.length && groups.length) {
                var hash = window.location.hash.match(/^#md-api-group-(\d+)$/);
                activate(hash ? hash[1] : groups[0].getAttribute('data-api-group'));
            }
            navLinks.forEach(function (link) {
                link.addEventListener('click', function () { activate(link.getAttribute('data-api-nav')); });
            });

            var modalElement = page.querySelector('#md-api-confirm');
            var modal = modalElement && window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modalElement) : null;
            var pending = null;
            var busy = false;
            var feedback = page.querySelector('[data-api-feedback]');
            var feedbackText = page.querySelector('[data-api-feedback-text]');
            var status = page.querySelector('[data-api-status]');
            var secret = page.querySelector('[data-api-secret]');
            var list = page.querySelector('[data-api-token-list]');
            var confirmSubmit = modalElement ? modalElement.querySelector('[data-api-confirm-submit]') : null;
            var confirmText = modalElement ? modalElement.querySelector('[data-api-confirm-text]') : null;
            var modalTitle = modalElement ? modalElement.querySelector('.modal-title') : null;

            function announce(message) {
                if (status) status.textContent = message;
            }

            function updateCounts(stats, labels) {
                if (stats) {
                    page.querySelectorAll('[data-api-stat]').forEach(function (card) {
                        var value = card.querySelector('.md-kpi__value');
                        var key = card.getAttribute('data-api-stat');
                        if (value && Object.prototype.hasOwnProperty.call(stats, key)) value.textContent = String(stats[key]);
                    });
                    var activeMeta = page.querySelector('[data-api-stat="active"] .md-kpi__meta');
                    if (activeMeta && labels && labels.active_meta) activeMeta.textContent = labels.active_meta;
                }
                if (labels) {
                    var hero = page.querySelector('.md-api-hero .md-section-head__description');
                    if (hero) hero.textContent = labels.hero || '';
                    var tabCount = page.querySelector('[data-api-tab-count]');
                    if (tabCount) {
                        tabCount.textContent = labels.tab_total;
                        tabCount.hidden = String(labels.tab_total) === '0';
                    }
                }
            }

            function setBusy(button, value) {
                if (!button) return;
                button.disabled = value;
                button.classList.toggle('is-loading', value);
                if (value) button.setAttribute('aria-busy', 'true');
                else button.removeAttribute('aria-busy');
                var spinner = button.querySelector('[data-api-spinner]');
                if (value && !spinner) {
                    spinner = document.createElement('span');
                    spinner.className = 'md-spinner md-btn__spinner';
                    spinner.setAttribute('data-api-spinner', '');
                    spinner.setAttribute('aria-hidden', 'true');
                    button.appendChild(spinner);
                } else if (!value && spinner) {
                    spinner.remove();
                }
            }

            async function perform(form) {
                if (busy) return;
                busy = true;
                if (feedback) feedback.hidden = true;
                var button = form.querySelector('[type="submit"]');
                var operation = form.elements.operation ? form.elements.operation.value : '';
                setBusy(button, true);
                if (list) list.setAttribute('aria-busy', 'true');
                try {
                    var response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {'Accept': 'application/json'}
                    });
                    var payload;
                    try {
                        payload = await response.json();
                    } catch (parseError) {
                        throw new Error('Сервер вернул неожиданный ответ. Обновите страницу и повторите.');
                    }
                    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Операция не выполнена.');
                    if (list) list.innerHTML = payload.list_html;
                    updateCounts(payload.stats, payload.labels);
                    if (secret) {
                        secret.innerHTML = payload.secret_html || '';
                        secret.hidden = !payload.secret_html;
                        if (payload.secret_html) {
                            secret.focus();
                            secret.scrollIntoView({
                                block: 'center',
                                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth'
                            });
                        }
                    }
                    if (!payload.secret_html && list) list.focus({preventScroll: true});
                    if (operation === 'issue') form.reset();
                    var messages = {
                        issue: 'Токен выпущен.',
                        rotate: 'Токен перевыпущен.',
                        revoke: 'Токен отозван.'
                    };
                    var message = messages[operation] || 'Список токенов обновлён.';
                    announce(message);
                    if (window.MDJAdminUI) window.MDJAdminUI.boot(page);
                    if (window.MDJUIToast) window.MDJUIToast.show({type: 'success', title: 'Готово', text: message});
                } catch (error) {
                    if (feedback && feedbackText) {
                        var errorMessage = error && error.message ? error.message : '';
                        if (!errorMessage || /Failed to fetch|NetworkError|Load failed/i.test(errorMessage)) {
                            errorMessage = 'Не удалось связаться с сервером. Проверьте подключение и повторите.';
                        }
                        feedbackText.textContent = errorMessage;
                        feedback.hidden = false;
                        feedback.focus();
                    }
                    announce('Не удалось выполнить операцию.');
                } finally {
                    busy = false;
                    if (list) list.removeAttribute('aria-busy');
                    setBusy(button, false);
                }
            }

            page.addEventListener('submit', function (event) {
                var form = event.target.closest('[data-api-action]');
                if (!form) return;
                event.preventDefault();
                if (busy) return;
                if (form.dataset.apiConfirm && modal) {
                    pending = form;
                    if (confirmText) confirmText.textContent = form.dataset.apiConfirm;
                    if (modalTitle) modalTitle.textContent = form.dataset.apiConfirmTitle || 'Изменить доступ к API?';
                    var operation = form.elements.operation ? form.elements.operation.value : '';
                    if (confirmSubmit) {
                        var label = confirmSubmit.querySelector('.md-btn__label');
                        if (label) label.textContent = operation === 'rotate' ? 'Перевыпустить' : (operation === 'revoke' ? 'Отозвать' : 'Подтвердить');
                        confirmSubmit.classList.toggle('md-btn--danger', operation !== 'rotate');
                        confirmSubmit.classList.toggle('md-btn--primary', operation === 'rotate');
                    }
                    modal.show();
                } else {
                    perform(form);
                }
            });

            if (modalElement) {
                if (confirmSubmit) confirmSubmit.addEventListener('click', function () {
                    var form = pending;
                    pending = null;
                    modal.hide();
                    if (form) perform(form);
                });
                modalElement.addEventListener('hide.bs.modal', function () { pending = null; });
            }

            if (secret) window.addEventListener('pagehide', function () {
                secret.replaceChildren();
                secret.hidden = true;
            }, {once: true});
        });
    }

    if (window.MDJAdminUI) window.MDJAdminUI.registerModuleUI('api', {init: init});
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(document); }, {once: true});
    else init(document);
}(window, document));
