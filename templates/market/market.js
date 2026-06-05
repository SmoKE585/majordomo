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
        var activeItem = document.querySelector('#tab > li.active');
        if (!activeItem) {
            return;
        }
        if (activeItem.id !== 'tab_updates') {
            return;
        }
        var activeLink = activeItem.querySelector('a');
        if (!activeLink || activeItem.querySelector('.market-plugin-count-badge')) {
            return;
        }
        var scope = root || document;
        var badge = document.createElement('span');
        badge.className = 'market-plugin-count-badge';
        badge.textContent = scope.querySelectorAll('#market_application .market-plugin-card').length;
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
            progress.style.background = state === 'error'
                ? 'linear-gradient(90deg, #dc3545, #ef6a78)'
                : state === 'success'
                    ? 'linear-gradient(90deg, #198754, #54b47b)'
                    : 'linear-gradient(90deg, #4792d1, #6ab1df)';
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
        marketSetInstallStatus(text || 'Операция завершена, выполняется переход...', state || 'success', 100, 'finish');
    }

    function bindFallbackImages(root) {
        Array.prototype.forEach.call((root || document).querySelectorAll('img[data-market-fallback-src]'), function (img) {
            if (img.getAttribute('data-market-fallback-bound') === '1') {
                return;
            }
            img.setAttribute('data-market-fallback-bound', '1');
            img.addEventListener('error', function handleError() {
                var fallback = img.getAttribute('data-market-fallback-src');
                if (!fallback || img.getAttribute('data-market-fallback-applied') === '1') {
                    return;
                }
                img.setAttribute('data-market-fallback-applied', '1');
                img.src = fallback;
            });
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
            searchResult.innerHTML = '<div class="market-results__loading"><i class="glyphicon glyphicon-refresh"></i><span>' + config.loadingText + '</span></div>';
        }

        function renderError(message) {
            if (!searchResult) {
                return;
            }
            searchResult.innerHTML = '<div class="market-results__error">' + message + '</div>';
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
            var clearItem = document.createElement('li');
            clearItem.id = searchHintId;
            clearItem.innerHTML = '<a href="#" data-market-action="clear-search">' + config.searchCloseLabel + '</a>';
            var searchItem = document.createElement('li');
            searchItem.id = searchTabId;
            searchItem.className = 'active';
            searchItem.innerHTML = '<a href="#panel_installed" id="panel_search">' + config.searchLabel + '</a>';
            tab.insertBefore(searchItem, tab.firstChild);
            tab.insertBefore(clearItem, searchItem.nextSibling);
        }

        function setActiveTabLink(link) {
            if (!tab || !link) {
                return;
            }
            var items = tab.querySelectorAll('li');
            for (var i = 0; i < items.length; i++) {
                items[i].classList.remove('active');
            }
            if (link.parentNode) {
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
                var searchLink = document.getElementById('panel_search');
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
            if (action === 'toggle-upload') {
                event.preventDefault();
                if (manualUpload) {
                    manualUpload.classList.toggle('is-open');
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
            marketSetInstallStatus('Подготовка операции. Подробный журнал появится ниже.', 'active', 15, 'prepare');
        }

        bindFallbackImages(document);
    }

    window.setCustomRepositoryUrl = setCustomRepositoryUrl;
    window.marketAfterListRender = marketAfterListRender;
    window.marketSetInstallStatus = marketSetInstallStatus;
    window.marketFinishInstall = marketFinishInstall;

    document.addEventListener('DOMContentLoaded', bootMarketPage);
})(window, document);
