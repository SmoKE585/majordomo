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

    function marketSetInstallStatus(text, state, percent, step) {
        var label = document.getElementById('marketInstallStatusText');
        var icon = document.getElementById('marketInstallStatusIcon');
        var progress = document.querySelector('#marketInstallProgress .md-market-progress__bar');
        var modal = document.querySelector('[data-market-update-modal]');
        if (label) {
            label.textContent = text || '';
        }
        if (typeof percent === 'undefined' || percent === null) {
            percent = state === 'success' || state === 'error' ? 100 : 35;
        }
        if (icon) {
            icon.classList.remove('is-success', 'is-error');
            if (state === 'success') {
                icon.classList.add('is-success');
            } else if (state === 'error') {
                icon.classList.add('is-error');
            }
        }
        if (progress) {
            progress.style.width = percent + '%';
            progress.classList.remove('is-error', 'is-success');
            if (state === 'error') {
                progress.classList.add('is-error');
            } else if (state === 'success') {
                progress.classList.add('is-success');
            }
        }
        if (modal) {
            var normalizedPercent = Math.max(0, Math.min(100, percent));
            var opacity = 0.58 + (normalizedPercent / 100) * 0.34;
            if (state === 'error') {
                opacity = 0.88;
            } else if (state === 'success') {
                opacity = 0.94;
            }
            modal.style.setProperty('--md-market-backdrop-opacity', opacity.toFixed(2));
        }
        if (step) {
            var activeNode = document.getElementById('marketInstallStep_' + step);
            var activeOrder = activeNode ? parseInt(activeNode.getAttribute('data-order'), 10) : NaN;
            Array.prototype.forEach.call(document.querySelectorAll('.md-market-step'), function (item) {
                var order = parseInt(item.getAttribute('data-order'), 10);
                var isActive = item.id === 'marketInstallStep_' + step;
                item.classList.toggle('is-active', isActive);
                item.classList.toggle('is-done', !Number.isNaN(activeOrder) && !Number.isNaN(order) && order < activeOrder);
            });
        }
    }

    function marketFinishInstall(text, state) {
        window.onbeforeunload = null;
        marketSetInstallStatus(text || 'Готово. Обновляем страницу…', state || 'success', 100, 'finish');
    }

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
            if (loading) {
                loading.hidden = true;
            }
            if (!list) {
                return;
            }

            var commits = data && data.COMMITS ? data.COMMITS : [];
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

        function openMarketUpdate(button) {
            var modalElement = document.getElementById('marketUpdateModal');
            if (!modalElement || !window.bootstrap || !bootstrap.Modal) {
                return;
            }
            var card = button.closest ? button.closest('.market-item') : null;
            var moduleName = button.getAttribute('data-module-name') || '';
            var installed = button.getAttribute('data-installed') === '1';
            var currentVersion = button.getAttribute('data-current-version') || '';
            var latestVersion = button.getAttribute('data-latest-version') || '';
            var installUrl = button.getAttribute('data-install-url') || '';
            var titleNode = card ? card.querySelector('.market-item__title') : null;
            var title = titleNode ? titleNode.textContent.trim() : moduleName;

            var eyebrow = document.getElementById('marketUpdateModalEyebrow');
            var titleElement = document.getElementById('marketUpdateModalTitle');
            var versions = document.getElementById('marketUpdateModalVersions');
            var confirm = document.getElementById('marketUpdateModalConfirm');
            var repo = document.getElementById('marketUpdateModalRepo');
            var list = document.getElementById('marketUpdateModalList');
            var loading = document.getElementById('marketUpdateModalLoading');

            if (eyebrow) {
                eyebrow.textContent = installed ? 'Обновление модуля' : 'Установка модуля';
            }
            if (titleElement) {
                titleElement.textContent = title;
            }
            if (versions) {
                var parts = [];
                if (installed && currentVersion) {
                    parts.push('Установлено: ' + currentVersion);
                }
                if (latestVersion) {
                    parts.push('Версия: ' + latestVersion);
                }
                versions.textContent = parts.join(' · ');
            }
            if (confirm) {
                confirm.textContent = installed ? 'Обновить' : 'Установить';
                confirm.setAttribute('href', installUrl);
            }
            if (repo) {
                repo.innerHTML = '';
            }
            if (list) {
                list.innerHTML = '';
            }
            if (loading) {
                loading.hidden = false;
            }

            bootstrap.Modal.getOrCreateInstance(modalElement).show();

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
                    repo.innerHTML = data.REPOSITORY
                        ? '<i class="ti ti-brand-git" aria-hidden="true"></i><a href="' + escapeHtml(data.REPOSITORY) + '" target="_blank" rel="noopener">' + escapeHtml(data.REPOSITORY) + '</a>'
                        : '';
                }
                if (versions && data.CURRENT_VERSION && installed) {
                    var versionParts = [];
                    versionParts.push('Установлено: ' + data.CURRENT_VERSION);
                    if (data.LATEST_VERSION) {
                        versionParts.push('Версия: ' + data.LATEST_VERSION);
                    }
                    versions.textContent = versionParts.join(' · ');
                }
                renderUpdateCommits(data);
            }).catch(function (error) {
                if (loading) {
                    loading.hidden = true;
                }
                if (list) {
                    list.innerHTML = '<div class="md-alert md-alert--danger" role="alert"><i class="ti ti-alert-triangle md-alert__icon" aria-hidden="true"></i><div class="md-alert__body"><div class="md-alert__text">' + escapeHtml(error && error.message ? error.message : 'Не удалось загрузить обновления.') + '</div></div></div>';
                }
            });
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

        if (document.querySelector('[data-market-update-modal]')) {
            document.body.classList.add('md-market-modal-open');
            marketSetInstallStatus('Подготовка…', 'active', 15, 'prepare');
        }

        bindFallbackImages(document);
    }

    window.setCustomRepositoryUrl = setCustomRepositoryUrl;
    window.marketAfterListRender = marketAfterListRender;
    window.marketSetInstallStatus = marketSetInstallStatus;
    window.marketFinishInstall = marketFinishInstall;

    document.addEventListener('DOMContentLoaded', bootMarketPage);
})(window, document);
