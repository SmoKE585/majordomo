(function (window, document) {
    'use strict';

    var admin = window.MDJAdminPanel = window.MDJAdminPanel || {};
    var state = admin.state = admin.state || {};

    var legacyAttributeMap = {
        toggle: 'bs-toggle',
        target: 'bs-target',
        dismiss: 'bs-dismiss',
        parent: 'bs-parent',
        backdrop: 'bs-backdrop',
        keyboard: 'bs-keyboard',
        placement: 'bs-placement',
        trigger: 'bs-trigger',
        container: 'bs-container',
        html: 'bs-html',
        content: 'bs-content',
        title: 'bs-title'
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

        function setHoverGroup(card, currentState) {
            if (!card) {
                return;
            }
            card.classList.toggle('is-hover-group', currentState);

            var parentId = card.getAttribute('data-md-class-parent-id');
            if (parentId && parentId !== '0' && cardsById[parentId]) {
                cardsById[parentId].classList.toggle('is-hover-group', currentState);
            }

            var ownId = card.getAttribute('data-md-class-id');
            root.querySelectorAll('[data-md-class-parent-id="' + ownId + '"]').forEach(function (child) {
                child.classList.toggle('is-hover-group', currentState);
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

    state.headerClockTimer = state.headerClockTimer || 0;

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
        if (state.headerClockTimer) {
            return;
        }
        state.headerClockTimer = window.setInterval(updateHeaderDateTime, 1000);
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

    function initRegisteredModuleUIs(root) {
        Object.keys(state.moduleUIs).forEach(function (name) {
            var moduleUI = state.moduleUIs[name];
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
            if (button.dataset.mdSidebarDrawerBound === '1') {
                return;
            }
            button.dataset.mdSidebarDrawerBound = '1';
            button.addEventListener('click', function () {
                if (body.classList.contains('md-admin-sidebar-open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        });

        closeTargets.forEach(function (target) {
            if (target.dataset.mdSidebarDrawerBound === '1') {
                return;
            }
            target.dataset.mdSidebarDrawerBound = '1';
            target.addEventListener('click', closeSidebar);
        });

        if (!document.body.dataset.mdSidebarDrawerEscapeBound) {
            document.body.dataset.mdSidebarDrawerEscapeBound = '1';
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeSidebar();
                }
            });
        }

        document.querySelectorAll('.md-admin-sidebar__link').forEach(function (link) {
            if (link.dataset.mdSidebarDrawerBound === '1') {
                return;
            }
            link.dataset.mdSidebarDrawerBound = '1';
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

    function initSidebarNotificationBadges(scope) {
        scope.querySelectorAll('.md-admin-sidebar__badge[data-md-module]').forEach(function (badge) {
            if (badge.dataset.mdNotyBound === '1') return;
            badge.dataset.mdNotyBound = '1';

            badge.style.cursor = 'pointer';

            badge.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                var raw = badge.getAttribute('data-md-notifications');
                if (!raw) return;

                var notifications;
                try {
                    notifications = JSON.parse(raw);
                } catch (e) {
                    return;
                }
                if (!Array.isArray(notifications) || notifications.length === 0) return;

                var html = '<div class="md-admin-noty-list">';
                for (var i = 0; i < notifications.length; i++) {
                    var noty = notifications[i];
                    html +=
                        '<div class="md-admin-noty-item">' +
                        '<div class="md-admin-noty-item__msg">' + admin.escapeHtml(noty.msg) + '</div>' +
                        '<div class="md-admin-noty-item__meta">' + admin.escapeHtml(noty.time) + '</div>' +
                        '</div>';
                }
                html += '</div>';

                var popoverInstance = window.bootstrap && window.bootstrap.Popover.getInstance(badge);
                if (popoverInstance) {
                    popoverInstance.setContent({ '.popover-body': html });
                } else if (window.bootstrap) {
                    popoverInstance = new window.bootstrap.Popover(badge, {
                        html: true,
                        content: html,
                        placement: badge.getAttribute('data-bs-placement') || 'right',
                        container: 'body',
                        trigger: 'manual',
                        customClass: 'md-admin-noty-popover'
                    });
                }
                if (popoverInstance) {
                    popoverInstance.show();
                }

                clearTimeout(badge._mdNotyHideTimer);
                badge._mdNotyHideTimer = setTimeout(function () {
                    if (popoverInstance) popoverInstance.hide();
                }, 8000);

                var moduleName = badge.getAttribute('data-md-module');
                var xhr = new XMLHttpRequest();
                xhr.open('GET', '?ajax_panel=1&op=dismiss_module_notifications&module_name=' + encodeURIComponent(moduleName));
                xhr.onload = function () {
                    if (xhr.responseText.trim() === 'OK') {
                        badge.style.transition = 'opacity .25s ease, transform .25s ease';
                        badge.style.opacity = '0';
                        badge.style.transform = 'scale(0.7)';
                        setTimeout(function () {
                            if (badge.parentNode) badge.remove();
                        }, 280);
                    }
                };
                xhr.onerror = function () {
                    if (popoverInstance) popoverInstance.hide();
                };
                xhr.send();
            });
        });
    }

    function initNotificationPopoverDismiss() {
        if (document.body.dataset.mdNotyDismissBound === '1') return;
        document.body.dataset.mdNotyDismissBound = '1';

        document.addEventListener('click', function (event) {
            var clickedOnBadge = event.target.closest('.md-admin-sidebar__badge[data-md-module]');
            var clickedInPopover = event.target.closest('.md-admin-noty-popover');
            if (!clickedOnBadge && !clickedInPopover) {
                document.querySelectorAll('.md-admin-sidebar__badge[data-md-module]').forEach(function (badge) {
                    if (window.bootstrap) {
                        var instance = window.bootstrap.Popover.getInstance(badge);
                        if (instance) instance.hide();
                    }
                });
            }
        });
    }

    function boot(root) {
        var scope = root && root.querySelectorAll ? root : document;
        admin.setBootRoot(scope);
        window.MDJAdminLastBootRoot = scope;

        copyLegacyBootstrapAttributes(scope);
        normalizeLegacyClasses(scope);
        initThemeSwitcher(scope);
        initCheckboxToggles(scope);
        initBootstrapWidgets(scope);
        initSidebarNotificationBadges(scope);
        initNotificationPopoverDismiss();
        initAdminSidebarSections(scope);
        initPersistentCollapses(scope);
        initToggleTargets(scope);
        initHintActions(scope);
        initConfirmActions(scope);
        initClassesTree(scope);

        admin.getBootTasks().forEach(function (task) {
            task(scope);
        });

        initRegisteredModuleUIs(scope);
        ensureActiveModuleTabsVisible(scope);
    }

    admin.copyLegacyBootstrapAttributes = copyLegacyBootstrapAttributes;
    admin.normalizeLegacyClasses = normalizeLegacyClasses;
    admin.initBootstrapWidgets = initBootstrapWidgets;
    admin.applyThemeFromCookie = applyThemeFromCookie;
    admin.getThemeValue = getThemeValue;
    admin.syncThemeControls = syncThemeControls;
    admin.initThemeSwitcher = initThemeSwitcher;
    admin.initCheckboxToggles = initCheckboxToggles;
    admin.getCookie = getCookie;
    admin.setCookie = setCookie;
    admin.deleteCookie = deleteCookie;
    admin.setCollapseState = setCollapseState;
    admin.initAdminSidebarSections = initAdminSidebarSections;
    admin.initPersistentCollapses = initPersistentCollapses;
    admin.initToggleTargets = initToggleTargets;
    admin.initHintActions = initHintActions;
    admin.initConfirmActions = initConfirmActions;
    admin.initClassesTree = initClassesTree;
    admin.escapeHtml = escapeHtml;
    admin.initSidebarNotificationBadges = initSidebarNotificationBadges;
    admin.updateHeaderDateTime = updateHeaderDateTime;
    admin.initHeaderDateTime = initHeaderDateTime;
    admin.ensureActiveModuleTabsVisible = ensureActiveModuleTabsVisible;
    admin.initRegisteredModuleUIs = initRegisteredModuleUIs;
    admin.initAdminSidebarDrawer = initAdminSidebarDrawer;
    admin.scrollSidebarToActiveItem = scrollSidebarToActiveItem;
    admin.installJqueryBridge = installJqueryBridge;
    admin.boot = boot;

    installJqueryBridge();
})(window, document);
