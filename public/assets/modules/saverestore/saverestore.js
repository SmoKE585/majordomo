(function () {
    var STATE = {
        running: false,
        finished: false,
        finishState: '',
        startedAt: 0,
        elapsedTimer: null,
        logEntries: [],
        checks: [],
        copyTimer: null,
        lastStep: 'prepare',
        lastPercent: 0,
        lastActivityAt: 0,
        stallTimer: null,
        stallWarned: false
    };

    function q(selector, root) {
        return (root || document).querySelector(selector);
    }

    function qa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function modalElement() {
        return q('[data-saverestore-update-modal]');
    }

    function pad(value) {
        return value < 10 ? '0' + value : String(value);
    }

    function formatClock(date) {
        return pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
    }

    function formatDuration(milliseconds) {
        var total = Math.max(0, Math.floor(milliseconds / 1000));
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var seconds = total % 60;
        if (hours > 0) {
            return pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
        }
        return pad(minutes) + ':' + pad(seconds);
    }

    function updateElapsed() {
        var target = document.getElementById('systemUpdateElapsed');
        if (!target) {
            return;
        }
        if (!STATE.startedAt) {
            STATE.startedAt = Date.now();
        }
        target.textContent = formatDuration(Date.now() - STATE.startedAt);
    }

    function startTimer() {
        if (STATE.elapsedTimer) {
            return;
        }
        if (!STATE.startedAt) {
            STATE.startedAt = Date.now();
        }
        updateElapsed();
        STATE.elapsedTimer = window.setInterval(updateElapsed, 1000);
    }

    function stopTimer() {
        if (STATE.elapsedTimer) {
            window.clearInterval(STATE.elapsedTimer);
            STATE.elapsedTimer = null;
        }
        updateElapsed();
    }

    // Сколько тишины терпим на этапе. Во время «Применения» идут composer, npm
    // и миграции: они присылают пульс раз в 20 секунд, поэтому тишина дольше
    // двух с половиной минут означает, что запрос действительно оборвался.
    var STALL_LIMITS = {
        download: 90000,
        apply: 150000
    };
    var STALL_DEFAULT = 120000;

    function stepLabel(step) {
        var item = q('[data-saverestore-step="' + step + '"] .md-saverestore-update-modal__step-label');
        return item && item.textContent ? item.textContent.trim() : 'выполнение';
    }

    function noteActivity() {
        STATE.lastActivityAt = Date.now();
        STATE.stallWarned = false;
        var metaHint = q('[data-saverestore-meta-hint]');
        if (metaHint && STATE.running) {
            metaHint.textContent = 'Не закрывайте окно';
        }
    }

    function startWatchdog() {
        if (STATE.stallTimer) {
            return;
        }
        STATE.stallTimer = window.setInterval(checkStall, 5000);
    }

    function stopWatchdog() {
        if (STATE.stallTimer) {
            window.clearInterval(STATE.stallTimer);
            STATE.stallTimer = null;
        }
    }

    /**
     * Показать, что сервер замолчал. Без этого окно навсегда остаётся в
     * состоянии «В процессе», и непонятно, работает обновление или умерло.
     */
    function checkStall() {
        if (!STATE.running || STATE.finished || !STATE.lastActivityAt || STATE.stallWarned) {
            return;
        }
        var limit = STALL_LIMITS[STATE.lastStep] || STALL_DEFAULT;
        var silence = Date.now() - STATE.lastActivityAt;
        if (silence < limit) {
            return;
        }
        STATE.stallWarned = true;
        var metaHint = q('[data-saverestore-meta-hint]');
        if (metaHint) {
            metaHint.textContent = 'Нет данных от сервера';
        }
        appendLog(
            'Сервер не присылает данные ' + Math.round(silence / 1000) + ' с на этапе «' + stepLabel(STATE.lastStep) +
            '». Запрос мог оборваться по таймауту PHP: дождитесь ответа либо обновите страницу и повторите.',
            'warning'
        );
    }

    function setStatusIcon(state) {
        var icon = document.getElementById('systemUpdateStatusIcon');
        if (!icon) {
            return;
        }
        var normalized = state === 'success' || state === 'error' ? state : 'active';
        icon.classList.remove('is-active', 'is-success', 'is-error');
        icon.classList.add('is-' + normalized);
        var glyph = icon.querySelector('i');
        if (glyph) {
            glyph.className = 'ti ' + (
                normalized === 'success' ? 'ti-circle-check' :
                    normalized === 'error' ? 'ti-alert-triangle' : 'ti-loader-2'
            );
        }
    }

    function setLiveBadge(state) {
        var badge = q('[data-saverestore-live-badge]');
        if (!badge) {
            return;
        }
        var normalized = state === 'success' || state === 'error' ? state : 'active';
        badge.classList.remove('md-badge--info', 'md-badge--success', 'md-badge--danger');
        badge.classList.add(
            normalized === 'success' ? 'md-badge--success' :
                normalized === 'error' ? 'md-badge--danger' : 'md-badge--info'
        );
        var label = q('[data-saverestore-live-label]', badge);
        if (label) {
            label.textContent = normalized === 'success' ? 'Готово' : normalized === 'error' ? 'Ошибка' : 'В процессе';
        }
    }

    function updateProgress(percent, state) {
        var value = Math.max(0, Math.min(100, Number(percent) || 0));
        var progress = document.getElementById('systemUpdateProgress');
        var bar = progress ? q('.md-progress__bar', progress) : null;
        var label = document.getElementById('systemUpdatePercent');
        if (bar) {
            bar.style.width = value + '%';
        }
        if (progress) {
            progress.setAttribute('aria-valuenow', String(Math.round(value)));
            progress.classList.toggle('md-progress--success', state === 'success');
            progress.classList.toggle('md-progress--danger', state === 'error');
        }
        if (label) {
            label.textContent = String(Math.round(value));
        }
    }

    function updateStep(step, state) {
        var modal = modalElement();
        if (!modal || !step) {
            return;
        }
        var steps = qa('[data-saverestore-step]', modal);
        if (!steps.length) {
            return;
        }
        var active = null;
        steps.forEach(function (item) {
            if (item.getAttribute('data-saverestore-step') === step) {
                active = item;
            }
        });
        var activeOrder = active ? parseInt(active.getAttribute('data-order'), 10) : NaN;
        var complete = state === 'success';
        steps.forEach(function (item) {
            var order = parseInt(item.getAttribute('data-order'), 10);
            var isActive = item === active;
            var isDone = !Number.isNaN(activeOrder) && !Number.isNaN(order) && order < activeOrder;
            item.classList.toggle('is-active', isActive && !complete && state !== 'error');
            item.classList.toggle('is-done', isDone || (isActive && complete));
            item.classList.toggle('is-error', isActive && state === 'error');
            if (isActive && !complete) {
                item.setAttribute('aria-current', 'step');
            } else {
                item.removeAttribute('aria-current');
            }
        });
        if (active && !Number.isNaN(activeOrder)) {
            var counter = document.getElementById('systemUpdateStepCounter');
            if (counter) {
                counter.textContent = String(activeOrder);
            }
            var stepper = q('[data-saverestore-stepper]', modal);
            if (stepper && stepper.scrollWidth > stepper.clientWidth) {
                var target = stepper.scrollLeft;
                if (active.offsetLeft < target) {
                    target = active.offsetLeft;
                }
                if (active.offsetLeft + active.offsetWidth > target + stepper.clientWidth) {
                    target = active.offsetLeft + active.offsetWidth - stepper.clientWidth;
                }
                stepper.scrollLeft = Math.max(0, target);
            }
        }
    }

    function scrollLogToBottom() {
        var log = q('[data-saverestore-log]');
        if (!log) {
            return;
        }
        log.scrollTop = log.scrollHeight;
        setJumpVisible(false);
    }

    function setJumpVisible(visible) {
        var jump = q('[data-saverestore-log-jump]');
        if (!jump) {
            return;
        }
        jump.hidden = !visible;
    }

    function logIsNearBottom(log) {
        return log.scrollHeight - log.scrollTop - log.clientHeight < 56;
    }

    function updateCopyButton() {
        var button = q('[data-saverestore-copy-log]');
        if (!button) {
            return;
        }
        button.disabled = STATE.logEntries.length === 0;
    }

    function appendLog(text, kind) {
        var log = q('[data-saverestore-log]');
        var value = String(text || '').trim();
        if (!log || !value) {
            return;
        }
        var shouldStick = logIsNearBottom(log);
        var empty = q('[data-saverestore-log-empty]', log);
        if (empty) {
            empty.remove();
        }

        var normalizedKind = ['success', 'warning', 'error', 'muted'].indexOf(kind) !== -1 ? kind : 'info';
        var now = Date.now();
        var previous = STATE.logEntries[STATE.logEntries.length - 1];
        if (previous && previous.type === 'log' && previous.text === value &&
            previous.kind === normalizedKind && previous.receivedAt && now - previous.receivedAt < 600) {
            return;
        }
        var time = formatClock(new Date(now));
        var entry = document.createElement('div');
        entry.className = 'md-saverestore-update-modal__log-entry is-' + normalizedKind;

        var timeNode = document.createElement('span');
        timeNode.className = 'md-saverestore-update-modal__log-time';
        timeNode.textContent = time;

        var iconNode = document.createElement('span');
        iconNode.className = 'md-saverestore-update-modal__log-icon';
        var glyph = document.createElement('i');
        glyph.className = 'ti ' + (
            normalizedKind === 'success' ? 'ti-circle-check' :
                normalizedKind === 'error' ? 'ti-circle-x' :
                    normalizedKind === 'warning' ? 'ti-alert-triangle' :
                        normalizedKind === 'muted' ? 'ti-minus' : 'ti-info-circle'
        );
        glyph.setAttribute('aria-hidden', 'true');
        iconNode.appendChild(glyph);

        var textNode = document.createElement('span');
        textNode.className = 'md-saverestore-update-modal__log-text';
        textNode.textContent = value;

        entry.appendChild(timeNode);
        entry.appendChild(iconNode);
        entry.appendChild(textNode);
        log.appendChild(entry);

        STATE.logEntries.push({ type: 'log', time: time, text: value, kind: normalizedKind, receivedAt: now });
        if (STATE.logEntries.length > 500) {
            STATE.logEntries.shift();
            if (log.firstElementChild) {
                log.removeChild(log.firstElementChild);
            }
        }

        if (shouldStick) {
            scrollLogToBottom();
        } else {
            setJumpVisible(true);
        }
        updateCopyButton();
    }

    function appendSection(title, description) {
        var log = q('[data-saverestore-log]');
        var value = String(title || '').trim();
        if (!log || !value) {
            return;
        }
        var shouldStick = logIsNearBottom(log);
        var empty = q('[data-saverestore-log-empty]', log);
        if (empty) {
            empty.remove();
        }

        var section = document.createElement('div');
        section.className = 'md-saverestore-update-modal__log-section';

        var heading = document.createElement('div');
        heading.className = 'md-saverestore-update-modal__log-section-title';
        heading.textContent = value;
        section.appendChild(heading);

        var descriptionText = String(description || '').trim();
        if (descriptionText) {
            var text = document.createElement('div');
            text.className = 'md-saverestore-update-modal__log-section-text';
            text.textContent = descriptionText;
            section.appendChild(text);
        }

        log.appendChild(section);
        STATE.logEntries.push({ type: 'section', title: value, description: descriptionText });

        if (shouldStick) {
            scrollLogToBottom();
        } else {
            setJumpVisible(true);
        }
        updateCopyButton();
    }

    function renderChecks(title, checks) {
        var card = q('[data-saverestore-checks-card]');
        var list = q('[data-saverestore-checks]');
        var summary = q('[data-saverestore-checks-summary]');
        var note = q('[data-saverestore-checks-note]');
        if (!list) {
            return;
        }
        var items = Array.isArray(checks) ? checks : [];
        list.innerHTML = '';
        STATE.checks = items;

        var passed = 0;
        items.forEach(function (check) {
            var ok = !!(check && check.ok);
            if (ok) {
                passed++;
            }
            var row = document.createElement('div');
            row.className = 'md-saverestore-update-modal__check ' + (ok ? 'is-ok' : 'is-fail');

            var icon = document.createElement('span');
            icon.className = 'md-saverestore-update-modal__check-icon';
            var glyph = document.createElement('i');
            glyph.className = 'ti ' + (ok ? 'ti-circle-check' : 'ti-circle-x');
            glyph.setAttribute('aria-hidden', 'true');
            icon.appendChild(glyph);

            var content = document.createElement('div');
            content.className = 'md-saverestore-update-modal__check-content';
            var rowTitle = document.createElement('div');
            rowTitle.className = 'md-saverestore-update-modal__check-title';
            rowTitle.textContent = String((check && check.title) || '');
            content.appendChild(rowTitle);

            var details = String((check && check.details) || '').trim();
            if (details) {
                var rowDetails = document.createElement('div');
                rowDetails.className = 'md-saverestore-update-modal__check-details';
                rowDetails.textContent = details;
                content.appendChild(rowDetails);
            }

            row.appendChild(icon);
            row.appendChild(content);
            list.appendChild(row);
        });

        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'md-saverestore-update-modal__checks-empty';
            empty.textContent = 'Проверки не переданы.';
            list.appendChild(empty);
        }

        if (summary) {
            summary.textContent = items.length ? passed + ' из ' + items.length : '—';
            summary.classList.remove('md-badge--neutral', 'md-badge--success', 'md-badge--danger');
            summary.classList.add(
                items.length && passed === items.length ? 'md-badge--success' :
                    passed < items.length ? 'md-badge--danger' : 'md-badge--neutral'
            );
            summary.setAttribute('title', String(title || 'Проверки'));
        }

        if (card) {
            card.classList.toggle('is-fail', passed < items.length);
        }

        if (note) {
            var allPassed = items.length > 0 && passed === items.length;
            note.hidden = !items.length || allPassed;
            note.classList.remove('is-success', 'is-danger');
            if (items.length && !allPassed) {
                note.classList.add('is-danger');
                note.textContent = 'Есть ошибки. Исправьте их и повторите запуск.';
            }
        }
    }

    function cleanLocation() {
        var modal = modalElement();
        if (!modal) {
            return;
        }
        var clean = modal.getAttribute('data-saverestore-return-url');
        if (!clean || !window.history || typeof window.history.replaceState !== 'function') {
            return;
        }
        try {
            window.history.replaceState(null, '', clean);
        } catch (error) {
            // Ignore browsers that refuse history updates in this context.
        }
    }

    function showFinishUI(state) {
        var modal = modalElement();
        if (!modal) {
            return;
        }
        var body = q('[data-saverestore-body]', modal);
        if (body) {
            body.setAttribute('aria-busy', 'false');
        }
        var close = q('.md-saverestore-update-modal__close', modal);
        var actions = q('[data-saverestore-finish-actions]', modal);
        var finishText = q('[data-saverestore-finish-text]', modal);
        var note = q('[data-saverestore-note]', modal);
        if (note) {
            note.hidden = true;
        }

        if (state === 'error') {
            if (close) {
                close.hidden = false;
            }
            if (actions) {
                actions.hidden = false;
            }
            if (finishText) {
                finishText.textContent = 'Проверьте журнал, затем вернитесь к обновлениям.';
            }
            cleanLocation();
            window.setTimeout(function () {
                var back = q('[data-saverestore-return]', modal);
                if (back) {
                    back.focus();
                }
            }, 120);
        } else {
            if (close) {
                close.hidden = true;
            }
            if (actions) {
                actions.hidden = true;
            }
            cleanLocation();
        }
    }

    function handleEvent(event) {
        if (!event || typeof event !== 'object') {
            return;
        }
        noteActivity();
        if (event.type === 'events' && Array.isArray(event.items)) {
            event.items.forEach(handleEvent);
            return;
        }
        switch (event.type) {
            case 'log':
                appendLog(event.text, event.kind);
                break;
            case 'section':
                appendSection(event.title, event.description);
                break;
            case 'checks':
                renderChecks(event.title, event.checks);
                break;
            case 'finish':
                window.systemUpdateFinish(event.text, event.state);
                break;
            default:
                break;
        }
    }

    window.systemUpdateSetStatus = function (text, state, percent, step) {
        if (STATE.finished && state === 'active') {
            return;
        }
        var normalizedState = state === 'success' || state === 'error' ? state : 'active';
        var label = document.getElementById('systemUpdateStatusText');
        if (label) {
            label.textContent = text || '';
        }
        var statusLabel = q('[data-saverestore-status-label]');
        if (statusLabel) {
            statusLabel.textContent = normalizedState === 'error' ? 'Операция остановлена' :
                normalizedState === 'success' ? 'Завершено' : 'Текущий этап';
        }
        var metaHint = q('[data-saverestore-meta-hint]');
        if (metaHint) {
            metaHint.textContent = normalizedState === 'error' ? 'Проверьте журнал' :
                normalizedState === 'success' ? 'Страница перезагрузится' : 'Не закрывайте окно';
        }
        var value = typeof percent === 'undefined' || percent === null ?
            (normalizedState === 'success' || normalizedState === 'error' ? 100 : 35) : percent;
        updateProgress(value, normalizedState);
        setStatusIcon(normalizedState);
        setLiveBadge(normalizedState);
        updateStep(step, normalizedState);

        if (normalizedState === 'active') {
            STATE.running = true;
            STATE.lastStep = step || STATE.lastStep;
            STATE.lastPercent = value;
            noteActivity();
            startTimer();
            startWatchdog();
        } else {
            STATE.running = false;
            stopTimer();
            stopWatchdog();
        }
    };

    window.systemUpdateFinish = function (text, state) {
        var normalizedState = state === 'error' ? 'error' : 'success';
        STATE.finished = true;
        STATE.finishState = normalizedState;
        STATE.running = false;
        window.onbeforeunload = null;
        var finishStep = normalizedState === 'error' ? (STATE.lastStep || 'prepare') : 'finish';
        var finishPercent = normalizedState === 'error' ? (STATE.lastPercent || 0) : 100;
        window.systemUpdateSetStatus(
            text || (normalizedState === 'error' ? 'Операция остановлена.' : 'Операция завершена.'),
            normalizedState,
            finishPercent,
            finishStep
        );
        showFinishUI(normalizedState);
    };

    window.systemUpdateEvent = handleEvent;

    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin) {
            return;
        }
        var data = event.data;
        if (!data || data.source !== 'mdj-saverestore') {
            return;
        }
        handleEvent(data);
    });

    function legacyCopy(text) {
        return new Promise(function (resolve, reject) {
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.top = '-1000px';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                var copied = document.execCommand('copy');
                document.body.removeChild(textarea);
                copied ? resolve() : reject(new Error('copy failed'));
            } catch (error) {
                document.body.removeChild(textarea);
                reject(error);
            }
        });
    }

    function copyText(text) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(text).catch(function () {
                return legacyCopy(text);
            });
        }
        return legacyCopy(text);
    }

    function plainLog() {
        return STATE.logEntries.map(function (entry) {
            if (entry.type === 'section') {
                return '\n[' + entry.title + ']' + (entry.description ? ' ' + entry.description : '') + '\n';
            }
            return '[' + entry.time + '] ' + entry.text;
        }).join('\n').trim();
    }

    function boot(root) {
        var page = root.querySelector('[data-saverestore-page]');
        if (!page) {
            return;
        }
        if (page.getAttribute('data-saverestore-booted') === '1') {
            return;
        }
        page.setAttribute('data-saverestore-booted', '1');

        var modal = page.querySelector('[data-saverestore-update-modal]');
        var openButtons = page.querySelectorAll('[data-saverestore-update-open]');
        var closeButtons = page.querySelectorAll('[data-saverestore-update-close]');
        var panelButtons = page.querySelectorAll('[data-saverestore-panel]');
        var panelBodies = page.querySelectorAll('[data-saverestore-panel-body]');
        var autoToggle = page.querySelector('[data-saverestore-auto-toggle]');
        var autoSettings = page.querySelector('[data-saverestore-auto-settings]');
        var fileInput = page.querySelector('[data-saverestore-file-input]');
        var fileLabel = page.querySelector('[data-saverestore-file-label]');
        var log = page.querySelector('[data-saverestore-log]');
        var copyButton = page.querySelector('[data-saverestore-copy-log]');
        var jumpButton = page.querySelector('[data-saverestore-log-jump]');

        // Воркер (update_iframe.php) заканчивает загрузку документа ровно тогда,
        // когда PHP закончил запрос. Если события finish к этому моменту нет,
        // сервер оборвал обновление (таймаут php-fpm, рестарт, падение) —
        // показываем ошибку вместо вечного «В процессе».
        var workerFrame = document.getElementById('processUpdateFrame');
        if (workerFrame) {
            workerFrame.addEventListener('load', function () {
                if (STATE.finished) {
                    return;
                }
                window.setTimeout(function () {
                    if (STATE.finished) {
                        return;
                    }
                    var label = stepLabel(STATE.lastStep);
                    appendLog(
                        'Соединение с сервером закрылось на этапе «' + label + '», результат не получен. ' +
                        'Обычно это таймаут php-fpm или обрыв прокси; обновление могло ещё продолжаться на сервере. ' +
                        'Обновите страницу панели и проверьте версию в «Обновлении системы». Если снова встаёт на ' +
                        'скачивании или composer — запустите ./deploy.sh по SSH: он не зависит от веб-сервера.',
                        'error'
                    );
                    window.systemUpdateFinish(
                        'Запрос обновления оборвался на этапе «' + label + '»: сервер закрыл соединение, не прислав результат.',
                        'error'
                    );
                }, 2000);
            });
        }

        function setModalOpen(isOpen) {
            if (!modal) {
                return;
            }
            modal.classList.toggle('is-open', isOpen);
            document.body.classList.toggle('md-saverestore-modal-open', isOpen);
            if (isOpen) {
                var dialog = modal.querySelector('.md-saverestore-update-modal__dialog');
                if (dialog) {
                    window.setTimeout(function () {
                        dialog.focus();
                    }, 60);
                }
            }
        }

        function canClose() {
            return !STATE.running || STATE.finished;
        }

        function setPanel(panelId) {
            panelButtons.forEach(function (button) {
                var isActive = button.getAttribute('data-saverestore-panel') === panelId;
                button.classList.toggle('is-active', isActive);
                button.classList.toggle('active', isActive);
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            panelBodies.forEach(function (panel) {
                var isActive = panel.id === panelId;
                panel.classList.toggle('is-active', isActive);
                panel.hidden = !isActive;
            });
        }

        function syncAutoSettings() {
            if (!autoToggle || !autoSettings) {
                return;
            }
            autoSettings.classList.toggle('is-hidden', autoToggle.value !== '1');
        }

        function syncCheckCards() {
            page.querySelectorAll('[data-saverestore-check-card]').forEach(function (card) {
                var input = card.querySelector('input[type="checkbox"]');
                if (!input) {
                    return;
                }
                card.classList.toggle('is-selected', !!input.checked);
            });
        }

        openButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                setModalOpen(true);
                window.systemUpdateSetStatus('Подготовка операции…', 'active', 10, 'prepare');
            });
        });

        closeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (!canClose()) {
                    return;
                }
                setModalOpen(false);
            });
        });

        if (modal) {
            modal.addEventListener('click', function (event) {
                if (event.target === modal && canClose()) {
                    setModalOpen(false);
                }
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal && modal.classList.contains('is-open') && canClose()) {
                setModalOpen(false);
            }
        });

        panelButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                setPanel(button.getAttribute('data-saverestore-panel'));
            });
        });

        if (autoToggle) {
            autoToggle.addEventListener('change', syncAutoSettings);
            syncAutoSettings();
        }

        page.querySelectorAll('[data-saverestore-check-card] input[type="checkbox"]').forEach(function (checkbox) {
            checkbox.addEventListener('change', syncCheckCards);
        });
        syncCheckCards();

        if (fileInput && fileLabel) {
            var defaultLabel = fileLabel.textContent;
            fileInput.addEventListener('change', function () {
                if (fileInput.files && fileInput.files.length > 0) {
                    fileLabel.textContent = 'Выбрано файлов: ' + fileInput.files.length;
                } else {
                    fileLabel.textContent = defaultLabel;
                }
            });
        }

        if (log) {
            log.addEventListener('scroll', function () {
                setJumpVisible(!logIsNearBottom(log) && STATE.logEntries.length > 0);
            });
        }

        if (jumpButton) {
            jumpButton.addEventListener('click', scrollLogToBottom);
        }

        if (copyButton) {
            copyButton.addEventListener('click', function () {
                var text = plainLog();
                if (!text) {
                    return;
                }
                copyText(text).then(function () {
                    copyButton.classList.add('is-copied');
                    copyButton.innerHTML = '<i class="ti ti-check" aria-hidden="true"></i>';
                    copyButton.setAttribute('title', 'Журнал скопирован');
                    copyButton.setAttribute('aria-label', 'Журнал скопирован');
                    if (STATE.copyTimer) {
                        window.clearTimeout(STATE.copyTimer);
                    }
                    STATE.copyTimer = window.setTimeout(function () {
                        copyButton.classList.remove('is-copied');
                        copyButton.innerHTML = '<i class="ti ti-copy" aria-hidden="true"></i>';
                        copyButton.setAttribute('title', 'Скопировать журнал');
                        copyButton.setAttribute('aria-label', 'Скопировать журнал');
                    }, 1600);
                }).catch(function () {
                    copyButton.setAttribute('title', 'Не удалось скопировать');
                });
            });
        }

        updateCopyButton();

        var shouldOpen = page.getAttribute('data-saverestore-open-update') === '1' ||
            (modal && modal.classList.contains('is-open'));

        if (shouldOpen) {
            if (!STATE.finished) {
                STATE.running = true;
            }
            setModalOpen(true);
            if (!STATE.finished) {
                startTimer();
            }
        }

        setPanel('uploadBackup');
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('saverestore', {
            init: boot
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        boot(document);
    });
})();
