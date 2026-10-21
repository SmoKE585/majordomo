/*
 * UI Kit page-only behaviour.
 *
 * This file is intentionally NOT part of the shared admin bundle: it is
 * connected only by ?action=ui_kit. Shared Alpine components and the toast
 * store live in js/admin/modules/admin_panel/116_ui_components.js.
 */
(function (window, document) {
    'use strict';

    function fallbackCopy(text) {
        var textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', 'readonly');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
        } catch (error) {
            // Clipboard is unavailable; the button still gives feedback.
        }
        document.body.removeChild(textarea);
        return Promise.resolve();
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).catch(function () {
                return fallbackCopy(text);
            });
        }
        return fallbackCopy(text);
    }

    if (window.Alpine && typeof window.Alpine.data === 'function') {
        window.Alpine.data('mdIconBrowser', function () {
            return {
                icons: [],
                query: '',
                limit: 120,
                init: function () {
                    var source = document.getElementById('mdIconCatalog');
                    if (!source) {
                        return;
                    }
                    try {
                        this.icons = JSON.parse(source.textContent || '[]');
                    } catch (error) {
                        this.icons = [];
                    }
                },
                get matches() {
                    var query = String(this.query || '').trim().toLowerCase();
                    if (!query) {
                        return this.icons;
                    }
                    return this.icons.filter(function (icon) {
                        return icon.indexOf(query) !== -1;
                    });
                },
                get visible() {
                    return this.matches.slice(0, this.limit);
                },
                get matchCount() {
                    return this.matches.length;
                },
                get total() {
                    return this.icons.length;
                },
                more: function () {
                    this.limit += 120;
                },
                pick: function (icon) {
                    var className = 'ti ti-' + icon;
                    copyText(className).then(function () {
                        if (window.MDJUIToast) {
                            window.MDJUIToast.show({
                                type: 'success',
                                title: 'Класс скопирован',
                                text: className
                            });
                        }
                    });
                }
            };
        });
    }

    function initCopyButtons(root) {
        root.querySelectorAll('.md-ui-demo__code pre').forEach(function (pre) {
            if (pre.dataset.mdCopyReady === '1') {
                return;
            }
            pre.dataset.mdCopyReady = '1';
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'md-ui-code-copy';
            button.innerHTML = '<i class="ti ti-clipboard" aria-hidden="true"></i><span>Копировать</span>';
            button.addEventListener('click', function () {
                var code = pre.querySelector('code');
                copyText(code ? code.textContent : pre.textContent).then(function () {
                    var label = button.querySelector('span');
                    if (label) {
                        label.textContent = 'Скопировано';
                    }
                    window.setTimeout(function () {
                        if (label) {
                            label.textContent = 'Копировать';
                        }
                    }, 1600);
                });
            });
            pre.appendChild(button);
        });
    }

    function initScrollSpy(root) {
        var links = Array.prototype.slice.call(root.querySelectorAll('[data-md-doc-link]'));
        var sections = Array.prototype.slice.call(root.querySelectorAll('[data-md-doc-section]'));
        if (!links.length || !sections.length || !('IntersectionObserver' in window)) {
            return;
        }
        var byId = {};
        links.forEach(function (link) {
            byId[link.getAttribute('href').replace('#', '')] = link;
        });
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }
                links.forEach(function (link) {
                    link.classList.remove('is-active');
                    link.removeAttribute('aria-current');
                });
                var link = byId[entry.target.id];
                if (link) {
                    link.classList.add('is-active');
                    link.setAttribute('aria-current', 'true');
                }
            });
        }, { rootMargin: '-18% 0px -72% 0px', threshold: 0 });
        sections.forEach(function (section) {
            observer.observe(section);
        });
    }

    function initLinkedObjectDemoLog() {
        if (document.body.dataset.mdLinkedObjectDemoLog === '1') {
            return;
        }
        document.body.dataset.mdLinkedObjectDemoLog = '1';
        document.addEventListener('change', function (event) {
            var target = event.target;
            if (!target || !target.matches || !target.matches('[data-md-linkedobject-demo-source]')) {
                return;
            }
            var output = document.querySelector('[data-md-linkedobject-demo-log-text]');
            if (!output) {
                return;
            }
            var role = target.getAttribute('data-md-linkedobject-demo-source') || 'field';
            var time = new Intl.DateTimeFormat('ru-RU', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }).format(new Date());
            output.textContent = role + ' = "' + target.value + '" · ' + time;
        });
    }

    function initCommandPalette(root) {
        var modal = root.querySelector('#mdCommandPalette');
        if (!modal || modal.dataset.mdPaletteReady === '1') {
            return;
        }
        modal.dataset.mdPaletteReady = '1';
        modal.addEventListener('shown.bs.modal', function () {
            var input = modal.querySelector('[data-md-command-input]');
            if (!input) {
                return;
            }
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();
        });
    }

    function initUIKit() {
        var page = document.querySelector('[data-md-ui-kit]');
        if (!page) {
            return;
        }
        initCopyButtons(page);
        initScrollSpy(page);
        initCommandPalette(page);
        initLinkedObjectDemoLog();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUIKit, { once: true });
    } else {
        initUIKit();
    }
})(window, document);
