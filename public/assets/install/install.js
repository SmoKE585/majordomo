/*
 * First-run setup wizard.
 *
 * No framework and no jQuery: this page boots without the admin shell bundle.
 * All behaviour is progressive enhancement. If JavaScript is disabled, every
 * step is visible and the native form still submits to the server.
 */
(function () {
    'use strict';

    var config = window.MDInstallConfig || {};
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function $(selector, root) {
        return (root || document).querySelector(selector);
    }

    function $$(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    /* ---------------------------------------------------------------------
       Theme
       --------------------------------------------------------------------- */

    function currentTheme() {
        return document.documentElement.getAttribute('data-bs-theme') === 'light' ? 'light' : 'dark';
    }

    function applyTheme(theme) {
        var next = theme === 'light' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        var icon = $('[data-install-theme-icon]');
        if (icon) {
            icon.className = next === 'dark' ? 'ti ti-sun' : 'ti ti-moon';
        }
        try {
            document.cookie = (config.themeCookie || 'md_install_theme') + '=' + next + '; path=/; max-age=31536000; samesite=lax';
        } catch (ignore) {
            /* cookies may be disabled; the page still works */
        }
    }

    applyTheme(currentTheme());

    $$('[data-install-theme-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
        });
    });

    /* ---------------------------------------------------------------------
       Copy buttons (success screen)
       --------------------------------------------------------------------- */

    $$('[data-install-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            var text = button.getAttribute('data-install-copy') || '';
            var done = function () {
                var original = button.innerHTML;
                button.textContent = 'Скопировано';
                window.setTimeout(function () { button.innerHTML = original; }, 1800);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {});
                return;
            }
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); done(); } catch (ignore) {}
            document.body.removeChild(area);
        });
    });

    /* ---------------------------------------------------------------------
       Password reveal (MDJUI::input password_toggle)
       --------------------------------------------------------------------- */

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-md-password-toggle]');
        if (!button) {
            return;
        }
        var id = button.getAttribute('data-md-password-toggle');
        var input = id ? document.getElementById(id) : null;
        if (!input) {
            return;
        }
        var reveal = input.getAttribute('type') === 'password';
        input.setAttribute('type', reveal ? 'text' : 'password');
        button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
        button.setAttribute('aria-label', reveal ? 'Скрыть пароль' : 'Показать пароль');
        var icon = button.querySelector('i');
        if (icon) {
            icon.className = reveal ? 'ti ti-eye-off' : 'ti ti-eye';
        }
    });

    /* ---------------------------------------------------------------------
       Wizard
       --------------------------------------------------------------------- */

    var form = $('[data-install-form]');
    if (!form) {
        return;
    }

    var steps = $$('[data-install-step]', form);
    if (!steps.length) {
        return;
    }

    var lastStep = steps.length - 1;
    var startStep = parseInt(form.getAttribute('data-start-step') || '0', 10);
    if (isNaN(startStep) || startStep < 0 || startStep > lastStep) {
        startStep = 0;
    }
    var current = startStep;
    var actions = $('.md-install-actions', form);
    var nextButton = $('[data-install-next]', form);
    var prevButton = $('[data-install-prev]', form);
    var finishButton = $('[data-install-finish]', form);
    var blocked = form.getAttribute('data-install-blocked') === '1';

    function stepOf(index) {
        return steps[index] || null;
    }

    function updateNav() {
        var label = $('[data-install-step-label]');
        if (label) {
            label.textContent = 'Шаг ' + (current + 1) + ' из ' + steps.length;
        }
        var counter = $('[data-install-step-counter]', form);
        if (counter) {
            counter.textContent = 'Шаг ' + (current + 1) + ' из ' + steps.length;
        }
        var progress = $('[data-install-progress]', form);
        if (progress) {
            progress.style.width = Math.round(((current + 1) / steps.length) * 100) + '%';
        }

        $$('[data-install-goto]', form).forEach(function (link, index) {
            link.classList.toggle('is-active', index === current);
            link.classList.toggle('is-done', index < current);
            link.setAttribute('aria-current', index === current ? 'step' : 'false');
        });

        // On phones the step list is a horizontal scroller. Keep the active
        // step visible instead of leaving it off-screen at step 3+.
        var stepList = document.querySelector('.md-install__steps');
        if (stepList && stepList.scrollWidth > stepList.clientWidth) {
            var activeLink = stepList.querySelector('.is-active');
            if (activeLink) {
                activeLink.scrollIntoView({ block: 'nearest', inline: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
            }
        }

        if (actions) {
            actions.classList.toggle('is-first', current === 0);
            actions.classList.toggle('is-last', current === lastStep);
        }
        if (nextButton) {
            nextButton.hidden = current === lastStep;
            nextButton.disabled = blocked;
        }
        if (prevButton) {
            prevButton.hidden = current === 0;
        }
        if (finishButton) {
            finishButton.style.display = current === lastStep ? '' : 'none';
        }
    }

    function showStep(index, scroll) {
        if (index < 0 || index > lastStep) {
            return;
        }
        current = index;
        steps.forEach(function (step, stepIndex) {
            var active = stepIndex === index;
            step.classList.toggle('is-active', active);
            if (active) {
                step.removeAttribute('hidden');
                step.setAttribute('aria-hidden', 'false');
            } else {
                step.setAttribute('hidden', '');
                step.setAttribute('aria-hidden', 'true');
            }
        });
        updateNav();
        if (scroll !== false) {
            window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        }
    }

    steps.forEach(function (step, index) {
        if (index !== startStep) {
            step.setAttribute('hidden', '');
            step.setAttribute('aria-hidden', 'true');
        }
        if (!step.hasAttribute('tabindex')) {
            step.setAttribute('tabindex', '-1');
        }
    });
    updateNav();

    $$('[data-install-goto]').forEach(function (link) {
        link.addEventListener('click', function () {
            var index = parseInt(link.getAttribute('data-install-goto'), 10);
            if (!isNaN(index)) {
                showStep(index);
            }
        });
    });

    if (nextButton) {
        nextButton.addEventListener('click', function () {
            if (blocked) {
                return;
            }
            if (validateStep(current)) {
                showStep(current + 1);
            }
        });
    }

    if (prevButton) {
        prevButton.addEventListener('click', function () {
            showStep(current - 1);
        });
    }

    /* ---------------------------------------------------------------------
       Validation
       --------------------------------------------------------------------- */

    function isVisible(element) {
        if (!element || element.disabled) {
            return false;
        }
        if (element.type === 'hidden') {
            return false;
        }
        return !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    }

    function controlsInStep(step, includeHidden) {
        return $$('input, select, textarea', step).filter(function (control) {
            if (control.type === 'hidden' || control.disabled) {
                return false;
            }
            if (control.name === '_token') {
                return false;
            }
            var wrapper = control.closest('[data-install-depends]');
            while (wrapper) {
                if (wrapper.hasAttribute('hidden')) {
                    return false;
                }
                wrapper = wrapper.parentElement ? wrapper.parentElement.closest('[data-install-depends]') : null;
            }
            return includeHidden ? true : isVisible(control);
        });
    }

    function customError(control) {
        var value = (control.value || '').trim();
        if (control.getAttribute('data-install-required') === '1' && value === '' && control.type !== 'checkbox') {
            return 'Заполните это поле.';
        }
        if (control.name === 'admin_password' && value !== '' && value.length < 8) {
            return 'Пароль должен быть не короче 8 символов.';
        }
        if (control.name === 'admin_password_confirm') {
            var password = form.querySelector('[name="admin_password"]');
            if (password && value !== '' && value !== password.value) {
                return 'Пароли не совпадают.';
            }
        }
        return '';
    }

    function findInvalid(step, includeHidden) {
        var controls = controlsInStep(step, !!includeHidden);
        for (var i = 0; i < controls.length; i++) {
            var control = controls[i];
            control.setCustomValidity('');
            var message = customError(control);
            if (message !== '') {
                control.setCustomValidity(message);
                return control;
            }
            if (!control.checkValidity()) {
                return control;
            }
        }
        return null;
    }

    function validateStep(index) {
        var control = findInvalid(stepOf(index), false);
        if (control) {
            control.reportValidity();
            control.focus();
            return false;
        }
        return true;
    }

    // A switch that is hidden because its parent feature is off must not keep
    // a stale "check" state in the visible form. Disabled controls are not
    // submitted, which matches the server-side dependency rules.
    function refreshDependencies() {
        var wrappers = $$('[data-install-depends]', form);
        var pass = 0;
        var changed = true;
        while (changed && pass < 6) {
            changed = false;
            pass++;
            wrappers.forEach(function (wrapper) {
                var dep = null;
                try {
                    dep = JSON.parse(wrapper.getAttribute('data-install-depends') || 'null');
                } catch (ignore) {
                    dep = null;
                }
                if (!dep || !dep.field) {
                    return;
                }
                var control = form.querySelector('[name="' + dep.field.replace(/"/g, '\\"') + '"]');
                var value = '0';
                if (control) {
                    var parentWrapper = control.closest('[data-install-depends]');
                    var parentHidden = false;
                    while (parentWrapper) {
                        if (parentWrapper.hasAttribute('hidden')) {
                            parentHidden = true;
                            break;
                        }
                        parentWrapper = parentWrapper.parentElement ? parentWrapper.parentElement.closest('[data-install-depends]') : null;
                    }
                    if (!parentHidden) {
                        if (control.type === 'checkbox') {
                            value = control.checked ? '1' : '0';
                        } else {
                            value = control.value || '';
                        }
                    }
                }
                var visible = String(value) === String(dep.value);
                if (wrapper.hasAttribute('hidden') === visible) {
                    changed = true;
                }
                if (visible) {
                    wrapper.removeAttribute('hidden');
                    $$('input, select, textarea', wrapper).forEach(function (el) {
                        el.disabled = false;
                    });
                } else {
                    wrapper.setAttribute('hidden', '');
                    $$('input, select, textarea', wrapper).forEach(function (el) {
                        el.disabled = true;
                    });
                }
            });
        }
    }

    form.addEventListener('change', refreshDependencies);
    form.addEventListener('input', refreshDependencies);
    refreshDependencies();

    form.addEventListener('submit', function (event) {
        // Validate every step, then jump to the first invalid one. Doing this
        // manually avoids the "invalid form control is not focusable" browser
        // error when a required field lives in a hidden step.
        for (var index = 0; index <= lastStep; index++) {
            var invalid = findInvalid(stepOf(index), true);
            if (invalid) {
                event.preventDefault();
                showStep(index);
                window.setTimeout(function () {
                    invalid.reportValidity();
                    invalid.focus();
                }, 30);
                return;
            }
        }
        if (finishButton) {
            finishButton.disabled = true;
            finishButton.classList.add('is-loading');
            finishButton.innerHTML = '<span class="md-spinner" aria-hidden="true"></span> Сохраняем…';
        }
    });

    // Enter should advance a step instead of submitting the whole wizard from
    // step 2. Textareas keep their normal newline behaviour.
    form.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        var target = event.target;
        if (!target || target.tagName === 'TEXTAREA' || target.tagName === 'BUTTON') {
            return;
        }
        if (current < lastStep) {
            event.preventDefault();
            if (validateStep(current)) {
                showStep(current + 1);
            }
        }
    });

    /* ---------------------------------------------------------------------
       Database test
       --------------------------------------------------------------------- */

    var testButton = $('[data-install-test-db]', form);
    if (testButton) {
        testButton.addEventListener('click', function () {
            if (testButton.disabled) {
                return;
            }
            var card = $('[data-install-db-card]', form);
            var title = $('[data-install-db-title]', form);
            var badge = $('[data-install-db-badge]', form);
            var message = $('[data-install-db-message]', form);
            var meta = $('[data-install-db-meta]', form);
            var original = testButton.innerHTML;
            testButton.disabled = true;
            testButton.classList.add('is-loading');
            testButton.innerHTML = '<span class="md-spinner" aria-hidden="true"></span> Проверяем…';

            var data = new FormData(form);
            data.append('action', 'test_db');

            fetch(form.action || config.url, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            }).then(function (response) {
                return response.json().catch(function () { return { ok: false, message: 'Сервер вернул неожиданный ответ.' }; });
            }).then(function (result) {
                var connected = !!result.connected;
                var missing = Array.isArray(result.missing) ? result.missing : [];
                var good = !!result.ok;
                if (card) {
                    card.classList.toggle('is-online', connected);
                }
                if (title) {
                    title.textContent = good
                        ? 'Соединение установлено, схема на месте'
                        : (connected ? 'Соединение есть, но схема неполная' : 'Не удалось подключиться');
                }
                if (badge) {
                    var tone = good ? 'success' : (connected ? 'warning' : 'danger');
                    var label = good ? 'онлайн' : (connected ? 'схема' : 'ошибка');
                    badge.innerHTML = '<span class="md-badge md-badge--' + tone + '">' + label + '</span>';
                }
                if (message) {
                    var text = result.message || '';
                    if (result.needs_admin) {
                        text += ' На шаге «Система» нужно будет создать администратора.';
                    }
                    message.textContent = text;
                }
                if (meta) {
                    meta.textContent = '';
                    if (result.server) {
                        var server = document.createElement('span');
                        server.textContent = 'MySQL ' + result.server;
                        meta.appendChild(server);
                    }
                    if (missing.length) {
                        var missingNode = document.createElement('span');
                        missingNode.textContent = 'Нет таблиц: ' + missing.join(', ');
                        meta.appendChild(missingNode);
                    }
                }
            }).catch(function () {
                if (title) { title.textContent = 'Не удалось проверить подключение'; }
                if (message) { message.textContent = 'Сеть недоступна. Проверьте соединение и повторите попытку.'; }
                if (badge) { badge.innerHTML = '<span class="md-badge md-badge--danger">ошибка</span>'; }
            }).then(function () {
                testButton.disabled = false;
                testButton.classList.remove('is-loading');
                testButton.innerHTML = original;
            });
        });
    }
})();
