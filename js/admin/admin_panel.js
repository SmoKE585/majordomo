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
        var match = document.cookie.match(/(?:^|;\s*)theme=([^;]+)/);
        var theme = match ? decodeURIComponent(match[1]) : (window.MDJAdminDefaultTheme || 'light');
        var isDark = theme === 'dark';
        document.documentElement.setAttribute('data-bs-theme', isDark ? 'dark' : 'light');
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

    function boot(root) {
        copyLegacyBootstrapAttributes(root);
        normalizeLegacyClasses(root);
        initBootstrapWidgets(root);
        initAdminSidebarSections(root);
        initPersistentCollapses(root);
        initToggleTargets(root);
        initHintActions(root);
        initConfirmActions(root);
        initClassesTree(root);
        initGlobalSearchDrawer(root);
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
        openSearch: function (value) {
            if (window.MDJAdminSearch) {
                window.MDJAdminSearch.open(value);
            }
        }
    };

    installJqueryBridge();

    document.addEventListener('DOMContentLoaded', function () {
        applyThemeFromCookie();
        boot(document);
        installJqueryBridge();
        initAdminSidebarDrawer();
    });
})(window, document);

(function (window, document, $) {
    'use strict';

    var latestMessage = '';
    var latestMessageTimer = 0;
    var latestMessageInFlight = false;
    var latestMessageLoopStarted = false;
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
        $(document).on('click', '#btnFilterCloseSearch', function () {
            $('#filter_modules').val('');
            window.filterSearch();
        });
        $(document).on('click', '#btnConsoleToggleModule', function () {
            $('#currModuleName').toggle();
        });
        $(document).on('click', '#btnConsoleClose', function () {
            return window.consoleToggle();
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

    if ($) {
        $(bindAdminShellHandlers);
    }
})(window, document, window.jQuery);
