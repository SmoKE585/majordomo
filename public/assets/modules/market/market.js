(function (window, document) {
    'use strict';

    var marketBooted = false;

    function getMarketConfig() {
        var node = document.querySelector('[data-market-config]');
        return {
            rootHtml: node ? (node.getAttribute('data-roothtml') || '/') : '/',
            loadingText: node ? (node.getAttribute('data-loading-text') || 'Loading...') : 'Loading...',
            searchLabel: node ? (node.getAttribute('data-search-label') || 'Search') : 'Search',
            searchCloseLabel: node ? (node.getAttribute('data-search-close-label') || 'Close search') : 'Close search',
            installPrompt: node ? (node.getAttribute('data-install-prompt') || 'Enter repository URL') : 'Enter repository URL',
            listLoadError: node ? (node.getAttribute('data-list-load-error') || 'Failed to load extensions list.') : 'Failed to load extensions list.',
            updatesTab: 'tab_updates'
        };
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function plural(number, one, few, many) {
        var n = Math.abs(parseInt(number, 10) || 0);
        var mod10 = n % 10;
        var mod100 = n % 100;
        if (mod10 === 1 && mod100 !== 11) {
            return one;
        }
        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
            return few;
        }
        return many;
    }

    function setCustomRepositoryUrl(moduleName, currentUrlBase64) {
        var config = getMarketConfig();
        var currentUrl = currentUrlBase64 ? window.atob(currentUrlBase64) : '';
        var url = window.prompt(config.installPrompt, currentUrl || '');
        if (url === null) {
            return false;
        }
        window.location.href = config.rootHtml + 'panel/market.html?mode=save_custom_repository&name=' + encodeURIComponent(moduleName) + '&custom_url=' + encodeURIComponent(url);
        return false;
    }

    function marketAfterListRender(root) {
        bindFallbackImages(root || document);
        var scope = root || document;
        var items = scope.querySelectorAll('#market_application .market-item');
        var catalogCount = document.getElementById('marketCatalogCount');
        if (catalogCount) {
            var n = items.length;
            var mod10 = n % 10;
            var mod100 = n % 100;
            var word = 'модулей';
            if (mod10 === 1 && mod100 !== 11) {
                word = 'модуль';
            } else if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
                word = 'модуля';
            }
            catalogCount.textContent = n + ' ' + word;
        }

        var activeLink = document.querySelector('#tab .nav-link.active, #tab .nav-link.is-active, #tab > li.active > a');
        if (!activeLink) {
            return;
        }
        if (activeLink.id !== 'panel_updates') {
            return;
        }
        var existingBadge = activeLink.querySelector('.market-plugin-count-badge');
        if (existingBadge) {
            existingBadge.textContent = items.length;
            return;
        }
        var badge = document.createElement('span');
        badge.className = 'md-badge market-plugin-count-badge';
        badge.textContent = items.length;
        activeLink.appendChild(badge);
    }

    var marketInstallState = {
        running: false,
        finished: false,
        finishState: '',
        startedAt: 0,
        elapsedTimer: null,
        logEntries: [],
        copyTimer: null,
        lastStep: 'prepare',
        lastPercent: 0
    };

    function mq(selector, root) {
        return (root || document).querySelector(selector);
    }

    function mqa(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function marketModal() {
        return mq('[data-market-update-modal]');
    }

    function marketPad(value) {
        return value < 10 ? '0' + value : String(value);
    }

    function marketClock(date) {
        return marketPad(date.getHours()) + ':' + marketPad(date.getMinutes()) + ':' + marketPad(date.getSeconds());
    }

    function marketDuration(milliseconds) {
        var total = Math.max(0, Math.floor(milliseconds / 1000));
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var seconds = total % 60;
        return (hours > 0 ? marketPad(hours) + ':' : '') + marketPad(minutes) + ':' + marketPad(seconds);
    }

    function marketUpdateElapsed() {
        var target = document.getElementById('marketInstallElapsed');
        if (!target) {
            return;
        }
        if (!marketInstallState.startedAt) {
            marketInstallState.startedAt = Date.now();
        }
        target.textContent = marketDuration(Date.now() - marketInstallState.startedAt);
    }

    function marketStartTimer() {
        if (marketInstallState.elapsedTimer) {
            return;
        }
        if (!marketInstallState.startedAt) {
            marketInstallState.startedAt = Date.now();
        }
        marketUpdateElapsed();
        marketInstallState.elapsedTimer = window.setInterval(marketUpdateElapsed, 1000);
    }

    function marketStopTimer() {
        if (marketInstallState.elapsedTimer) {
            window.clearInterval(marketInstallState.elapsedTimer);
            marketInstallState.elapsedTimer = null;
        }
        marketUpdateElapsed();
    }

    function marketSetStatusIcon(state) {
        var icon = document.getElementById('marketInstallStatusIcon');
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

    function marketSetLiveBadge(state) {
        var badge = mq('[data-market-live-badge]');
        if (!badge) {
            return;
        }
        var normalized = state === 'success' || state === 'error' ? state : 'active';
        badge.classList.remove('md-badge--info', 'md-badge--success', 'md-badge--danger');
        badge.classList.add(
            normalized === 'success' ? 'md-badge--success' :
                normalized === 'error' ? 'md-badge--danger' : 'md-badge--info'
        );
        var label = mq('[data-market-live-label]', badge);
        if (label) {
            label.textContent = normalized === 'success' ? 'Готово' : normalized === 'error' ? 'Ошибка' : 'В процессе';
        }
    }

    function marketUpdateProgress(percent, state) {
        var value = Math.max(0, Math.min(100, Number(percent) || 0));
        var progress = document.getElementById('marketInstallProgress');
        var bar = progress ? mq('.md-progress__bar', progress) : null;
        var label = document.getElementById('marketInstallPercent');
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

    function marketUpdateStep(step, state) {
        var modal = marketModal();
        if (!modal || !step) {
            return;
        }
        var steps = mqa('[data-market-step]', modal);
        if (!steps.length) {
            return;
        }
        var active = null;
        steps.forEach(function (item) {
            if (item.getAttribute('data-market-step') === step) {
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
            var counter = document.getElementById('marketInstallStepCounter');
            if (counter) {
                counter.textContent = String(activeOrder);
            }
            var stepper = mq('[data-market-stepper]', modal);
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

    function marketSetInstallStatus(text, state, percent, step) {
        var s = marketInstallState;
        if (s.finished && state === 'active') {
            return;
        }
        var normalized = state === 'success' || state === 'error' ? state : 'active';
        var label = document.getElementById('marketInstallStatusText');
        if (label) {
            label.textContent = text || '';
        }
        var statusLabel = mq('[data-market-status-label]');
        if (statusLabel) {
            statusLabel.textContent = normalized === 'error' ? 'Операция остановлена' :
                normalized === 'success' ? 'Завершено' : 'Текущий этап';
        }
        var hint = mq('[data-market-meta-hint]');
        if (hint) {
            hint.textContent = normalized === 'error' ? 'Проверьте журнал' :
                normalized === 'success' ? 'Страница перезагрузится' : 'Не закрывайте окно';
        }
        var value = typeof percent === 'undefined' || percent === null ?
            (normalized === 'success' || normalized === 'error' ? 100 : 35) : percent;
        marketUpdateProgress(value, normalized);
        marketSetStatusIcon(normalized);
        marketSetLiveBadge(normalized);
        marketUpdateStep(step, normalized);
        if (normalized === 'active') {
            s.running = true;
            s.lastStep = step || s.lastStep;
            s.lastPercent = value;
            marketStartTimer();
        } else {
            s.running = false;
            marketStopTimer();
        }
    }

    function marketCleanLocation() {
        var modal = marketModal();
        if (!modal) {
            return;
        }
        var clean = modal.getAttribute('data-market-return-url');
        if (!clean || !window.history || typeof window.history.replaceState !== 'function') {
            return;
        }
        try {
            window.history.replaceState(null, '', clean);
        } catch (error) {
            // Ignore browsers that refuse history updates in this context.
        }
    }

    function marketShowFinishUI(state) {
        var modal = marketModal();
        if (!modal) {
            return;
        }
        var body = mq('[data-market-body]', modal);
        if (body) {
            body.setAttribute('aria-busy', 'false');
        }
        var close = mq('.md-market-update-modal__close', modal);
        var actions = mq('[data-market-finish-actions]', modal);
        var finishText = mq('[data-market-finish-text]', modal);
        var note = mq('[data-market-note]', modal);
        if (note) {
            note.hidden = true;
        }
        var detailsNote = mq('[data-market-details-note]', modal);
        if (detailsNote) {
            detailsNote.hidden = true;
        }
        if (state === 'error') {
            if (close) {
                close.hidden = false;
            }
            if (actions) {
                actions.hidden = false;
            }
            if (finishText) {
                finishText.textContent = 'Проверьте журнал, затем вернитесь в маркет.';
            }
            marketCleanLocation();
            window.setTimeout(function () {
                var back = mq('[data-market-return]', modal);
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
            marketCleanLocation();
        }
    }

    function marketFinishInstall(text, state) {
        var normalized = state === 'error' ? 'error' : 'success';
        var s = marketInstallState;
        s.finished = true;
        s.finishState = normalized;
        s.running = false;
        window.onbeforeunload = null;
        var finishStep = normalized === 'error' ? (s.lastStep || 'prepare') : 'finish';
        var finishPercent = normalized === 'error' ? (s.lastPercent || 0) : 100;
        marketSetInstallStatus(
            text || (normalized === 'error' ? 'Операция остановлена.' : 'Операция завершена.'),
            normalized,
            finishPercent,
            finishStep
        );
        marketShowFinishUI(normalized);
    }

    function marketScrollLogBottom() {
        var log = mq('[data-market-log]');
        if (!log) {
            return;
        }
        log.scrollTop = log.scrollHeight;
        marketSetJumpVisible(false);
    }

    function marketSetJumpVisible(visible) {
        var jump = mq('[data-market-log-jump]');
        if (jump) {
            jump.hidden = !visible;
        }
    }

    function marketLogNearBottom(log) {
        return log.scrollHeight - log.scrollTop - log.clientHeight < 56;
    }

    function marketUpdateCopyButton() {
        var button = mq('[data-market-copy-log]');
        if (button) {
            button.disabled = marketInstallState.logEntries.length === 0;
        }
    }

    function marketAppendLog(text, kind) {
        var log = mq('[data-market-log]');
        var value = String(text || '').trim();
        if (!log || !value) {
            return;
        }
        var stick = marketLogNearBottom(log);
        var empty = mq('[data-market-log-empty]', log);
        if (empty) {
            empty.remove();
        }
        var normalizedKind = ['success', 'warning', 'error', 'muted'].indexOf(kind) !== -1 ? kind : 'info';
        var now = Date.now();
        var previous = marketInstallState.logEntries[marketInstallState.logEntries.length - 1];
        if (previous && previous.type === 'log' && previous.text === value &&
            previous.kind === normalizedKind && previous.receivedAt && now - previous.receivedAt < 600) {
            return;
        }
        var time = marketClock(new Date(now));
        var entry = document.createElement('div');
        entry.className = 'md-market-update-modal__log-entry is-' + normalizedKind;

        var timeNode = document.createElement('span');
        timeNode.className = 'md-market-update-modal__log-time';
        timeNode.textContent = time;

        var iconNode = document.createElement('span');
        iconNode.className = 'md-market-update-modal__log-icon';
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
        textNode.className = 'md-market-update-modal__log-text';
        textNode.textContent = value;

        entry.appendChild(timeNode);
        entry.appendChild(iconNode);
        entry.appendChild(textNode);
        log.appendChild(entry);

        marketInstallState.logEntries.push({ type: 'log', time: time, text: value, kind: normalizedKind, receivedAt: now });
        if (marketInstallState.logEntries.length > 500) {
            marketInstallState.logEntries.shift();
            if (log.firstElementChild) {
                log.removeChild(log.firstElementChild);
            }
        }
        if (stick) {
            marketScrollLogBottom();
        } else {
            marketSetJumpVisible(true);
        }
        marketUpdateCopyButton();
    }

    function marketAppendSection(title, description) {
        var log = mq('[data-market-log]');
        var value = String(title || '').trim();
        if (!log || !value) {
            return;
        }
        var stick = marketLogNearBottom(log);
        var empty = mq('[data-market-log-empty]', log);
        if (empty) {
            empty.remove();
        }
        var section = document.createElement('div');
        section.className = 'md-market-update-modal__log-section';
        var heading = document.createElement('div');
        heading.className = 'md-market-update-modal__log-section-title';
        heading.textContent = value;
        section.appendChild(heading);
        var descriptionText = String(description || '').trim();
        if (descriptionText) {
            var text = document.createElement('div');
            text.className = 'md-market-update-modal__log-section-text';
            text.textContent = descriptionText;
            section.appendChild(text);
        }
        log.appendChild(section);
        marketInstallState.logEntries.push({ type: 'section', title: value, description: descriptionText });
        if (stick) {
            marketScrollLogBottom();
        } else {
            marketSetJumpVisible(true);
        }
        marketUpdateCopyButton();
    }

    function marketHandleInstallEvent(event) {
        if (!event || typeof event !== 'object') {
            return;
        }
        if (event.type === 'events' && Array.isArray(event.items)) {
            event.items.forEach(marketHandleInstallEvent);
            return;
        }
        switch (event.type) {
            case 'log':
                marketAppendLog(event.text, event.kind);
                break;
            case 'section':
                marketAppendSection(event.title, event.description);
                break;
            case 'finish':
                marketFinishInstall(event.text, event.state);
                break;
            default:
                break;
        }
    }

    function marketPlainLog() {
        return marketInstallState.logEntries.map(function (entry) {
            if (entry.type === 'section') {
                return '\n[' + entry.title + ']' + (entry.description ? ' ' + entry.description : '') + '\n';
            }
            return '[' + entry.time + '] ' + entry.text;
        }).join('\n').trim();
    }

    function marketLegacyCopy(text) {
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

    function marketCopyText(text) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            return navigator.clipboard.writeText(text).catch(function () {
                return marketLegacyCopy(text);
            });
        }
        return marketLegacyCopy(text);
    }

    window.marketInstallEvent = marketHandleInstallEvent;
    window.addEventListener('message', function (event) {
        if (event.origin !== window.location.origin) {
            return;
        }
        var data = event.data;
        if (!data || data.source !== 'mdj-market') {
            return;
        }
        marketHandleInstallEvent(data);
    });

    function bindFallbackImages(root) {
        function applyFallback(img) {
            var fallback = img.getAttribute('data-market-fallback-src');
            if (!fallback || img.getAttribute('data-market-fallback-applied') === '1') {
                return;
            }
            img.setAttribute('data-market-fallback-applied', '1');
            img.src = fallback;
        }

        Array.prototype.forEach.call((root || document).querySelectorAll('img[data-market-fallback-src]'), function (img) {
            if (img.getAttribute('data-market-fallback-bound') === '1') {
                return;
            }
            img.setAttribute('data-market-fallback-bound', '1');
            img.addEventListener('error', function () {
                applyFallback(img);
            });
            if (img.complete && img.naturalWidth === 0) {
                applyFallback(img);
            }
        });
    }

    function bootMarketPage() {
        if (marketBooted) {
            marketAfterListRender(document);
            return;
        }
        marketBooted = true;

        var config = getMarketConfig();
        var filterInput = document.getElementById('filter');
        var searchResult = document.getElementById('search_result');
        var tab = document.getElementById('tab');
        var searchBox = document.getElementById('marketSearchBox');
        var manualUpload = document.getElementById('marketManualUpload');
        var searchTimer = 0;
        var searchTabId = 'li_search';
        var searchHintId = 'li_search_hint';

        function setLoadingState(isLoading) {
            if (!searchBox) {
                return;
            }
            searchBox.classList.toggle('is-loading', !!isLoading);
        }

        function renderLoading() {
            if (!searchResult) {
                return;
            }
            searchResult.innerHTML = '<div class="market-results__loading"><span class="md-spinner" aria-hidden="true"></span><span>' + config.loadingText + '</span></div>';
        }

        function renderError(message) {
            if (!searchResult) {
                return;
            }
            searchResult.innerHTML = '<div class="md-alert md-alert--danger market-results__error" role="alert"><i class="ti ti-alert-triangle md-alert__icon" aria-hidden="true"></i><div class="md-alert__body"><div class="md-alert__text">' + message + '</div></div></div>';
        }

        function renderUpdateCommits(data) {
            var list = document.getElementById('marketUpdateModalList');
            var loading = document.getElementById('marketUpdateModalLoading');
            var count = document.getElementById('marketUpdateModalCount');
            if (loading) {
                loading.hidden = true;
            }
            if (!list) {
                return;
            }

            var commits = data && data.COMMITS ? data.COMMITS : [];
            if (count) {
                count.hidden = !commits.length;
                count.textContent = commits.length ? commits.length + ' ' + plural(commits.length, 'коммит', 'коммита', 'коммитов') : '';
            }
            if (!commits.length) {
                var fallback = data && data.LATEST_COMMENT ? data.LATEST_COMMENT : '';
                list.innerHTML = fallback
                    ? '<div class="market-update-entry"><div class="market-update-entry__head"><time>Последнее изменение</time></div><div class="market-update-entry__text">' + escapeHtml(fallback) + '</div></div>'
                    : '<div class="md-empty md-empty--compact"><div class="md-empty__icon"><i class="ti ti-git-commit" aria-hidden="true"></i></div><div class="md-empty__title">Нет данных о коммитах</div><div class="md-empty__text">Репозиторий не вернул список обновлений.</div></div>';
                return;
            }

            var rows = [];
            for (var i = 0; i < commits.length; i++) {
                var commit = commits[i] || {};
                rows.push(
                    '<div class="market-update-entry">'
                    + '<div class="market-update-entry__head"><time>' + escapeHtml(commit.DATE || '') + '</time>'
                    + (commit.URL ? '<a href="' + escapeHtml(commit.URL) + '" target="_blank" rel="noopener">Открыть <i class="ti ti-external-link" aria-hidden="true"></i></a>' : '')
                    + '</div>'
                    + '<div class="market-update-entry__text">' + escapeHtml(commit.TEXT || '') + '</div>'
                    + '</div>'
                );
            }
            list.innerHTML = rows.join('');
        }

        function openMarketAction(button, isUninstall) {
            var modalElement = document.getElementById('marketUpdateModal');
            if (!modalElement || !window.bootstrap || !bootstrap.Modal) {
                return;
            }
            var card = button.closest ? button.closest('.market-item') : null;
            var moduleName = button.getAttribute('data-module-name') || '';
            var installed = button.getAttribute('data-installed') === '1';
            var currentVersion = button.getAttribute('data-current-version') || button.getAttribute('data-installed-version') || '';
            var latestVersion = button.getAttribute('data-latest-version') || '';
            var installUrl = button.getAttribute('data-install-url') || button.getAttribute('data-uninstall-url') || '';
            var titleNode = card ? card.querySelector('.market-item__title') : null;
            var title = button.getAttribute('data-title') || (titleNode ? titleNode.textContent.trim() : moduleName);

            var eyebrow = document.getElementById('marketUpdateModalEyebrow');
            var titleElement = document.getElementById('marketUpdateModalTitle');
            var versions = document.getElementById('marketUpdateModalVersions');
            var confirm = document.getElementById('marketUpdateModalConfirm');
            var repo = document.getElementById('marketUpdateModalRepo');
            var list = document.getElementById('marketUpdateModalList');
            var loading = document.getElementById('marketUpdateModalLoading');
            var note = document.getElementById('marketUpdateModalNote');
            var count = document.getElementById('marketUpdateModalCount');
            var sectionTitle = document.getElementById('marketUpdateModalSectionTitle');
            var hint = document.getElementById('marketUpdateModalHint');
            var icon = document.getElementById('marketUpdateModalIcon');

            if (icon) {
                icon.innerHTML = '<i class="ti ' + (isUninstall ? 'ti-trash' : installed ? 'ti-refresh' : 'ti-cloud-download') + '" aria-hidden="true"></i>';
                icon.classList.toggle('is-danger', isUninstall);
            }
            if (eyebrow) {
                eyebrow.textContent = isUninstall ? 'Удаление модуля' : installed ? 'Обновление модуля' : 'Установка модуля';
            }
            if (titleElement) {
                titleElement.textContent = title;
            }
            if (versions) {
                var parts = [];
                if (currentVersion) {
                    parts.push('Установлено: ' + currentVersion);
                }
                if (!isUninstall && latestVersion) {
                    parts.push('Обновление: ' + latestVersion);
                }
                versions.textContent = parts.join(' · ');
            }
            if (confirm) {
                confirm.textContent = isUninstall ? 'Удалить' : installed ? 'Обновить' : 'Установить';
                confirm.setAttribute('href', installUrl);
                confirm.className = 'md-btn ' + (isUninstall ? 'md-btn--danger' : 'md-btn--primary');
            }
            if (repo) {
                repo.innerHTML = '';
                repo.hidden = true;
            }
            if (list) {
                list.innerHTML = '';
            }
            if (note) {
                note.hidden = isUninstall;
                note.classList.remove('is-danger');
                if (!isUninstall) {
                    note.innerHTML = '<i class="ti ti-info-circle" aria-hidden="true"></i><span>Проверьте список изменений перед установкой.</span>';
                }
            }
            if (count) {
                count.hidden = true;
                count.textContent = '';
            }
            if (sectionTitle) {
                sectionTitle.textContent = isUninstall ? 'Последствия' : 'Что изменится';
            }
            if (hint) {
                hint.textContent = isUninstall ? 'После удаления страница обновится автоматически.' : 'После установки страница обновится автоматически.';
            }
            if (loading) {
                loading.hidden = isUninstall;
            }

            if (isUninstall) {
                if (list) {
                    list.innerHTML = '<div class="md-alert md-alert--danger" role="alert">'
                        + '<i class="ti ti-alert-triangle md-alert__icon" aria-hidden="true"></i>'
                        + '<div class="md-alert__body"><div class="md-alert__title">Модуль будет удалён</div>'
                        + '<div class="md-alert__text">Будут удалены файлы модуля' + (currentVersion ? ' версии ' + escapeHtml(currentVersion) : '') + ' и связанные записи в базе данных.</div></div>'
                        + '</div>';
                }
            }

            bootstrap.Modal.getOrCreateInstance(modalElement).show();

            if (isUninstall) {
                return;
            }
            if (!moduleName) {
                renderUpdateCommits({COMMITS: []});
                return;
            }

            fetch(config.rootHtml + 'ajax/market.html?op=commits&name=' + encodeURIComponent(moduleName), {
                credentials: 'same-origin'
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                if (data.STATUS !== 'OK') {
                    throw new Error(data.MESSAGE || 'Не удалось загрузить обновления.');
                }
                if (repo) {
                    repo.hidden = !data.REPOSITORY;
                    repo.innerHTML = data.REPOSITORY
                        ? '<i class="ti ti-brand-git" aria-hidden="true"></i><a href="' + escapeHtml(data.REPOSITORY) + '" target="_blank" rel="noopener">' + escapeHtml(data.REPOSITORY) + '</a>'
                        : '';
                }
                if (versions && data.CURRENT_VERSION && installed) {
                    var versionParts = [];
                    versionParts.push('Установлено: ' + data.CURRENT_VERSION);
                    if (data.LATEST_VERSION) {
                        versionParts.push('Обновление: ' + data.LATEST_VERSION);
                    }
                    versions.textContent = versionParts.join(' · ');
                }
                renderUpdateCommits(data);
            }).catch(function (error) {
                if (loading) {
                    loading.hidden = true;
                }
                if (count) {
                    count.hidden = true;
                }
                if (list) {
                    list.innerHTML = '<div class="md-alert md-alert--danger" role="alert"><i class="ti ti-alert-triangle md-alert__icon" aria-hidden="true"></i><div class="md-alert__body"><div class="md-alert__text">' + escapeHtml(error && error.message ? error.message : 'Не удалось загрузить обновления.') + '</div></div></div>';
                }
            });
        }

        function openMarketUpdate(button) {
            openMarketAction(button, false);
        }

        function openMarketUninstall(button) {
            openMarketAction(button, true);
        }

        function getSearchTab() {
            return document.getElementById(searchTabId);
        }

        function getSearchHintTab() {
            return document.getElementById(searchHintId);
        }

        function removeSearchTabs() {
            var searchTab = getSearchTab();
            var searchHintTab = getSearchHintTab();
            if (searchTab && searchTab.parentNode) {
                searchTab.parentNode.removeChild(searchTab);
            }
            if (searchHintTab && searchHintTab.parentNode) {
                searchHintTab.parentNode.removeChild(searchHintTab);
            }
        }

        function createSearchTabs() {
            if (!tab || getSearchTab()) {
                return;
            }
            var clearItem = document.createElement('a');
            clearItem.id = searchHintId;
            clearItem.href = '#';
            clearItem.className = 'nav-link market-tabs__clear';
            clearItem.setAttribute('data-market-action', 'clear-search');
            clearItem.setAttribute('aria-label', config.searchCloseLabel);
            clearItem.innerHTML = '<i class="ti ti-x" aria-hidden="true"></i>';
            var searchItem = document.createElement('a');
            searchItem.id = searchTabId;
            searchItem.href = '#panel_installed';
            searchItem.className = 'nav-link is-active';
            searchItem.textContent = config.searchLabel;
            tab.insertBefore(searchItem, tab.firstChild);
            tab.insertBefore(clearItem, searchItem.nextSibling);
        }

        function setActiveTabLink(link) {
            if (!tab || !link) {
                return;
            }
            Array.prototype.forEach.call(tab.querySelectorAll('a'), function (item) {
                item.classList.remove('active');
                item.classList.remove('is-active');
                if (item.parentNode && item.parentNode.tagName === 'LI') {
                    item.parentNode.classList.remove('active');
                }
            });
            link.classList.add('active');
            link.classList.add('is-active');
            if (link.parentNode && link.parentNode.tagName === 'LI') {
                link.parentNode.classList.add('active');
            }
        }

        function requestPluginList(categoryId, searchText) {
            if (!searchResult) {
                return;
            }
            renderLoading();
            setLoadingState(!!searchText);
            var problem = document.getElementById('problemLinkToConnect');
            if (problem) {
                problem.style.display = 'none';
            }
            fetch(config.rootHtml + 'ajax/market.html?op=check_updates', {
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.text();
                })
                .then(function (data) {
                    try {
                        JSON.parse(data);
                    } catch (e) {
                        if (problem) {
                            problem.style.display = 'block';
                        }
                    }
                    return fetch(config.rootHtml + 'ajax/market.html?op=list&category_id=' + encodeURIComponent(categoryId) + '&search=' + encodeURIComponent(searchText || ''), {
                        credentials: 'same-origin'
                    });
                })
                .then(function (response) {
                    return response.text();
                })
                .then(function (html) {
                    searchResult.innerHTML = html;
                    marketAfterListRender(searchResult);
                })
                .catch(function () {
                    if (problem) {
                        problem.style.display = 'block';
                    }
                    renderError(config.listLoadError);
                })
                .finally(function () {
                    setLoadingState(false);
                });
        }

        window.refreshPluginsList = function (categoryId, searchText) {
            requestPluginList(categoryId, searchText || '');
        };

        window.filterPlugins = function () {
            if (!filterInput) {
                return;
            }
            var query = filterInput.value.toLowerCase().trim();
            if (query) {
                createSearchTabs();
                var searchLink = document.getElementById(searchTabId);
                if (searchLink) {
                    setActiveTabLink(searchLink);
                }
                requestPluginList(0, query);
                return;
            }
            removeSearchTabs();
            var installedLink = document.getElementById('panel_installed');
            if (installedLink) {
                setActiveTabLink(installedLink);
                requestPluginList('installed', '');
            }
        };

        if (filterInput) {
            filterInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                setLoadingState(true);
                searchTimer = window.setTimeout(window.filterPlugins, 450);
            });
        }

        if (tab) {
            tab.addEventListener('click', function (event) {
                var target = event.target;
                while (target && target.tagName !== 'A') {
                    target = target.parentNode;
                }
                if (!target) {
                    return;
                }
                if (target.getAttribute('data-market-action') === 'clear-search') {
                    event.preventDefault();
                    if (filterInput) {
                        filterInput.value = '';
                    }
                    window.filterPlugins();
                    return;
                }
                var categoryId = target.getAttribute('data-category-id');
                if (categoryId) {
                    event.preventDefault();
                    setActiveTabLink(target);
                    requestPluginList(categoryId, '');
                }
            });
        }

        document.addEventListener('click', function (event) {
            var actionTarget = event.target;
            while (actionTarget && actionTarget !== document) {
                if (actionTarget.nodeType === 1 && actionTarget.getAttribute('data-market-action')) {
                    break;
                }
                actionTarget = actionTarget.parentNode;
            }
            if (!actionTarget || actionTarget === document || actionTarget.nodeType !== 1) {
                return;
            }
            var action = actionTarget.getAttribute('data-market-action');
            if (action === 'open-update') {
                event.preventDefault();
                openMarketUpdate(actionTarget);
            }
            if (action === 'open-uninstall') {
                event.preventDefault();
                openMarketUninstall(actionTarget);
            }
            if (action === 'toggle-upload') {
                event.preventDefault();
                if (manualUpload) {
                    var isOpen = manualUpload.classList.toggle('is-open');
                    Array.prototype.forEach.call(document.querySelectorAll('[data-market-action="toggle-upload"]'), function (button) {
                        button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    });
                }
            }
            if (action === 'scroll-to-catalog') {
                event.preventDefault();
                var section = document.getElementById('marketCatalogSection');
                if (section && section.scrollIntoView) {
                    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
            if (action === 'custom-repo') {
                event.preventDefault();
                setCustomRepositoryUrl(
                    actionTarget.getAttribute('data-module-name') || '',
                    actionTarget.getAttribute('data-current-url') || ''
                );
            }
        });

        document.addEventListener('click', function (event) {
            var confirmTarget = event.target;
            while (confirmTarget && confirmTarget !== document) {
                if (confirmTarget.nodeType === 1 && confirmTarget.hasAttribute('data-confirm')) {
                    break;
                }
                confirmTarget = confirmTarget.parentNode;
            }
            if (!confirmTarget || confirmTarget === document || confirmTarget.nodeType !== 1) {
                return;
            }
            if (!window.confirm(confirmTarget.getAttribute('data-confirm') || 'Are you sure?')) {
                event.preventDefault();
            }
        });

        var initialTab = window.location.hash.replace('#', '') === 'panel_updates' ? 'panel_updates' : 'panel_installed';
        var link = document.getElementById(initialTab);
        if (link) {
            setActiveTabLink(link);
            requestPluginList(link.getAttribute('data-category-id'), '');
        }

        var progressModal = document.querySelector('[data-market-update-modal]');
        if (progressModal) {
            document.body.classList.add('md-market-modal-open');
            if (!marketInstallState.finished) {
                marketStartTimer();
            }

            var dialog = progressModal.querySelector('.md-market-update-modal__dialog');
            if (dialog) {
                window.setTimeout(function () {
                    dialog.focus();
                }, 60);
            }

            function canCloseProgress() {
                return !marketInstallState.running || marketInstallState.finished;
            }

            function closeProgressModal() {
                if (!canCloseProgress()) {
                    return;
                }
                progressModal.classList.remove('is-open');
                document.body.classList.remove('md-market-modal-open');
            }

            Array.prototype.forEach.call(progressModal.querySelectorAll('[data-market-close]'), function (button) {
                button.addEventListener('click', closeProgressModal);
            });

            progressModal.addEventListener('click', function (event) {
                if (event.target === progressModal) {
                    closeProgressModal();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && progressModal.classList.contains('is-open')) {
                    closeProgressModal();
                }
            });

            var progressLog = progressModal.querySelector('[data-market-log]');
            if (progressLog) {
                progressLog.addEventListener('scroll', function () {
                    marketSetJumpVisible(!marketLogNearBottom(progressLog) && marketInstallState.logEntries.length > 0);
                });
            }

            var jumpButton = progressModal.querySelector('[data-market-log-jump]');
            if (jumpButton) {
                jumpButton.addEventListener('click', marketScrollLogBottom);
            }

            var copyButton = progressModal.querySelector('[data-market-copy-log]');
            if (copyButton) {
                copyButton.addEventListener('click', function () {
                    var text = marketPlainLog();
                    if (!text) {
                        return;
                    }
                    marketCopyText(text).then(function () {
                        copyButton.classList.add('is-copied');
                        copyButton.innerHTML = '<i class="ti ti-check" aria-hidden="true"></i>';
                        copyButton.setAttribute('title', 'Журнал скопирован');
                        copyButton.setAttribute('aria-label', 'Журнал скопирован');
                        if (marketInstallState.copyTimer) {
                            window.clearTimeout(marketInstallState.copyTimer);
                        }
                        marketInstallState.copyTimer = window.setTimeout(function () {
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
            marketUpdateCopyButton();
        }

        bindFallbackImages(document);
    }

    window.setCustomRepositoryUrl = setCustomRepositoryUrl;
    window.marketAfterListRender = marketAfterListRender;
    window.marketSetInstallStatus = marketSetInstallStatus;
    window.marketFinishInstall = marketFinishInstall;

    document.addEventListener('DOMContentLoaded', bootMarketPage);
})(window, document);
