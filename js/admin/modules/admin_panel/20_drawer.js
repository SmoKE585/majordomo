(function (window, document) {
    'use strict';

    var admin = window.MDJAdminPanel = window.MDJAdminPanel || {};

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

    admin.addBootTask('drawer-host', initAdminDrawerHost);
})(window, document);
