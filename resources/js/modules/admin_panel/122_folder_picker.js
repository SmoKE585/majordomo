/*
 * Folder picker dialog for path settings.
 *
 * Markup:
 *   <button data-md-folder-picker="value_BACKUP_PATH"
 *           data-md-folder-picker-modal="mdSettingsFolderPicker">Выбрать</button>
 *   <?= MDJUI::folderPicker([...]) ?>
 *
 * The endpoint is read-only and must return the JSON shape produced by
 * MajorDoMo\Core\Filesystem\FolderBrowser::listing(). The component never
 * receives a full filesystem listing: navigation is limited by the server.
 */
(function (window, document) {
    'use strict';

    var instances = new WeakMap();

    function pluralFolders(number) {
        var value = Math.abs(Number(number) || 0);
        var mod10 = value % 10;
        var mod100 = value % 100;
        if (mod10 === 1 && mod100 !== 11) {
            return 'папка';
        }
        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
            return 'папки';
        }
        return 'папок';
    }

    function icon(name) {
        var node = document.createElement('i');
        node.className = 'ti ti-' + name;
        node.setAttribute('aria-hidden', 'true');
        return node;
    }

    function FolderPicker(modal) {
        this.modal = modal;
        this.endpoint = modal.getAttribute('data-md-folder-endpoint') || '';
        this.list = modal.querySelector('[data-md-folder-list]');
        this.breadcrumbs = modal.querySelector('[data-md-folder-breadcrumbs]');
        this.search = modal.querySelector('[data-md-folder-search]');
        this.count = modal.querySelector('[data-md-folder-count]');
        this.empty = modal.querySelector('[data-md-folder-empty]');
        this.error = modal.querySelector('[data-md-folder-error]');
        this.notice = modal.querySelector('[data-md-folder-notice]');
        this.current = modal.querySelector('[data-md-folder-current]');
        this.up = modal.querySelector('[data-md-folder-up]');
        this.choose = modal.querySelector('[data-md-folder-choose]');
        this.target = null;
        this.startPath = '';
        this.controller = null;
        this.state = {
            path: '',
            absolute: '',
            parent: null,
            rootName: '',
            dirs: [],
            truncated: false,
            loading: false
        };
        this.bind();
    }

    FolderPicker.prototype.bind = function () {
        var self = this;

        this.up.addEventListener('click', function () {
            if (self.state.parent !== null && !self.state.loading) {
                self.load(self.state.parent);
            }
        });

        this.search.addEventListener('input', function () {
            self.renderList();
        });

        this.list.addEventListener('click', function (event) {
            var row = event.target.closest('[data-md-folder-path]');
            if (!row || row.disabled || self.state.loading) {
                return;
            }
            self.load(row.getAttribute('data-md-folder-path'));
        });

        this.breadcrumbs.addEventListener('click', function (event) {
            var crumb = event.target.closest('[data-md-folder-path]');
            if (!crumb || self.state.loading) {
                return;
            }
            self.load(crumb.getAttribute('data-md-folder-path'));
        });

        this.choose.addEventListener('click', function () {
            if (self.state.loading || !self.state.absolute) {
                return;
            }
            var target = self.target;
            if (target) {
                target.value = self.state.absolute;
                target.dispatchEvent(new Event('input', {bubbles: true}));
                target.dispatchEvent(new Event('change', {bubbles: true}));
                target.focus();
            }
            self.close();
        });

        this.modal.addEventListener('shown.bs.modal', function () {
            self.search.value = '';
            self.load(self.startPath);
            self.search.focus();
        });

        this.modal.addEventListener('hidden.bs.modal', function () {
            self.abort();
        });
    };

    FolderPicker.prototype.openFor = function (target) {
        this.target = target;
        this.startPath = target && typeof target.value === 'string' ? target.value.trim() : '';
        this.showError('');
        this.showNotice('');
        this.search.value = '';
        var modal = window.bootstrap && window.bootstrap.Modal
            ? window.bootstrap.Modal.getOrCreateInstance(this.modal, {backdrop: 'static'})
            : null;
        if (modal) {
            if (this.modal.classList.contains('show')) {
                this.search.value = '';
                this.load(this.startPath);
                this.search.focus();
            } else {
                modal.show();
            }
        } else {
            this.modal.classList.add('show');
            this.modal.style.display = 'block';
            this.modal.removeAttribute('aria-hidden');
            this.load(this.startPath);
        }
    };

    FolderPicker.prototype.close = function () {
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(this.modal).hide();
            return;
        }
        this.modal.classList.remove('show');
        this.modal.style.display = 'none';
        this.modal.setAttribute('aria-hidden', 'true');
    };

    FolderPicker.prototype.abort = function () {
        if (this.controller) {
            this.controller.abort();
            this.controller = null;
        }
    };

    FolderPicker.prototype.load = function (path) {
        var self = this;
        if (!this.endpoint) {
            this.showError('Не настроен адрес списка папок.');
            return;
        }

        this.abort();
        this.state.loading = true;
        this.setBusy(true);
        this.showError('');
        this.showNotice('');

        var url;
        try {
            url = new URL(this.endpoint, window.location.href);
        } catch (error) {
            this.state.loading = false;
            this.setBusy(false);
            this.showError('Некорректный адрес списка папок.');
            return;
        }
        url.searchParams.set('path', path || '');

        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        this.controller = controller;

        fetch(url.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (self.controller !== controller && controller) {
                return;
            }
            if (!data || !data.ok) {
                throw new Error(data && data.error ? data.error : 'Не удалось прочитать папку.');
            }
            self.state.path = String(data.path || '');
            self.state.absolute = String(data.absolute || '');
            self.state.parent = data.parent === null || typeof data.parent === 'undefined' ? null : String(data.parent);
            self.state.rootName = data.root && data.root.name ? String(data.root.name) : '';
            self.state.dirs = Array.isArray(data.dirs) ? data.dirs : [];
            self.state.truncated = !!data.truncated;
            self.state.loading = false;
            self.setBusy(false);
            self.render(data);
            if (data.fallback) {
                self.showNotice('Путь вне проекта или не существует. Открыт корень проекта.');
            }
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            self.state.loading = false;
            self.setBusy(false);
            self.showError(error && error.message ? error.message : 'Не удалось прочитать папку.');
        });
    };

    FolderPicker.prototype.setBusy = function (busy) {
        this.list.setAttribute('aria-busy', busy ? 'true' : 'false');
        this.modal.classList.toggle('is-loading', busy);
        this.choose.disabled = busy || !this.state.absolute;
        this.up.disabled = busy || this.state.parent === null;
    };

    FolderPicker.prototype.render = function (data) {
        this.renderBreadcrumbs();
        this.renderList();
        this.current.textContent = this.state.absolute;
        this.current.title = this.state.absolute;
        this.up.disabled = this.state.loading || this.state.parent === null;
        this.choose.disabled = this.state.loading || !this.state.absolute;
        if (this.notice.hidden && data && data.writable === false) {
            this.showNotice('В эту папку нельзя записывать: проверьте права доступа.');
        }
    };

    FolderPicker.prototype.renderBreadcrumbs = function () {
        var root = 'Корень проекта';
        var rootTitle = this.state.rootName || root;
        var fragment = document.createDocumentFragment();

        function add(path, label, current, title) {
            var node = current ? document.createElement('span') : document.createElement('button');
            node.className = 'md-folder-picker__crumb';
            if (!current) {
                node.type = 'button';
                node.setAttribute('data-md-folder-path', path);
            } else {
                node.setAttribute('aria-current', 'page');
            }
            if (label === '') {
                node.appendChild(icon('home'));
            } else {
                node.textContent = label;
            }
            if (title) {
                node.title = title;
            }
            fragment.appendChild(node);
        }

        add('', root, this.state.path === '', rootTitle);
        var parts = this.state.path ? this.state.path.split('/') : [];
        var accumulated = [];
        parts.forEach(function (part, index) {
            accumulated.push(part);
            var separator = document.createElement('span');
            separator.className = 'md-folder-picker__separator';
            separator.setAttribute('aria-hidden', 'true');
            separator.textContent = '/';
            fragment.appendChild(separator);
            add(accumulated.join('/'), part, index === parts.length - 1, accumulated.join('/'));
        });

        this.breadcrumbs.replaceChildren(fragment);
    };

    FolderPicker.prototype.matchesFilter = function (dir) {
        var query = this.search.value.trim().toLowerCase();
        if (query === '') {
            return true;
        }
        return String(dir.name || '').toLowerCase().indexOf(query) !== -1;
    };

    FolderPicker.prototype.renderList = function () {
        var self = this;
        var visible = 0;
        var fragment = document.createDocumentFragment();

        this.state.dirs.forEach(function (dir) {
            if (!self.matchesFilter(dir)) {
                return;
            }
            visible++;

            var row = document.createElement('button');
            row.type = 'button';
            row.className = 'md-folder-picker__item';
            row.setAttribute('role', 'option');
            row.setAttribute('data-md-folder-path', dir.path);
            row.setAttribute('data-md-folder-name', dir.name);
            if (dir.readable === false) {
                row.disabled = true;
                row.title = 'Нет доступа на чтение';
            }

            var folderIcon = document.createElement('span');
            folderIcon.className = 'md-folder-picker__item-icon';
            folderIcon.appendChild(icon(dir.readable === false ? 'folder-off' : 'folder'));

            var name = document.createElement('span');
            name.className = 'md-folder-picker__item-name';
            name.textContent = dir.name;

            var arrow = icon('chevron-right');
            arrow.classList.add('md-folder-picker__item-arrow');

            row.appendChild(folderIcon);
            row.appendChild(name);
            row.appendChild(arrow);
            fragment.appendChild(row);
        });

        this.list.replaceChildren(fragment);
        this.list.hidden = visible === 0;
        this.empty.hidden = visible !== 0;

        if (this.count) {
            var label = visible + ' ' + pluralFolders(visible);
            if (this.state.truncated) {
                label += ' · показаны не все';
            }
            this.count.textContent = label;
        }

        if (visible === 0 && this.empty) {
            var title = this.empty.querySelector('strong');
            var text = this.empty.querySelector('span');
            var filtered = this.state.dirs.length > 0 && this.search.value.trim() !== '';
            if (title) {
                title.textContent = filtered ? 'Ничего не найдено' : 'Здесь нет вложенных папок';
            }
            if (text) {
                text.textContent = filtered
                    ? 'Измените фильтр или очистите его.'
                    : 'Можно выбрать текущую папку или подняться выше.';
            }
        }
    };

    FolderPicker.prototype.showError = function (message) {
        if (!this.error) {
            return;
        }
        this.error.textContent = message || '';
        this.error.hidden = message === '';
    };

    FolderPicker.prototype.showNotice = function (message) {
        if (!this.notice) {
            return;
        }
        this.notice.textContent = message || '';
        this.notice.hidden = message === '';
    };

    function modalFor(trigger) {
        var id = trigger.getAttribute('data-md-folder-picker-modal');
        if (id) {
            return document.getElementById(id);
        }
        return document.querySelector('[data-md-folder-picker-dialog]');
    }

    function instanceFor(modal) {
        if (!modal) {
            return null;
        }
        var instance = instances.get(modal);
        if (!instance) {
            instance = new FolderPicker(modal);
            instances.set(modal, instance);
        }
        return instance;
    }

    function open(targetId) {
        var target = document.getElementById(targetId);
        if (!target) {
            return false;
        }
        var modal = document.querySelector('[data-md-folder-picker-dialog]');
        var picker = instanceFor(modal);
        if (!picker) {
            return false;
        }
        picker.openFor(target);
        return true;
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('[data-md-folder-picker-dialog]').forEach(function (modal) {
            instanceFor(modal);
        });
        scope.querySelectorAll('[data-md-folder-picker]').forEach(function (button) {
            if (button.dataset.mdFolderPickerReady === '1') {
                return;
            }
            button.dataset.mdFolderPickerReady = '1';
            button.addEventListener('click', function (event) {
                event.preventDefault();
                var target = document.getElementById(button.getAttribute('data-md-folder-picker'));
                var picker = instanceFor(modalFor(button));
                if (!target || !picker) {
                    return;
                }
                picker.openFor(target);
            });
        });
    }

    window.MDFolderPicker = {
        init: init,
        open: open
    };

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('folderPicker', {init: init});
    }

    document.addEventListener('DOMContentLoaded', function () {
        init(document);
    });
})(window, document);
