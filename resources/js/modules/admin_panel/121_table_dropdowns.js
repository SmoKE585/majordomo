/*
 * Table action dropdowns.
 *
 * Tables live inside `.md-table__scroll`, which scrolls horizontally. A
 * Bootstrap dropdown menu is absolutely positioned inside that scroll
 * container, so it gets clipped by the table edge. Port the open menu to
 * <body> and position it explicitly, then move it back on close so Bootstrap
 * can still find it as a sibling of the toggle on the next open.
 */
(function (window, document) {
    'use strict';

    var marker = 'data-md-table-dropdown-portal';

    function menuFor(toggle) {
        return toggle && toggle._mdTableMenu ? toggle._mdTableMenu : null;
    }

    function positionMenu(toggle, menu) {
        if (!toggle || !menu) {
            return;
        }

        var rect = toggle.getBoundingClientRect();
        var margin = 8;
        var gap = 4;

        menu.style.setProperty('position', 'fixed', 'important');
        menu.style.setProperty('inset', 'auto', 'important');
        menu.style.setProperty('transform', 'none', 'important');
        menu.style.setProperty('z-index', '1080', 'important');
        menu.style.maxWidth = 'calc(100vw - 16px)';

        var menuWidth = menu.offsetWidth || 206;
        var menuHeight = menu.offsetHeight || 320;
        var spaceBelow = window.innerHeight - rect.bottom - margin;
        var spaceAbove = rect.top - margin;
        var openUp = spaceBelow < Math.min(menuHeight, 320) && spaceAbove > spaceBelow;

        menu.style.maxHeight = Math.max(120, Math.floor(openUp ? spaceAbove : spaceBelow)) + 'px';
        menu.style.overflowY = 'auto';

        if (openUp) {
            menu.style.setProperty('top', 'auto', 'important');
            menu.style.setProperty('bottom', Math.round(window.innerHeight - rect.top + gap) + 'px', 'important');
        } else {
            menu.style.setProperty('bottom', 'auto', 'important');
            menu.style.setProperty('top', Math.round(rect.bottom + gap) + 'px', 'important');
        }

        if (rect.right - menuWidth < margin) {
            menu.style.setProperty('right', 'auto', 'important');
            menu.style.setProperty('left', Math.max(margin, Math.min(rect.left, window.innerWidth - menuWidth - margin)) + 'px', 'important');
        } else {
            menu.style.setProperty('left', 'auto', 'important');
            menu.style.setProperty('right', Math.max(margin, window.innerWidth - rect.right) + 'px', 'important');
        }
    }

    function schedulePosition(toggle, menu) {
        positionMenu(toggle, menu);
        window.clearTimeout(menu._mdPositionTimer);
        menu._mdPositionTimer = window.setTimeout(function () {
            positionMenu(toggle, menu);
        }, 60);
        window.clearTimeout(menu._mdPositionTimer2);
        menu._mdPositionTimer2 = window.setTimeout(function () {
            positionMenu(toggle, menu);
        }, 260);
    }

    function openMenu(toggle) {
        if (!toggle) {
            return;
        }
        var home = toggle.parentNode;
        var menu = home ? home.querySelector('.dropdown-menu') : null;
        if (!menu) {
            return;
        }

        menu._mdTableHome = home;
        menu._mdTableToggle = toggle;
        toggle._mdTableMenu = menu;
        menu.setAttribute(marker, '1');
        if (menu.parentNode !== document.body) {
            document.body.appendChild(menu);
        }
        schedulePosition(toggle, menu);
    }

    function closeMenu(toggle) {
        var menu = menuFor(toggle);
        if (!menu) {
            return;
        }

        window.clearTimeout(menu._mdPositionTimer);
        window.clearTimeout(menu._mdPositionTimer2);
        menu._mdPositionTimer = null;
        menu._mdPositionTimer2 = null;

        ['position', 'inset', 'top', 'left', 'right', 'bottom', 'max-height', 'max-width', 'overflow-y', 'z-index', 'transform'].forEach(function (property) {
            menu.style.removeProperty(property);
        });
        menu.removeAttribute(marker);
        if (menu._mdTableHome && menu._mdTableHome.isConnected) {
            menu._mdTableHome.appendChild(menu);
        } else if (menu.parentNode === document.body) {
            menu.remove();
        }
        menu._mdTableHome = null;
        menu._mdTableToggle = null;
        toggle._mdTableMenu = null;
    }

    function repositionOpenMenus() {
        document.querySelectorAll('body > .dropdown-menu[' + marker + ']').forEach(function (menu) {
            var toggle = menu._mdTableToggle;
            if (!toggle || !toggle.isConnected) {
                if (menu.parentNode === document.body) {
                    menu.remove();
                }
                return;
            }
            var rect = toggle.getBoundingClientRect();
            if (rect.bottom < 0 || rect.top > window.innerHeight) {
                if (window.bootstrap && window.bootstrap.Dropdown) {
                    var instance = window.bootstrap.Dropdown.getInstance(toggle);
                    if (instance) {
                        instance.hide();
                    }
                }
                return;
            }
            positionMenu(toggle, menu);
        });
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        if (document.documentElement.dataset.mdTableDropdowns !== '1') {
            document.documentElement.dataset.mdTableDropdowns = '1';

            document.addEventListener('shown.bs.dropdown', function (event) {
                var toggle = event.target;
                if (!toggle || !toggle.closest || !toggle.closest('.md-table__scroll')) {
                    return;
                }
                openMenu(toggle);
            });

            document.addEventListener('hidden.bs.dropdown', function (event) {
                var toggle = event.target;
                if (!toggle || !toggle.closest || !toggle.closest('.md-table__scroll')) {
                    return;
                }
                closeMenu(toggle);
            });

            window.addEventListener('scroll', repositionOpenMenus, true);
            window.addEventListener('resize', repositionOpenMenus);
        }

        // Dropdowns that were open before a partLoad are no longer needed.
        document.querySelectorAll('body > .dropdown-menu[' + marker + ']').forEach(function (menu) {
            var toggle = menu._mdTableToggle;
            if (!toggle || !scope.contains(toggle)) {
                menu.remove();
            }
        });
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('table-dropdowns', { init: init });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window, document);

