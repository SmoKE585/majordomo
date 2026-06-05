(function (window, document) {
    'use strict';

    var legacyAttributeMap = {
        'toggle': 'bs-toggle',
        'target': 'bs-target',
        'dismiss': 'bs-dismiss',
        'parent': 'bs-parent',
        'backdrop': 'bs-backdrop',
        'keyboard': 'bs-keyboard',
        'placement': 'bs-placement',
        'trigger': 'bs-trigger',
        'container': 'bs-container',
        'html': 'bs-html',
        'content': 'bs-content',
        'title': 'bs-title'
    };

    function copyLegacyBootstrapAttributes(root) {
        Object.keys(legacyAttributeMap).forEach(function (legacyName) {
            var bsName = legacyAttributeMap[legacyName];
            var legacyAttr = 'data-' + legacyName;
            var bsAttr = 'data-' + bsName;
            root.querySelectorAll('[' + legacyAttr + ']').forEach(function (element) {
                if (!element.hasAttribute(bsAttr)) {
                    element.setAttribute(bsAttr, element.getAttribute(legacyAttr));
                }
            });
        });
    }

    function normalizeLegacyClasses(root) {
        root.querySelectorAll('.close:not(.btn-close)').forEach(function (element) {
            element.classList.add('btn-close');
            if (element.textContent.trim() === '×') {
                element.textContent = '';
            }
        });
    }

    function initBootstrapWidgets(root) {
        if (!window.bootstrap) {
            return;
        }

        root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
            window.bootstrap.Tooltip.getOrCreateInstance(element);
        });
        root.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (element) {
            window.bootstrap.Popover.getOrCreateInstance(element);
        });
    }

    function applyThemeFromCookie() {
        var theme = getThemeValue();
        document.documentElement.setAttribute('data-bs-theme', theme);
        syncThemeControls(document, theme);
    }

    function getThemeValue() {
        var match = document.cookie.match(/(?:^|;\s*)theme=([^;]+)/);
        var theme = match ? decodeURIComponent(match[1]) : (window.MDJAdminDefaultTheme || 'light');
        return theme === 'dark' ? 'dark' : 'light';
    }

    function syncThemeControls(root, theme) {
        (root || document).querySelectorAll('[data-md-theme-choice]').forEach(function (input) {
            var value = input.getAttribute('data-md-theme-choice') || input.value || 'light';
            input.checked = value === theme;
            input.setAttribute('aria-checked', input.checked ? 'true' : 'false');
        });
    }

    function initThemeSwitcher(root) {
        var currentTheme = getThemeValue();

        root.querySelectorAll('[data-md-theme-choice]').forEach(function (input) {
            var value = input.getAttribute('data-md-theme-choice') || input.value || 'light';
            input.value = value;
            input.checked = value === currentTheme;
            input.setAttribute('aria-checked', input.checked ? 'true' : 'false');

            if (input.dataset.mdThemeBound === '1') {
                return;
            }

            input.dataset.mdThemeBound = '1';
            input.addEventListener('change', function () {
                if (!input.checked) {
                    return;
                }

                var theme = input.getAttribute('data-md-theme-choice') || input.value || 'light';
                setCookie('theme', theme, 180);
                document.documentElement.setAttribute('data-bs-theme', theme);
                syncThemeControls(root, theme);
            });
        });
    }

    function initCheckboxToggles(root) {
        root.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
            if (input.dataset.mdToggleCheckboxBound === '1') {
                return;
            }

            input.dataset.mdToggleCheckboxBound = '1';
            input.classList.add('md-admin-toggle-checkbox');
            input.setAttribute('role', 'switch');
            input.setAttribute('aria-checked', input.checked ? 'true' : 'false');

            input.addEventListener('change', function () {
                input.setAttribute('aria-checked', input.checked ? 'true' : 'false');
            });
        });
    }

    function getCookie(name) {
        var escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + escapedName + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function setCookie(name, value, days) {
        var date = new Date();
        date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
        document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + date.toUTCString() + '; path=/';
    }

    function deleteCookie(name) {
        document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    }

    function setCollapseState(element, command) {
        if (!element) {
            return;
        }
        if (window.bootstrap && window.bootstrap.Collapse) {
            window.bootstrap.Collapse.getOrCreateInstance(element, {toggle: false})[command]();
            return;
        }
        element.classList.toggle('show', command === 'show' || (command === 'toggle' && !element.classList.contains('show')));
    }

    function initAdminDrawerHost(root) {
        var drawer = document.getElementById('mdAdminDrawerHost');
        var backdrop = document.querySelector('.md-admin-drawer-backdrop');
        var body = document.body;
        var eyebrow = document.getElementById('mdAdminDrawerEyebrow');
        var title = document.getElementById('mdAdminDrawerTitle');
        var subtitle = document.getElementById('mdAdminDrawerSubtitle');
        var bodyNode = document.getElementById('mdAdminDrawerBody');
        var footerNode = document.getElementById('mdAdminDrawerFooter');

        if (!drawer || !backdrop || !bodyNode || !footerNode || window.MDJAdminDrawerHost) {
            return;
        }

        var current = null;

        function restoreNode(entry) {
            if (!entry || !entry.node || !entry.parent) {
                return;
            }
            if (entry.nextSibling && entry.nextSibling.parentNode === entry.parent) {
                entry.parent.insertBefore(entry.node, entry.nextSibling);
            } else {
                entry.parent.appendChild(entry.node);
            }
            if (entry.wasHidden) {
                entry.node.hidden = true;
            }
        }

        function resetContainers() {
            bodyNode.innerHTML = '';
            footerNode.innerHTML = '';
            footerNode.hidden = true;
        }

        function closeDrawer(owner) {
            if (!current || (owner && current.owner && owner !== current.owner)) {
                return false;
            }

            body.classList.remove('md-admin-drawer-open');
            drawer.setAttribute('aria-hidden', 'true');
            drawer.hidden = true;
            backdrop.hidden = true;
            drawer.style.removeProperty('--md-admin-drawer-width');

            if (current.bodyMount) {
                restoreNode(current.bodyMount);
            }
            if (current.footerMount) {
                restoreNode(current.footerMount);
            }
            resetContainers();

            if (typeof current.onClose === 'function') {
                current.onClose();
            }

            if (current.restoreFocus && current.restoreFocus.isConnected && typeof current.restoreFocus.focus === 'function') {
                current.restoreFocus.focus();
            }

            current = null;
            return true;
        }

        function mountNode(node, target) {
            if (!node || !target) {
                return null;
            }
            var mount = {
                node: node,
                parent: node.parentNode,
                nextSibling: node.nextSibling,
                wasHidden: !!node.hidden
            };
            node.hidden = false;
            target.appendChild(node);
            return mount;
        }

        function openDrawer(options) {
            if (!options || !options.body) {
                return false;
            }

            closeDrawer();
            if (window.MDJAdminSearch && typeof window.MDJAdminSearch.close === 'function') {
                window.MDJAdminSearch.close();
            }

            var drawerTitle = options.title || '';
            var drawerSubtitle = options.subtitle || '';
            var drawerEyebrow = options.eyebrow || '';

            title.textContent = drawerTitle;
            eyebrow.textContent = drawerEyebrow;
            subtitle.textContent = drawerSubtitle;
            eyebrow.hidden = !drawerEyebrow;
            subtitle.hidden = !drawerSubtitle;

            if (options.width) {
                drawer.style.setProperty('--md-admin-drawer-width', options.width);
            }

            current = {
                owner: options.owner || '',
                onClose: options.onClose || null,
                restoreFocus: document.activeElement && document.activeElement !== document.body ? document.activeElement : null,
                bodyMount: mountNode(options.body, bodyNode),
                footerMount: options.footer ? mountNode(options.footer, footerNode) : null
            };

            footerNode.hidden = !current.footerMount;
            backdrop.hidden = false;
            drawer.hidden = false;
            drawer.setAttribute('aria-hidden', 'false');
            body.classList.add('md-admin-drawer-open');

            if (typeof window.CustomEvent === 'function') {
                document.dispatchEvent(new window.CustomEvent('md:drawer-opened', {
                    detail: {
                        owner: current.owner,
                        body: options.body,
                        footer: options.footer || null
                    }
                }));
            }

            window.setTimeout(function () {
                var focusTarget = typeof options.focus === 'function' ? options.focus() : options.focus;
                if (focusTarget && typeof focusTarget.focus === 'function') {
                    focusTarget.focus();
                    return;
                }
                drawer.focus();
            }, 40);

            return true;
        }

        if (backdrop.dataset.mdDrawerBound !== '1') {
            backdrop.dataset.mdDrawerBound = '1';
            backdrop.addEventListener('click', function () {
                closeDrawer();
            });
        }

        drawer.querySelectorAll('[data-md-drawer-close]').forEach(function (button) {
            if (button.dataset.mdDrawerBound === '1') {
                return;
            }
            button.dataset.mdDrawerBound = '1';
            button.addEventListener('click', function (event) {
                event.preventDefault();
                closeDrawer();
            });
        });

        if (!window.MDJAdminDrawerEscapeBound) {
            window.MDJAdminDrawerEscapeBound = true;
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeDrawer();
                }
            });
        }

        window.MDJAdminDrawerHost = {
            open: openDrawer,
            close: closeDrawer,
            getCurrentOwner: function () {
                return current ? current.owner : '';
            }
        };
    }

    function initAdminSidebarSections(root) {
        root.querySelectorAll('[data-md-sidebar-category-toggle]').forEach(function (button) {
            var categoryId = button.getAttribute('data-md-sidebar-category-toggle');
            var target = document.getElementById('row_' + categoryId);
            var cookieName = 'sub_' + categoryId;
            var shouldShow = getCookie(cookieName) !== 'off';

            setCollapseState(target, shouldShow ? 'show' : 'hide');
            button.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');

            if (button.dataset.mdSidebarBound === '1') {
                return;
            }
            button.dataset.mdSidebarBound = '1';
            button.addEventListener('click', function () {
                var isOpen = target ? target.classList.contains('show') : false;
                setCookie(cookieName, isOpen ? 'off' : 'on', 180);
                setCollapseState(target, 'toggle');
                button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            });
        });
    }

    function initPersistentCollapses(root) {
        root.querySelectorAll('[data-md-collapse-toggle]').forEach(function (button) {
            var collapseId = button.getAttribute('data-md-collapse-toggle');
            var target = document.getElementById('row_' + collapseId);
            var cookieName = 'sub_' + collapseId;
            var shouldShow = getCookie(cookieName) !== 'off';

            setCollapseState(target, shouldShow ? 'show' : 'hide');
            button.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');

            if (button.dataset.mdCollapseBound === '1') {
                return;
            }
            button.dataset.mdCollapseBound = '1';
            button.addEventListener('click', function () {
                var isOpen = target ? target.classList.contains('show') : false;
                setCookie(cookieName, isOpen ? 'off' : 'on', 180);
                setCollapseState(target, 'toggle');
                button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            });
        });
    }

    function initToggleTargets(root) {
        root.querySelectorAll('[data-md-toggle-target]').forEach(function (button) {
            if (button.dataset.mdToggleTargetBound === '1') {
                return;
            }
            button.dataset.mdToggleTargetBound = '1';
            button.addEventListener('click', function () {
                var selector = button.getAttribute('data-md-toggle-target');
                if (!selector) {
                    return;
                }
                document.querySelectorAll(selector).forEach(function (target) {
                    target.classList.toggle('is-visible');
                });
            });
        });
    }

    function initHintActions(root) {
        root.querySelectorAll('[data-md-hide-hint]').forEach(function (button) {
            if (button.dataset.mdHideHintBound === '1') {
                return;
            }
            button.dataset.mdHideHintBound = '1';
            button.addEventListener('click', function () {
                var hintId = button.getAttribute('data-md-hide-hint');
                var hint = document.getElementById('hint_' + hintId);
                setCookie('hint_' + hintId, 'off', 180);
                if (hint) {
                    hint.style.display = 'none';
                }
            });
        });

        root.querySelectorAll('[data-md-reset-hints]').forEach(function (button) {
            if (button.dataset.mdResetHintsBound === '1') {
                return;
            }
            button.dataset.mdResetHintsBound = '1';
            button.addEventListener('click', function () {
                var message = button.getAttribute('data-confirm');
                if (message && !window.confirm(message)) {
                    return;
                }

                document.cookie.split(';').forEach(function (pair) {
                    var cookieName = pair.split('=')[0].trim();
                    if (cookieName.indexOf('hint_') === 0) {
                        deleteCookie(cookieName);
                        var hint = document.getElementById('hint_' + cookieName.replace('hint_', ''));
                        if (hint) {
                            hint.style.display = '';
                        }
                    }
                    if (cookieName.indexOf('sub_') === 0) {
                        deleteCookie(cookieName);
                        setCollapseState(document.getElementById('row_' + cookieName.replace('sub_', '')), 'show');
                    }
                });
            });
        });
    }

    function initConfirmActions(root) {
        root.querySelectorAll('[data-md-confirm]').forEach(function (element) {
            if (element.dataset.mdConfirmBound === '1') {
                return;
            }
            element.dataset.mdConfirmBound = '1';
            element.addEventListener('click', function (event) {
                var message = element.getAttribute('data-md-confirm');
                if (message && !window.confirm(message)) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            });
        });
    }

    function initClassesTree(root) {
        var cardsById = {};
        root.querySelectorAll('[data-md-class-card]').forEach(function (card) {
            cardsById[card.getAttribute('data-md-class-id')] = card;
        });

        function setHoverGroup(card, state) {
            if (!card) {
                return;
            }
            card.classList.toggle('is-hover-group', state);

            var parentId = card.getAttribute('data-md-class-parent-id');
            if (parentId && parentId !== '0' && cardsById[parentId]) {
                cardsById[parentId].classList.toggle('is-hover-group', state);
            }

            var ownId = card.getAttribute('data-md-class-id');
            root.querySelectorAll('[data-md-class-parent-id="' + ownId + '"]').forEach(function (child) {
                child.classList.toggle('is-hover-group', state);
            });
        }

        root.querySelectorAll('[data-md-class-card]').forEach(function (card) {
            if (card.dataset.mdClassHoverBound === '1') {
                return;
            }
            card.dataset.mdClassHoverBound = '1';
            card.addEventListener('mouseenter', function () {
                setHoverGroup(card, true);
            });
            card.addEventListener('mouseleave', function () {
                setHoverGroup(card, false);
            });
        });

        root.querySelectorAll('[data-md-class-collapse]').forEach(function (target) {
            var classId = target.getAttribute('data-md-class-collapse');
            if (getCookie('sub_classes_' + classId) === '1') {
                setCollapseState(target, 'show');
            }
        });

        root.querySelectorAll('[data-md-class-toggle]').forEach(function (button) {
            var ids = (button.getAttribute('data-md-class-toggle') || '')
                .split(',')
                .map(function (id) {
                    return id.trim();
                })
                .filter(Boolean);
            var primaryId = ids[0];
            var primaryTarget = primaryId ? document.getElementById('sub_' + primaryId) : null;

            if (primaryTarget) {
                button.setAttribute('aria-expanded', primaryTarget.classList.contains('show') ? 'true' : 'false');
            }

            if (button.dataset.mdClassToggleBound === '1') {
                return;
            }
            button.dataset.mdClassToggleBound = '1';
            button.addEventListener('click', function () {
                if (!primaryTarget) {
                    return;
                }
                var isOpen = primaryTarget.classList.contains('show');
                var nextOpen = !isOpen;
                setCollapseState(primaryTarget, 'toggle');
                setCookie('sub_classes_' + primaryId, nextOpen ? '1' : '0', 180);
                button.setAttribute('aria-expanded', nextOpen ? 'true' : 'false');

                if (!nextOpen && ids.length > 1) {
                    ids.slice(1).forEach(function (id) {
                        setCollapseState(document.getElementById('sub_' + id), 'hide');
                        setCookie('sub_classes_' + id, '0', 180);
                    });
                }
            });
        });

        root.querySelectorAll('[data-md-class-search]').forEach(function (input) {
            if (input.dataset.mdClassSearchBound === '1') {
                return;
            }
            input.dataset.mdClassSearchBound = '1';
            input.addEventListener('input', function () {
                var term = input.value.trim().toLowerCase();
                var matches = [];
                document.querySelectorAll('[data-md-class-object-item]').forEach(function (item) {
                    item.classList.remove('is-filter-match');
                    if (term.length > 2 && item.textContent.toLowerCase().indexOf(term) !== -1) {
                        item.classList.add('is-filter-match');
                        matches.push(item);
                    }
                });
                if (matches.length === 1) {
                    matches[0].scrollIntoView({behavior: 'smooth', block: 'center'});
                }
            });
        });

        root.querySelectorAll('[data-md-global-search]').forEach(function (button) {
            if (button.dataset.mdGlobalSearchBound === '1') {
                return;
            }
            button.dataset.mdGlobalSearchBound = '1';
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var term = button.getAttribute('data-md-global-search') || '';
                if (window.MDJAdminUI && typeof window.MDJAdminUI.openSearch === 'function') {
                    window.MDJAdminUI.openSearch(term);
                }
            });
        });
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function initGlobalSearchDrawer(root) {
        var drawer = document.getElementById('mdmGlobalSearchDrawer');
        var input = document.getElementById('filter_modules');
        var results = document.getElementById('filter_add');
        var summary = document.getElementById('filter_summary');
        var hint = document.querySelector('.searchNoty');
        var body = document.body;
        var searchTimer = 0;
        var searchRequest = null;

        if (!drawer || !input || !results) {
            return;
        }

        function setSummary(text) {
            if (summary) {
                summary.textContent = text || '';
            }
        }

        function renderState(className, title, text) {
            results.innerHTML = '<div class="md-admin-search-state ' + className + '"><strong>' + escapeHtml(title) + '</strong><span>' + escapeHtml(text || '') + '</span></div>';
        }

        function renderResults(data) {
            var sections = data.sections || {};
            var total = Number(data.total || 0);

            if (hint) {
                hint.hidden = total > 0;
            }
            setSummary(total ? ('Найдено: ' + total) : '');

            if (!total) {
                renderState('is-empty', data.message || 'Ничего не найдено', 'Попробуйте другое название, объект, свойство, метод или фрагмент кода.');
                return;
            }

            var html = Object.keys(sections).map(function (sectionName) {
                var items = sections[sectionName] || [];
                if (!items.length) {
                    return '';
                }
                var cards = items.map(function (item) {
                    var meta = (item.meta || []).map(function (metaItem) {
                        return '<span>' + escapeHtml(metaItem) + '</span>';
                    }).join('');
                    return '<a class="md-admin-search-result" href="' + escapeHtml(item.url || '#') + '">' +
                        '<span class="md-admin-search-result__type md-admin-search-result__type--' + escapeHtml(item.type || 'item') + '">' + escapeHtml(item.type || 'item') + '</span>' +
                        '<span class="md-admin-search-result__content">' +
                            '<strong>' + escapeHtml(item.title) + '</strong>' +
                            (item.description ? '<small>' + escapeHtml(item.description) + '</small>' : '') +
                            (meta ? '<span class="md-admin-search-result__meta">' + meta + '</span>' : '') +
                        '</span>' +
                    '</a>';
                }).join('');
                return '<section class="md-admin-search-section">' +
                    '<header><h3>' + escapeHtml(sectionName) + '</h3><span>' + items.length + '</span></header>' +
                    '<div class="md-admin-search-section__items">' + cards + '</div>' +
                '</section>';
            }).join('');

            results.innerHTML = html;
        }

        function performSearch() {
            var term = input.value.trim();

            if (searchRequest) {
                searchRequest.abort();
                searchRequest = null;
            }

            if (term.length <= 2) {
                setSummary('');
                if (hint) {
                    hint.hidden = false;
                }
                renderState('is-idle', 'Введите больше 2 символов', 'Поиск смотрит модули, классы, объекты, свойства, методы, скрипты и поддерживаемые устройства.');
                return;
            }

            renderState('is-loading', 'Ищем...', term);
            searchRequest = new AbortController();
            fetch('?ajax_panel=1&op=filter&title=' + encodeURIComponent(term), {
                headers: {
                    'Accept': 'application/json'
                },
                signal: searchRequest.signal
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Search request failed: ' + response.status);
                    }
                    return response.json();
                })
                .then(renderResults)
                .catch(function (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    setSummary('');
                    renderState('is-error', 'Ошибка поиска', error.message || 'Не удалось получить результаты.');
                });
        }

        function scheduleSearch() {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(performSearch, 220);
        }

        function openSearch(value) {
            if (window.MDJAdminDrawerHost && typeof window.MDJAdminDrawerHost.close === 'function') {
                window.MDJAdminDrawerHost.close();
            }
            body.classList.add('md-admin-search-open');
            drawer.setAttribute('aria-hidden', 'false');
            if (typeof value === 'string') {
                input.value = value;
            }
            window.setTimeout(function () {
                input.focus();
                input.select();
            }, 60);
            performSearch();
        }

        function closeSearch() {
            body.classList.remove('md-admin-search-open');
            drawer.setAttribute('aria-hidden', 'true');
        }

        window.MDJAdminSearch = {
            open: openSearch,
            close: closeSearch,
            search: performSearch,
            setFilter: function (value) {
                openSearch(value || '');
                return false;
            }
        };

        root.querySelectorAll('[data-md-search-open]').forEach(function (button) {
            if (button.dataset.mdSearchOpenBound === '1') {
                return;
            }
            button.dataset.mdSearchOpenBound = '1';
            button.addEventListener('click', function () {
                openSearch();
            });
        });

        root.querySelectorAll('[data-md-search-close]').forEach(function (button) {
            if (button.dataset.mdSearchCloseBound === '1') {
                return;
            }
            button.dataset.mdSearchCloseBound = '1';
            button.addEventListener('click', closeSearch);
        });

        root.querySelectorAll('[data-md-search-clear]').forEach(function (button) {
            if (button.dataset.mdSearchClearBound === '1') {
                return;
            }
            button.dataset.mdSearchClearBound = '1';
            button.addEventListener('click', function () {
                input.value = '';
                input.focus();
                performSearch();
            });
        });

        if (input.dataset.mdSearchInputBound !== '1') {
            input.dataset.mdSearchInputBound = '1';
            input.addEventListener('input', scheduleSearch);
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && body.classList.contains('md-admin-search-open')) {
                closeSearch();
            }
        });

        results.addEventListener('click', function (event) {
            var link = event.target.closest('a');
            if (link) {
                closeSearch();
            }
        });

        renderState('is-idle', 'Введите больше 2 символов', 'Поиск смотрит модули, классы, объекты, свойства, методы, скрипты и поддерживаемые устройства.');
    }

    function initObjectPropertyHistoryDrawer(root) {
        var drawer = document.getElementById('mdObjectHistoryDrawer');
        var panel = document.getElementById('mdObjectHistoryPanel');
        var state = document.getElementById('mdObjectHistoryState');
        var title = document.getElementById('mdObjectHistoryTitle');
        var subtitle = document.getElementById('mdObjectHistorySubtitle');
        var rangeLabel = document.getElementById('mdObjectHistoryRangeLabel');
        var currentValue = document.getElementById('mdObjectHistoryCurrentValue');
        var currentMeta = document.getElementById('mdObjectHistoryCurrentMeta');
        var stats = document.getElementById('mdObjectHistoryStats');
        var chart = document.getElementById('mdObjectHistoryChart');
        var chartSummary = document.getElementById('mdObjectHistoryChartSummary');
        var feed = document.getElementById('mdObjectHistoryFeed');
        var feedCount = document.getElementById('mdObjectHistoryFeedCount');
        var pagination = document.getElementById('mdObjectHistoryPagination');
        var paginationPages = document.getElementById('mdObjectHistoryPaginationPages');
        var tabs = document.getElementById('mdObjectHistoryTabs');
        var body = document.body;
        var activeTrigger = null;
        var activeRange = '7d';
        var activePage = 1;
        var activeRequest = null;
        var activeTab = 'history';

        if (!drawer || !panel || !state) {
            return;
        }

        function setState(mode, heading, text) {
            state.className = 'md-object-history-state is-' + mode;
            state.innerHTML = '<strong>' + escapeHtml(heading || '') + '</strong>' +
                (text ? '<span>' + escapeHtml(text) + '</span>' : '');
            state.hidden = false;
            panel.hidden = true;
        }

        function formatValue(value) {
            if (value === null || typeof value === 'undefined' || value === '') {
                return '—';
            }
            if (typeof value === 'number') {
                return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/\.00$/, '');
            }
            return String(value);
        }

        function setRangeButtons(range) {
            drawer.querySelectorAll('[data-md-history-range]').forEach(function (button) {
                var isActive = button.getAttribute('data-md-history-range') === range;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        function setActiveTab(tabName) {
            activeTab = tabName || 'history';
            drawer.querySelectorAll('[data-md-history-tab]').forEach(function (button) {
                var isActive = button.getAttribute('data-md-history-tab') === activeTab;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            drawer.querySelectorAll('[data-md-history-panel]').forEach(function (panelNode) {
                panelNode.hidden = panelNode.getAttribute('data-md-history-panel') !== activeTab;
            });
        }

        function toggleChartTab(isVisible) {
            var chartButton = drawer.querySelector('[data-md-history-tab="chart"]');
            if (tabs) {
                tabs.hidden = !isVisible;
            }
            if (chartButton) {
                chartButton.hidden = !isVisible;
            }
            if (!isVisible) {
                setActiveTab('history');
            }
        }

        function openDrawer(trigger) {
            activeTrigger = trigger;
            activePage = 1;
            body.classList.add('md-object-history-open');
            drawer.setAttribute('aria-hidden', 'false');
            title.textContent = trigger.getAttribute('data-property-name') || 'История свойства';
            subtitle.textContent = trigger.getAttribute('data-property-description') || '';
            setRangeButtons(activeRange);
            setActiveTab('history');
            loadHistory();
        }

        function closeDrawer() {
            body.classList.remove('md-object-history-open');
            drawer.setAttribute('aria-hidden', 'true');
            if (activeRequest) {
                activeRequest.abort();
                activeRequest = null;
            }
            if (activeTrigger) {
                activeTrigger.focus();
            }
        }

        function buildPaginationPages(currentPage, totalPages) {
            var pages = [];
            var startPage = Math.max(1, currentPage - 2);
            var endPage = Math.min(totalPages, currentPage + 2);

            if (startPage > 1) {
                pages.push(1);
            }
            if (startPage > 2) {
                pages.push('dots-left');
            }
            for (var page = startPage; page <= endPage; page++) {
                pages.push(page);
            }
            if (endPage < totalPages - 1) {
                pages.push('dots-right');
            }
            if (endPage < totalPages) {
                pages.push(totalPages);
            }

            return pages;
        }

        function renderPagination(meta) {
            if (!pagination || !paginationPages) {
                return;
            }

            var totalPages = Number(meta && meta.pages ? meta.pages : 0);
            var currentPage = Number(meta && meta.page ? meta.page : 1);
            activePage = currentPage;

            if (totalPages <= 1) {
                pagination.hidden = true;
                paginationPages.innerHTML = '';
                return;
            }

            pagination.hidden = false;
            paginationPages.innerHTML = buildPaginationPages(currentPage, totalPages).map(function (item) {
                if (typeof item !== 'number') {
                    return '<span class="md-object-history-pagination__dots">...</span>';
                }
                var activeClass = item === currentPage ? ' is-active' : '';
                return '<button type="button" class="md-object-history-pagination__page' + activeClass + '" data-md-history-page="' + item + '">' + item + '</button>';
            }).join('');

            pagination.querySelectorAll('[data-md-history-page-nav]').forEach(function (button) {
                var direction = button.getAttribute('data-md-history-page-nav');
                if (direction === 'prev') {
                    button.disabled = !meta.has_prev;
                } else if (direction === 'next') {
                    button.disabled = !meta.has_next;
                }
            });
        }

        function renderStats(data) {
            var items = [
                {label: 'Изменений', value: data.changes},
                {label: 'Минимум', value: formatValue(data.min)},
                {label: 'Среднее', value: formatValue(data.avg)},
                {label: 'Максимум', value: formatValue(data.max)}
            ];

            stats.innerHTML = items.map(function (item) {
                return '<article class="md-object-history-stat">' +
                    '<span>' + escapeHtml(item.label) + '</span>' +
                    '<strong>' + escapeHtml(formatValue(item.value)) + '</strong>' +
                '</article>';
            }).join('');
        }

        function buildChartSvg(points, minValue, maxValue) {
            var width = 820;
            var height = 280;
            var paddingX = 18;
            var paddingY = 20;
            var usableWidth = width - (paddingX * 2);
            var usableHeight = height - (paddingY * 2);
            var range = maxValue - minValue || 1;
            var path = '';
            var dots = '';

            points.forEach(function (point, index) {
                var x = paddingX + ((usableWidth / Math.max(points.length - 1, 1)) * index);
                var ratio = (point.value - minValue) / range;
                var y = height - paddingY - (ratio * usableHeight);
                path += (index ? ' L ' : 'M ') + x.toFixed(2) + ' ' + y.toFixed(2);
                dots += '<circle cx="' + x.toFixed(2) + '" cy="' + y.toFixed(2) + '" r="3.5">' +
                    '<title>' + escapeHtml(point.label + ' - ' + formatValue(point.value)) + '</title>' +
                '</circle>';
            });

            return '' +
                '<svg viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="График изменения свойства">' +
                    '<defs>' +
                        '<linearGradient id="mdObjectHistoryLine" x1="0%" y1="0%" x2="100%" y2="0%">' +
                            '<stop offset="0%" stop-color="#4792d1"></stop>' +
                            '<stop offset="100%" stop-color="#0f8a64"></stop>' +
                        '</linearGradient>' +
                    '</defs>' +
                    '<line x1="' + paddingX + '" y1="' + paddingY + '" x2="' + paddingX + '" y2="' + (height - paddingY) + '" class="md-object-history-chart__axis"></line>' +
                    '<line x1="' + paddingX + '" y1="' + (height - paddingY) + '" x2="' + (width - paddingX) + '" y2="' + (height - paddingY) + '" class="md-object-history-chart__axis"></line>' +
                    '<path d="' + path + '" class="md-object-history-chart__line"></path>' +
                    '<g class="md-object-history-chart__dots">' + dots + '</g>' +
                '</svg>';
        }

        function renderChart(chartData) {
            if (!chartData || !chartData.has_data || !chartData.points || chartData.points.length < 2) {
                chart.innerHTML = '<div class="md-object-history-chart__empty">Недостаточно числовых данных для построения графика.</div>';
                chartSummary.textContent = 'График недоступен';
                return;
            }

            chart.innerHTML = buildChartSvg(chartData.points, Number(chartData.min || 0), Number(chartData.max || 0));
            chartSummary.textContent = [
                chartData.first_label || '',
                chartData.last_label || ''
            ].filter(Boolean).join(' - ');
        }

        function renderFeed(items, paginationMeta) {
            var total = Number(paginationMeta && paginationMeta.total ? paginationMeta.total : 0);
            var from = Number(paginationMeta && paginationMeta.from ? paginationMeta.from : 0);
            var to = Number(paginationMeta && paginationMeta.to ? paginationMeta.to : 0);
            feedCount.textContent = total ? ('Показаны ' + from + '-' + to + ' из ' + total) : '';
            if (!items.length) {
                feed.innerHTML = '<tr><td colspan="3" class="md-object-history-table__empty">История за выбранный период пока пуста.</td></tr>';
                if (pagination) {
                    pagination.hidden = true;
                }
                return;
            }

            feed.innerHTML = items.map(function (item) {
                return '<tr>' +
                    '<td class="md-object-history-table__time">' + escapeHtml(item.added_label || '') + '</td>' +
                    '<td class="md-object-history-table__value">' + escapeHtml(formatValue(item.value)) + '</td>' +
                    '<td class="md-object-history-table__source">' + escapeHtml(item.source || '—') + '</td>' +
                '</tr>';
            }).join('');
        }

        function renderHistory(data) {
            title.textContent = (data.meta && data.meta.property_name) || (activeTrigger ? activeTrigger.getAttribute('data-property-name') : 'История свойства');
            subtitle.textContent = (data.meta && data.meta.description) || (activeTrigger ? activeTrigger.getAttribute('data-property-description') : '');
            rangeLabel.textContent = data.range && data.range.label ? data.range.label : '';
            currentValue.textContent = formatValue(data.current ? data.current.value : '');

            var metaParts = [];
            if (data.current && data.current.updated_label) {
                metaParts.push('Обновлено: ' + data.current.updated_label);
            }
            if (data.current && data.current.source) {
                metaParts.push('Источник: ' + data.current.source);
            }
            currentMeta.textContent = metaParts.join(' • ');

            renderStats(data.stats || {});
            renderFeed(data.history || [], data.history_pagination || {});
            renderPagination(data.history_pagination || {});
            toggleChartTab(!!(data.meta && data.meta.is_numeric));
            if (data.meta && data.meta.is_numeric) {
                renderChart(data.chart || {});
            } else {
                chart.innerHTML = '';
                chartSummary.textContent = '';
            }

            state.hidden = true;
            panel.hidden = false;
            setActiveTab('history');
        }

        function loadHistory() {
            if (!activeTrigger) {
                return;
            }

            var baseUrl = activeTrigger.getAttribute('data-history-url');
            if (!baseUrl) {
                setState('error', 'Ошибка', 'Не найден URL для загрузки истории.');
                return;
            }

            if (activeRequest) {
                activeRequest.abort();
            }

            setState('loading', 'Загружаю историю', 'Получаю точки графика и последние изменения.');
            activeRequest = new AbortController();
            fetch(baseUrl + '&history_range=' + encodeURIComponent(activeRange) + '&history_page=' + encodeURIComponent(activePage), {
                headers: {
                    'Accept': 'application/json'
                },
                signal: activeRequest.signal
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('History request failed: ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (!data || data.status !== 'ok') {
                        throw new Error(data && data.message ? data.message : 'Не удалось загрузить историю.');
                    }
                    activeRequest = null;
                    renderHistory(data);
                })
                .catch(function (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    activeRequest = null;
                    setState('error', 'История недоступна', error.message || 'Не удалось получить данные.');
                });
        }

        root.querySelectorAll('[data-md-prop-history-open]').forEach(function (button) {
            if (button.dataset.mdPropHistoryBound === '1') {
                return;
            }
            button.dataset.mdPropHistoryBound = '1';
            button.addEventListener('click', function () {
                openDrawer(button);
            });
        });

        root.querySelectorAll('[data-md-object-history-close]').forEach(function (button) {
            if (button.dataset.mdObjectHistoryCloseBound === '1') {
                return;
            }
            button.dataset.mdObjectHistoryCloseBound = '1';
            button.addEventListener('click', closeDrawer);
        });

        drawer.querySelectorAll('[data-md-history-range]').forEach(function (button) {
            if (button.dataset.mdHistoryRangeBound === '1') {
                return;
            }
            button.dataset.mdHistoryRangeBound = '1';
            button.addEventListener('click', function () {
                var nextRange = button.getAttribute('data-md-history-range') || '7d';
                if (nextRange === activeRange) {
                    return;
                }
                activeRange = nextRange;
                activePage = 1;
                setRangeButtons(activeRange);
                loadHistory();
            });
        });

        if (drawer.dataset.mdHistoryPaginationBound !== '1') {
            drawer.dataset.mdHistoryPaginationBound = '1';
            drawer.addEventListener('click', function (event) {
                var pageButton = event.target.closest('[data-md-history-page]');
                if (pageButton) {
                    var nextPage = parseInt(pageButton.getAttribute('data-md-history-page'), 10);
                    if (!isNaN(nextPage) && nextPage > 0 && nextPage !== activePage) {
                        activePage = nextPage;
                        loadHistory();
                    }
                    return;
                }

                var navButton = event.target.closest('[data-md-history-page-nav]');
                if (navButton) {
                    if (navButton.disabled) {
                        return;
                    }
                    var direction = navButton.getAttribute('data-md-history-page-nav');
                    if (direction === 'prev' && activePage > 1) {
                        activePage -= 1;
                        loadHistory();
                    } else if (direction === 'next') {
                        activePage += 1;
                        loadHistory();
                    }
                    return;
                }
            });
        }

        drawer.querySelectorAll('[data-md-history-tab]').forEach(function (button) {
            if (button.dataset.mdHistoryTabBound === '1') {
                return;
            }
            button.dataset.mdHistoryTabBound = '1';
            button.addEventListener('click', function () {
                if (button.hidden) {
                    return;
                }
                setActiveTab(button.getAttribute('data-md-history-tab') || 'history');
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && body.classList.contains('md-object-history-open')) {
                closeDrawer();
            }
        });
    }

    function initAdminConsoleDrawer(root) {
        var drawerContent = document.getElementById('mdAdminConsoleDrawerContent');
        var drawerFooter = document.getElementById('mdAdminConsoleDrawerFooter');
        var output = document.getElementById('console_output');
        var outputHint = document.getElementById('console_output_hintResize');
        var command = document.getElementById('command');
        var commandEditorHost = drawerContent ? drawerContent.querySelector('[data-code-editor-key="admin_console"]') : null;
        var form = drawerContent ? drawerContent.querySelector('form') : null;
        var moduleSelect = document.getElementById('currModuleName');
        var moduleField = document.getElementById('module_add');
        var methodsModule = document.getElementById('methodsModule');
        var methodsList = methodsModule ? methodsModule.querySelector('ul') : null;
        var loaderConsole = document.getElementById('loaderConsole');
        var loaderConsoleModule = document.getElementById('loaderConsoleModule');
        var dangerAlert = document.getElementById('dangerAlertConsole');
        var warningAlert = document.getElementById('warningAlertConsole');
        var historyList = document.getElementById('consoleHistoryList');
        var toggleModuleButton = document.getElementById('btnConsoleToggleModule');
        var clearHistoryButton = document.getElementById('btnConsoleClearHistory');
        var currentModuleName = '';
        var consoleHistoryKey = 'md-admin-console-history-v1';
        var consoleHistory = [];
        var currentHistoryIndex = -1;
        var pendingModuleLoad = false;

        if (!drawerContent || !command || !output) {
            return;
        }

        function escapeHtml(value) {
            return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (char) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char];
            });
        }

        function getConsoleEditor() {
            return commandEditorHost && commandEditorHost._codeEditor ? commandEditorHost._codeEditor : null;
        }

        function getCommandValue() {
            var editor = getConsoleEditor();
            return editor ? editor.getValue() : (command ? command.value : '');
        }

        function setCommandValue(value) {
            var editor = getConsoleEditor();
            var text = String(value || '');
            currentHistoryIndex = -1;

            if (editor) {
                editor.setValue(text);
                editor.focus();
                if (typeof editor.setCursor === 'function') {
                    var lastLine = Math.max(editor.lastLine(), 0);
                    editor.setCursor({line: lastLine, ch: editor.getLine(lastLine).length});
                }
                return;
            }

            if (command) {
                command.value = text;
                command.focus();
                command.setSelectionRange(command.value.length, command.value.length);
            }
        }

        function focusCommand() {
            var editor = getConsoleEditor();
            if (editor) {
                editor.focus();
                return;
            }
            if (command) {
                command.focus();
            }
        }

        function openDrawer() {
            if (!window.MDJAdminDrawerHost) {
                return;
            }
            window.MDJAdminDrawerHost.open({
                owner: 'console',
                eyebrow: drawerContent.getAttribute('data-md-drawer-eyebrow') || 'Панель управления',
                title: drawerContent.getAttribute('data-md-drawer-title') || 'Консоль',
                subtitle: drawerContent.getAttribute('data-md-drawer-subtitle') || '',
                width: '920px',
                body: drawerContent,
                footer: drawerFooter,
                focus: function () {
                    ensureConsoleEditorBindings();
                    return getConsoleEditor() ? null : command;
                }
            });
            window.setTimeout(function () {
                ensureConsoleEditorBindings();
                focusCommand();
            }, 40);
        }

        function closeDrawer() {
            if (window.MDJAdminDrawerHost) {
                window.MDJAdminDrawerHost.close('console');
            }
        }

        function bindConsoleEditor() {
            var editor = getConsoleEditor();
            if (!editor || !commandEditorHost || commandEditorHost.dataset.mdConsoleEditorBound === '1') {
                return false;
            }

            commandEditorHost.dataset.mdConsoleEditorBound = '1';

            var existingExtraKeys = editor.getOption('extraKeys') || {};
            var extraKeys = {};
            Object.keys(existingExtraKeys).forEach(function (key) {
                extraKeys[key] = existingExtraKeys[key];
            });

            extraKeys['Ctrl-Enter'] = function () {
                sendCommand();
            };
            extraKeys['Cmd-Enter'] = function () {
                sendCommand();
            };
            extraKeys['Up'] = function (cm) {
                if (cm.getCursor().line === 0 && cm.getCursor().ch === 0) {
                    moveHistory(1);
                    return;
                }
                return CodeMirror.Pass;
            };
            extraKeys['Down'] = function (cm) {
                var cursor = cm.getCursor();
                var lastLine = cm.lastLine();
                if (cursor.line === lastLine && cursor.ch === cm.getLine(lastLine).length) {
                    moveHistory(-1);
                    return;
                }
                return CodeMirror.Pass;
            };

            editor.setOption('extraKeys', extraKeys);
            editor.on('change', function () {
                currentHistoryIndex = -1;
            });

            if (window.MDJAdminDrawerHost && window.MDJAdminDrawerHost.getCurrentOwner() === 'console') {
                window.setTimeout(function () {
                    editor.focus();
                }, 0);
            }

            return true;
        }

        function ensureConsoleEditorBindings() {
            if (bindConsoleEditor()) {
                return;
            }

            if (drawerContent._codeEditorBindTimer) {
                return;
            }

            var attempts = 0;
            drawerContent._codeEditorBindTimer = window.setInterval(function () {
                attempts += 1;
                if (bindConsoleEditor() || attempts > 100) {
                    window.clearInterval(drawerContent._codeEditorBindTimer);
                    drawerContent._codeEditorBindTimer = 0;
                }
            }, 120);
        }

        function toggleDrawer() {
            if (window.MDJAdminDrawerHost && window.MDJAdminDrawerHost.getCurrentOwner() === 'console') {
                closeDrawer();
            } else {
                openDrawer();
            }
            return false;
        }

        function readHistory() {
            try {
                var raw = window.localStorage.getItem(consoleHistoryKey);
                var parsed = raw ? JSON.parse(raw) : [];
                if (!Array.isArray(parsed)) {
                    return [];
                }
                return parsed.filter(function (item) {
                    return typeof item === 'string' && item.trim() !== '';
                }).slice(0, 20);
            } catch (e) {
                return [];
            }
        }

        function saveHistory(items) {
            try {
                window.localStorage.setItem(consoleHistoryKey, JSON.stringify(items.slice(0, 20)));
            } catch (e) {
                // Ignore storage errors. Console still works without persistence.
            }
        }

        function pushHistory(value) {
            var normalized = String(value || '').trim();
            if (!normalized) {
                return;
            }
            consoleHistory = consoleHistory.filter(function (item) {
                return item !== normalized;
            });
            consoleHistory.unshift(normalized);
            saveHistory(consoleHistory);
            renderHistory();
        }

        function renderHistory() {
            if (!historyList) {
                return;
            }
            historyList.innerHTML = '';
            if (!consoleHistory.length) {
                historyList.innerHTML = '<div class="md-admin-console-history__empty">История пуста. После отправки команд она появится здесь и в стрелках редактора.</div>';
                return;
            }

            consoleHistory.forEach(function (item) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'md-admin-console-history__item';
                button.setAttribute('data-md-console-history-value', item);
                button.title = 'Вставить в редактор';
                button.textContent = item;
                historyList.appendChild(button);
            });
        }

        function setAlerts(kind, message) {
            if (dangerAlert) {
                dangerAlert.hidden = kind !== 'danger';
                dangerAlert.textContent = kind === 'danger' ? message : dangerAlert.textContent;
            }
            if (warningAlert) {
                warningAlert.hidden = kind !== 'warning';
                warningAlert.textContent = kind === 'warning' ? message : warningAlert.textContent;
            }
        }

        function clearAlerts() {
            if (dangerAlert) {
                dangerAlert.hidden = true;
            }
            if (warningAlert) {
                warningAlert.hidden = true;
            }
        }

        function setLoaderVisible(node, visible) {
            if (!node) {
                return;
            }
            node.classList.toggle('is-visible', !!visible);
        }

        function escapeCommandForDisplay(value) {
            return escapeHtml(value).replace(/\n/g, '<br>');
        }

        function appendOutputEntry(commandText, resultText, metaText) {
            var entry = document.createElement('div');
            entry.className = 'md-admin-console-output__entry';

            var meta = document.createElement('div');
            meta.className = 'md-admin-console-output__meta';
            meta.innerHTML = '<strong>' + escapeHtml(metaText || 'консоль') + '</strong>';

            var commandNode = document.createElement('div');
            commandNode.className = 'md-admin-console-output__command';
            commandNode.innerHTML = escapeCommandForDisplay(commandText);

            var resultNode = document.createElement('div');
            resultNode.className = 'md-admin-console-output__result';
            resultNode.innerHTML = escapeCommandForDisplay(resultText || '');

            entry.appendChild(meta);
            entry.appendChild(commandNode);
            entry.appendChild(resultNode);

            output.prepend(entry);
        }

        function setOutputMessage(message, kind) {
            output.innerHTML = '<div class="md-admin-console-output__entry"><div class="md-admin-console-output__meta"><strong>' + escapeHtml(kind || 'консоль') + '</strong></div><div class="md-admin-console-output__result">' + escapeCommandForDisplay(message) + '</div></div>';
        }

        function setMethods(methods) {
            if (!methodsModule || !methodsList) {
                return;
            }
            methodsList.innerHTML = '';
            if (!Array.isArray(methods) || !methods.length) {
                methodsModule.hidden = false;
                methodsList.innerHTML = '<div class="md-admin-console-methods__empty">Для выбранного модуля не найдено доступных методов.</div>';
                return;
            }

            methodsModule.hidden = false;
            methods.forEach(function (methodName) {
                var method = String(methodName || '').trim();
                if (!method) {
                    return;
                }
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-sm btn-outline-primary md-admin-console-methods__item';
                button.setAttribute('data-md-console-method', method);
                button.textContent = '$' + currentModuleName + '->' + method + '();';
                methodsList.appendChild(button);
            });
        }

        function setModuleVisibility(visible) {
            if (!moduleSelect) {
                return;
            }
            moduleSelect.classList.toggle('is-visible', !!visible);
        }

        function insertIntoTextarea(value) {
            var text = String(value || '');
            if (!text) {
                return;
            }
            setCommandValue(text);
        }

        function buildModuleBootstrapCommand(moduleName) {
            return "include(DIR_MODULES.'" + moduleName + "/" + moduleName + ".class.php');PHP_EOL$" + moduleName + " = new " + moduleName + "();";
        }

        function loadModuleMethods(moduleName) {
            var module = String(moduleName || '').trim();
            if (module && !/^[A-Za-z_][A-Za-z0-9_]*$/.test(module)) {
                setAlerts('danger', 'Некорректное имя модуля.');
                return;
            }
            currentModuleName = module;
            if (!module || module === '0') {
                moduleField.value = '';
                methodsModule.hidden = true;
                methodsList.innerHTML = '';
                return;
            }

            moduleField.value = buildModuleBootstrapCommand(module);
            methodsModule.hidden = false;
            setLoaderVisible(loaderConsoleModule, true);
            clearAlerts();
            pendingModuleLoad = true;

            var body = new URLSearchParams();
            body.set('ajax_panel', '1');
            body.set('op', 'console');
            body.set('command', moduleField.value + "PHP_EOLjson_encode(get_class_methods('" + module + "'));");

            fetch('?ajax_panel=1&op=console', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            }).then(function (response) {
                return response.text();
            }).then(function (text) {
                var methods = [];
                try {
                    methods = JSON.parse(text);
                } catch (e) {
                    methods = [];
                }
                setMethods(methods);
                setOutputMessage('Модуль подключен: ' + module, 'консоль');
            }).catch(function () {
                setMethods([]);
                setAlerts('warning', 'Не удалось загрузить список методов модуля.');
            }).finally(function () {
                pendingModuleLoad = false;
                setLoaderVisible(loaderConsoleModule, false);
            });
        }

        function sendCommand(customCommand) {
            var rawCommand = typeof customCommand === 'string' ? customCommand : getCommandValue();
            var trimmedCommand = String(rawCommand || '').trim();

            if (!trimmedCommand) {
                setAlerts('danger', 'Команда пуста. Введите код или выражение.');
                return false;
            }

            if (trimmedCommand === 'clear' || trimmedCommand === 'clear;') {
                clearAlerts();
                output.innerHTML = '<div class="md-admin-console-output__entry"><div class="md-admin-console-output__meta"><strong>консоль</strong></div><div class="md-admin-console-output__result"><em>Консоль очищена.</em></div></div>';
                setCommandValue('');
                setLoaderVisible(loaderConsole, false);
                return false;
            }

            clearAlerts();
            setLoaderVisible(loaderConsole, true);

            var finalCommand = trimmedCommand;
            if (!customCommand && moduleField && moduleField.value) {
                finalCommand = moduleField.value + trimmedCommand;
            }

            var body = new URLSearchParams();
            body.set('ajax_panel', '1');
            body.set('op', 'console');
            body.set('command', finalCommand);

            return fetch('?ajax_panel=1&op=console', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            }).then(function (response) {
                return response.text();
            }).then(function (text) {
                var result = text || 'Запрос выполнен.';
                appendOutputEntry(finalCommand, result, 'консоль');
                pushHistory(trimmedCommand);
                setCommandValue('');
                if (outputHint) {
                    outputHint.hidden = false;
                }
            }).catch(function () {
                setAlerts('danger', 'Не удалось отправить запрос.');
            }).finally(function () {
                setLoaderVisible(loaderConsole, false);
                if (!pendingModuleLoad) {
                    focusCommand();
                }
            });
        }

        function moveHistory(step) {
            if (!consoleHistory.length) {
                return;
            }
            if (currentHistoryIndex === -1) {
                currentHistoryIndex = step > 0 ? 0 : -1;
            } else if (step > 0) {
                currentHistoryIndex = Math.min(currentHistoryIndex + 1, consoleHistory.length - 1);
            } else {
                currentHistoryIndex = currentHistoryIndex <= 0 ? -1 : currentHistoryIndex - 1;
            }

            if (currentHistoryIndex === -1) {
                setCommandValue('');
                return;
            }

            setCommandValue(consoleHistory[currentHistoryIndex] || '');
        }

        consoleHistory = readHistory();
        renderHistory();
        ensureConsoleEditorBindings();

        if (!output.innerHTML.trim()) {
            setOutputMessage('Ожидание команды...', 'консоль');
        }

        if (drawerContent.dataset.mdConsoleBound !== '1') {
            drawerContent.dataset.mdConsoleBound = '1';
            drawerContent.addEventListener('click', function (event) {
                var historyButton = event.target.closest('[data-md-console-history-value]');
                if (historyButton) {
                    event.preventDefault();
                    insertIntoTextarea(historyButton.getAttribute('data-md-console-history-value'));
                    return;
                }

                var methodButton = event.target.closest('[data-md-console-method]');
                if (methodButton) {
                    event.preventDefault();
                    insertIntoTextarea(methodButton.getAttribute('data-md-console-method'));
                    return;
                }
            });
        }

        if (form && form.dataset.mdConsoleFormBound !== '1') {
            form.dataset.mdConsoleFormBound = '1';
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                sendCommand();
            });
        }

        if (toggleModuleButton && toggleModuleButton.dataset.mdConsoleBound !== '1') {
            toggleModuleButton.dataset.mdConsoleBound = '1';
            toggleModuleButton.addEventListener('click', function () {
                setModuleVisibility(!moduleSelect.classList.contains('is-visible'));
                if (moduleSelect.classList.contains('is-visible')) {
                    moduleSelect.focus();
                }
            });
        }

        if (clearHistoryButton && clearHistoryButton.dataset.mdConsoleBound !== '1') {
            clearHistoryButton.dataset.mdConsoleBound = '1';
            clearHistoryButton.addEventListener('click', function () {
                consoleHistory = [];
                saveHistory(consoleHistory);
                renderHistory();
                focusCommand();
            });
        }

        var clearCommandButton = document.getElementById('btnConsoleClearCommand');
        if (clearCommandButton && clearCommandButton.dataset.mdConsoleBound !== '1') {
            clearCommandButton.dataset.mdConsoleBound = '1';
            clearCommandButton.addEventListener('click', function () {
                setCommandValue('');
                focusCommand();
            });
        }

        if (moduleSelect && moduleSelect.dataset.mdConsoleBound !== '1') {
            moduleSelect.dataset.mdConsoleBound = '1';
            moduleSelect.addEventListener('change', function () {
                loadModuleMethods(moduleSelect.value);
            });
        }

        if (command.dataset.mdConsoleBound !== '1') {
            command.dataset.mdConsoleBound = '1';
            command.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowUp' && command.selectionStart === 0 && command.selectionEnd === 0) {
                    event.preventDefault();
                    moveHistory(1);
                    return;
                }
                if (event.key === 'ArrowDown' && command.selectionStart === command.value.length && command.selectionEnd === command.value.length) {
                    event.preventDefault();
                    moveHistory(-1);
                    return;
                }
                if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                    event.preventDefault();
                    sendCommand();
                }
            });
            command.addEventListener('input', function () {
                currentHistoryIndex = -1;
            });
        }

        root.querySelectorAll('[data-md-console-open]').forEach(function (button) {
            if (button.dataset.mdConsoleOpenBound === '1') {
                return;
            }
            button.dataset.mdConsoleOpenBound = '1';
            button.addEventListener('click', function (event) {
                event.preventDefault();
                openDrawer();
            });
        });

        if (!historyList.dataset.mdConsoleBound) {
            historyList.dataset.mdConsoleBound = '1';
        }

        window.MDJAdminConsole = {
            open: openDrawer,
            close: closeDrawer,
            toggle: toggleDrawer,
            send: sendCommand,
            insertModule: loadModuleMethods,
            insertCommand: insertIntoTextarea,
            getHistory: function () {
                return consoleHistory.slice();
            },
            setHistory: function (items) {
                consoleHistory = Array.isArray(items) ? items.filter(function (item) {
                    return typeof item === 'string' && item.trim() !== '';
                }).slice(0, 20) : [];
                saveHistory(consoleHistory);
                renderHistory();
            }
        };
    }

    var headerClockTimer = 0;

    function updateHeaderDateTime() {
        var clockNodes = document.querySelectorAll('[data-md-header-clock]');
        var dateNodes = document.querySelectorAll('[data-md-header-date]');
        if (!clockNodes.length && !dateNodes.length) {
            return;
        }

        var now = new Date();
        var timeText = new Intl.DateTimeFormat('ru-RU', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        }).format(now);
        var dateText = new Intl.DateTimeFormat('ru-RU', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        }).format(now);

        clockNodes.forEach(function (node) {
            node.textContent = timeText;
        });
        dateNodes.forEach(function (node) {
            node.textContent = dateText;
        });
    }

    function initHeaderDateTime() {
        updateHeaderDateTime();
        if (headerClockTimer) {
            return;
        }
        headerClockTimer = window.setInterval(updateHeaderDateTime, 1000);
    }

    function ensureActiveModuleTabsVisible(root) {
        var scope = root && root.querySelectorAll ? root : document;

        function alignTabs() {
            scope.querySelectorAll('.md-admin-module-tabs').forEach(function (tabs) {
                var activeLink = tabs.querySelector('.md-admin-module-tabs__link.is-active');
                var overflowX = Math.ceil(tabs.scrollWidth - tabs.clientWidth);
                var overflowY = Math.ceil(tabs.scrollHeight - tabs.clientHeight);

                if (!activeLink) {
                    return;
                }

                if (overflowX > 4) {
                    var tabsRect = tabs.getBoundingClientRect();
                    var activeRect = activeLink.getBoundingClientRect();
                    var currentScrollLeft = tabs.scrollLeft;
                    var targetScrollLeft = currentScrollLeft + (activeRect.left - tabsRect.left) - (tabs.clientWidth / 2) + (activeRect.width / 2);
                    tabs.scrollLeft = Math.max(0, Math.round(targetScrollLeft));
                }

                if (overflowY > 4) {
                    var currentScrollTop = tabs.scrollTop;
                    var tabsTop = tabs.getBoundingClientRect().top;
                    var activeTop = activeLink.getBoundingClientRect().top;
                    var targetScrollTop = currentScrollTop + (activeTop - tabsTop) - (tabs.clientHeight / 2) + (activeLink.offsetHeight / 2);
                    tabs.scrollTop = Math.max(0, Math.round(targetScrollTop));
                }
            });
        }

        alignTabs();
        window.requestAnimationFrame(alignTabs);
        window.setTimeout(alignTabs, 0);
    }

    function boot(root) {
        window.MDJAdminLastBootRoot = root;
        copyLegacyBootstrapAttributes(root);
        normalizeLegacyClasses(root);
        initThemeSwitcher(root);
        initCheckboxToggles(root);
        initBootstrapWidgets(root);
        initAdminDrawerHost(root);
        initAdminSidebarSections(root);
        initPersistentCollapses(root);
        initToggleTargets(root);
        initHintActions(root);
        initConfirmActions(root);
        initClassesTree(root);
        initGlobalSearchDrawer(root);
        initObjectPropertyHistoryDrawer(root);
        initAdminConsoleDrawer(root);
        initRegisteredModuleUIs(root);
        ensureActiveModuleTabsVisible(root);
    }

    var registeredModuleUIs = {};

    function initRegisteredModuleUIs(root) {
        Object.keys(registeredModuleUIs).forEach(function (name) {
            var moduleUI = registeredModuleUIs[name];
            if (moduleUI && typeof moduleUI.init === 'function') {
                moduleUI.init(root);
            }
        });
    }

    function initAdminSidebarDrawer() {
        var toggleButtons = document.querySelectorAll('[data-md-admin-sidebar-toggle]');
        var closeTargets = document.querySelectorAll('[data-md-admin-sidebar-close]');
        var body = document.body;

        function openSidebar() {
            body.classList.add('md-admin-sidebar-open');
        }

        function closeSidebar() {
            body.classList.remove('md-admin-sidebar-open');
        }

        toggleButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (body.classList.contains('md-admin-sidebar-open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        });

        closeTargets.forEach(function (target) {
            target.addEventListener('click', closeSidebar);
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        });

        document.querySelectorAll('.md-admin-sidebar__link').forEach(function (link) {
            link.addEventListener('click', closeSidebar);
        });
    }

    function scrollSidebarToActiveItem() {
        var sidebarInner = document.querySelector('.md-admin-sidebar__inner');
        if (!sidebarInner) {
            return;
        }

        var activeItem = sidebarInner.querySelector('.md-admin-sidebar__item.active, .md-admin-sidebar__item.is-active');
        if (!activeItem) {
            return;
        }

        window.requestAnimationFrame(function () {
            var sidebarRect = sidebarInner.getBoundingClientRect();
            var activeRect = activeItem.getBoundingClientRect();
            var currentScrollTop = sidebarInner.scrollTop;
            var targetScrollTop = currentScrollTop + (activeRect.top - sidebarRect.top) - (sidebarInner.clientHeight / 2) + (activeRect.height / 2);
            sidebarInner.scrollTop = Math.max(0, targetScrollTop);
        });
    }

    function callComponent(Component, element, commandOrOptions) {
        if (!Component || !element) {
            return;
        }
        var instance = Component.getOrCreateInstance(element, typeof commandOrOptions === 'object' ? commandOrOptions : {});
        if (typeof commandOrOptions === 'string' && typeof instance[commandOrOptions] === 'function') {
            instance[commandOrOptions]();
        }
    }

    function installJqueryBridge() {
        if (!window.jQuery || !window.bootstrap) {
            return;
        }

        var $ = window.jQuery;
        var bridge = {
            modal: window.bootstrap.Modal,
            collapse: window.bootstrap.Collapse,
            dropdown: window.bootstrap.Dropdown,
            tab: window.bootstrap.Tab,
            button: window.bootstrap.Button,
            tooltip: window.bootstrap.Tooltip,
            popover: window.bootstrap.Popover,
            alert: window.bootstrap.Alert
        };

        Object.keys(bridge).forEach(function (name) {
            if ($.fn[name]) {
                return;
            }
            $.fn[name] = function (commandOrOptions) {
                return this.each(function () {
                    callComponent(bridge[name], this, commandOrOptions);
                });
            };
        });
    }

    window.MDJAdminUI = {
        boot: boot,
        copyLegacyBootstrapAttributes: copyLegacyBootstrapAttributes,
        initBootstrapWidgets: initBootstrapWidgets,
        installJqueryBridge: installJqueryBridge,
        ensureActiveModuleTabsVisible: ensureActiveModuleTabsVisible,
        registerModuleUI: function (name, moduleUI) {
            if (!name || !moduleUI) {
                return;
            }
            registeredModuleUIs[name] = moduleUI;
            if (window.MDJAdminLastBootRoot) {
                moduleUI.init(window.MDJAdminLastBootRoot);
            }
        },
        unregisterModuleUI: function (name) {
            if (name && registeredModuleUIs[name]) {
                delete registeredModuleUIs[name];
            }
        },
        getBootRoot: function () {
            return window.MDJAdminLastBootRoot || document;
        },
        openSearch: function (value) {
            if (window.MDJAdminSearch) {
                window.MDJAdminSearch.open(value);
            }
        },
        closeSearch: function () {
            if (window.MDJAdminSearch) {
                window.MDJAdminSearch.close();
            }
        },
        openDrawer: function (options) {
            if (window.MDJAdminDrawerHost) {
                return window.MDJAdminDrawerHost.open(options);
            }
            return false;
        },
        closeDrawer: function (owner) {
            if (window.MDJAdminDrawerHost) {
                return window.MDJAdminDrawerHost.close(owner);
            }
            return false;
        },
        openConsole: function () {
            if (window.MDJAdminConsole) {
                window.MDJAdminConsole.open();
            }
        }
    };

    installJqueryBridge();

    document.addEventListener('DOMContentLoaded', function () {
        applyThemeFromCookie();
        boot(document);
        initHeaderDateTime();
        installJqueryBridge();
        initAdminSidebarDrawer();
        scrollSidebarToActiveItem();
    });
})(window, document);

(function (window, document, $) {
    'use strict';

    var latestMessage = '';
    var latestMessageTimer = 0;
    var latestMessageInFlight = false;
    var latestMessageLoopStarted = false;
    var flashNotificationsShown = false;
    var LATEST_MESSAGE_INTERVAL_ACTIVE = 10000;
    var LATEST_MESSAGE_INTERVAL_HIDDEN = 60000;

    function safeJsonParse(data, fallback) {
        try {
            return JSON.parse(data);
        } catch (e) {
            return typeof fallback === 'undefined' ? null : fallback;
        }
    }

    function applyDbBadgeState($el, value, warnThreshold, dangerThreshold) {
        $el.removeClass('label-success label-warning label-danger').css('cursor', 'help');
        if (value <= warnThreshold) {
            $el.addClass('label-success');
        } else if (value < dangerThreshold) {
            $el.addClass('label-warning');
        } else {
            $el.addClass('label-danger');
        }
    }

    function normalizeToastType(type) {
        var normalized = (type || 'info').toString().toLowerCase();
        if (normalized === 'ok') {
            normalized = 'success';
        } else if (normalized === 'danger' || normalized === 'fail') {
            normalized = 'error';
        } else if (normalized === 'warn') {
            normalized = 'warning';
        }

        var palette = {
            success: { bgColor: '#198754', loaderBg: '#b7efcb' },
            error: { bgColor: '#dc3545', loaderBg: '#ffb9c0' },
            warning: { bgColor: '#b7791f', loaderBg: '#f4d38f' },
            info: { bgColor: '#24415c', loaderBg: '#8cc2ea' }
        };

        return palette[normalized] ? {
            name: normalized,
            bgColor: palette[normalized].bgColor,
            loaderBg: palette[normalized].loaderBg
        } : {
            name: 'info',
            bgColor: palette.info.bgColor,
            loaderBg: palette.info.loaderBg
        };
    }

    function sanitizeFlashQuery() {
        if (!window.history || typeof window.history.replaceState !== 'function' || !window.location) {
            return;
        }

        var url = new URL(window.location.href);
        var keys = ['ok_msg', 'err_msg', 'notify_msg', 'notify_type', 'notify_title'];
        var changed = false;

        keys.forEach(function (key) {
            if (url.searchParams.has(key)) {
                url.searchParams.delete(key);
                changed = true;
            }
        });

        if (changed) {
            window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : '') + url.hash);
        }
    }

    function showToast(notification) {
        if (!$ || !notification || !notification.message) {
            return false;
        }

        var type = normalizeToastType(notification.type);
        var text = notification.title
            ? notification.title + ': ' + notification.message
            : notification.message;

        $.toast({
            text: text,
            showHideTransition: 'fade',
            allowToastClose: true,
            hideAfter: notification.hideAfter || 4500,
            stack: 5,
            position: 'top-right',
            bgColor: type.bgColor,
            textColor: '#ffffff',
            textAlign: 'left',
            loader: true,
            loaderBg: type.loaderBg
        });

        return true;
    }

    function showFlashNotifications(force) {
        if (!$) {
            return;
        }

        if (flashNotificationsShown && !force) {
            return;
        }

        var config = window.MDJAdminConfig || {};
        var notifications = Array.isArray(config.flashNotifications) ? config.flashNotifications : [];

        if (notifications.length) {
            notifications.forEach(showToast);
        }

        flashNotificationsShown = true;
        config.flashNotifications = [];
        window.MDJAdminConfig = config;

        if (config.sanitizeFlashQuery) {
            sanitizeFlashQuery();
            config.sanitizeFlashQuery = false;
        }
    }

    function checkLatestMessage() {
        if (latestMessageInFlight || !$) {
            return;
        }
        latestMessageInFlight = true;
        $.ajax({
            url: window.ROOTHTML + 'getlatestnote.html',
        }).done(function (data) {
            if (data !== latestMessage) {
                latestMessage = data;
                var dataJSON = safeJsonParse(latestMessage);
                if (dataJSON && dataJSON.DATA !== '' && latestMessageTimer !== 0) {
                    $.toast({
                        text: dataJSON.DATA,
                        showHideTransition: 'fade',
                        allowToastClose: true,
                        hideAfter: 3000,
                        stack: 5,
                        position: 'bottom-left',
                        bgColor: '#444444',
                        textColor: '#eeeeee',
                        textAlign: 'left',
                        loader: true,
                        loaderBg: '#9EC600',
                    });
                }
            }
        }).always(function () {
            latestMessageInFlight = false;
            clearTimeout(latestMessageTimer);
            latestMessageTimer = setTimeout(
                checkLatestMessage,
                document.hidden ? LATEST_MESSAGE_INTERVAL_HIDDEN : LATEST_MESSAGE_INTERVAL_ACTIVE
            );
        });
    }

    function bindAdminShellHandlers() {
        if (!$) {
            return;
        }
        if (!latestMessageLoopStarted) {
            latestMessageLoopStarted = true;
            checkLatestMessage();
        }
        showFlashNotifications();
        $(document).on('click', '#btnFilterCloseSearch', function () {
            $('#filter_modules').val('');
            window.filterSearch();
        });
        $(document).on('click', '#stopLoadBtnPreloader', function () {
            $('#preloader').hide();
        });
        $(document).on('click', '#linkToggleLeftPanel', function (e) {
            e.preventDefault();
            return window.leftPanelToggle();
        });
        $(document).on('click', '#linkConsoleDebug', function (e) {
            e.preventDefault();
            return window.consoleDebugToggle();
        });
    }

    window.safeJsonParse = safeJsonParse;
    window.applyDbBadgeState = applyDbBadgeState;
    window.checkLatestMessage = checkLatestMessage;
    window.mdjShowToast = showToast;
    window.mdjShowSuccessToast = function (message, title) {
        return showToast({ message: message, title: title || '', type: 'success' });
    };
    window.mdjShowErrorToast = function (message, title) {
        return showToast({ message: message, title: title || '', type: 'error' });
    };
    window.mdjShowWarningToast = function (message, title) {
        return showToast({ message: message, title: title || '', type: 'warning' });
    };
    window.mdjShowInfoToast = function (message, title) {
        return showToast({ message: message, title: title || '', type: 'info' });
    };
    window.showFlashNotifications = showFlashNotifications;

    if (window.MDJAdminUI) {
        window.MDJAdminUI.showToast = showToast;
        window.MDJAdminUI.showToasts = showFlashNotifications;
    }

    if ($) {
        $(bindAdminShellHandlers);
    }
})(window, document, window.jQuery);
