/*
 * Reusable Alpine components and the toast store for the admin design system.
 *
 * Loaded in the shared admin bundle. Page-specific UI Kit behaviour lives in
 * js/admin/ui_kit.js and is connected only on the UI Kit page.
 * 2026-10-22 — единый toast-store, максимум три сообщения, адаптер $.toast.
 */
import Alpine from 'alpinejs';

(function (window, document) {
    'use strict';

    var toastSeq = 0;
    var toastTimers = new Map();
    var toastIcons = {
        info: 'info-circle',
        success: 'circle-check',
        warning: 'alert-triangle',
        danger: 'circle-x'
    };

    window.MDJUIToast = {
        show: function (options) {
            var store = Alpine.store ? Alpine.store('toasts') : null;
            if (store) {
                return store.push(options || {});
            }
        },
        dismiss: function (id) {
            var store = Alpine.store('toasts');
            if (!store) return;
            if (id == null) store.items.slice().forEach(function (item) { store.remove(item.id); });
            else store.remove(id);
        }
    };

    function registerAlpineComponents() {
        Alpine.store('toasts', {
            items: [],
            push: function (toast) {
                toast = toast || {};
                var id = ++toastSeq;
                var type = toast.type || 'info';
                type = {error: 'danger', fail: 'danger', ok: 'success', warn: 'warning'}[type] || type;
                if (!toastIcons[type]) type = 'info';
                var duration = Number.isFinite(toast.duration) && toast.duration >= 0 ? toast.duration : 4200;
                while (this.items.length >= 3) this.remove(this.items[0].id);
                this.items.push({
                    id: id,
                    type: type,
                    icon: toast.icon || toastIcons[type] || 'info-circle',
                    title: toast.title || {info: 'Уведомление', success: 'Готово', warning: 'Внимание', danger: 'Ошибка'}[type],
                    text: toast.text == null ? '' : String(toast.text),
                    duration: duration
                });
                var self = this;
                if (duration > 0) toastTimers.set(id, window.setTimeout(function () {
                    self.remove(id);
                }, duration));
                return id;
            },
            remove: function (id) {
                window.clearTimeout(toastTimers.get(id));
                toastTimers.delete(id);
                this.items = this.items.filter(function (item) {
                    return item.id !== id;
                });
            }
        });

        Alpine.data('mdLight', function (config) {
            config = config || {};
            return {
                on: config.on !== false,
                brightness: typeof config.brightness === 'number' ? config.brightness : 72,
                temp: typeof config.temp === 'number' ? config.temp : 2700
            };
        });

        Alpine.data('mdScene', function (config) {
            config = config || {};
            return {
                enabled: config.enabled !== false,
                running: false,
                run: function () {
                    if (!this.enabled || this.running) {
                        return;
                    }
                    this.running = true;
                    var name = this.$el.getAttribute('data-scene-name') || 'Сцена';
                    window.MDJUIToast.show({
                        type: 'success',
                        title: 'Сцена запущена',
                        text: name + ' · команды отправлены устройствам'
                    });
                    var self = this;
                    window.setTimeout(function () {
                        self.running = false;
                    }, 1000);
                }
            };
        });

        Alpine.data('mdFileUpload', function () {
            return {
                files: [],
                dragging: false,
                addFiles: function (fileList) {
                    var list = Array.prototype.slice.call(fileList || []);
                    var self = this;
                    list.forEach(function (file) {
                        self.files.push({
                            name: file.name,
                            size: file.size,
                            type: file.type || 'file'
                        });
                    });
                },
                remove: function (index) {
                    this.files.splice(index, 1);
                },
                formatSize: function (bytes) {
                    if (bytes < 1024) {
                        return bytes + ' Б';
                    }
                    if (bytes < 1024 * 1024) {
                        return (bytes / 1024).toFixed(1).replace('.', ',') + ' КБ';
                    }
                    return (bytes / (1024 * 1024)).toFixed(1).replace('.', ',') + ' МБ';
                }
            };
        });

        Alpine.data('mdColorPicker', function (initial) {
            var start = initial || '#2f7fbd';
            return {
                color: start,
                hex: start,
                applyHex: function () {
                    var value = String(this.hex || '').trim();
                    if (/^#[0-9a-fA-F]{6}$/.test(value)) {
                        this.color = value.toLowerCase();
                        this.hex = value.toLowerCase();
                    } else {
                        this.hex = this.color;
                    }
                }
            };
        });

        Alpine.data('mdCommandPalette', function () {
            return {
                items: [],
                query: '',
                activeIndex: 0,
                init: function () {
                    var source = this.$el.getAttribute('data-md-palette');
                    if (!source) {
                        return;
                    }
                    try {
                        this.items = JSON.parse(source);
                    } catch (error) {
                        this.items = [];
                    }
                },
                get groups() {
                    var query = String(this.query || '').trim().toLowerCase();
                    var groups = [];
                    var index = 0;
                    this.items.forEach(function (item) {
                        var haystack = String(item.label + ' ' + item.hint + ' ' + item.group).toLowerCase();
                        if (query && haystack.indexOf(query) === -1) {
                            return;
                        }
                        var group = null;
                        for (var i = 0; i < groups.length; i += 1) {
                            if (groups[i].name === item.group) {
                                group = groups[i];
                                break;
                            }
                        }
                        if (!group) {
                            group = { name: item.group, items: [] };
                            groups.push(group);
                        }
                        group.items.push({
                            label: item.label,
                            hint: item.hint,
                            icon: item.icon,
                            href: item.href,
                            kbd: item.kbd,
                            index: index
                        });
                        index += 1;
                    });
                    return groups;
                },
                get flatItems() {
                    return this.groups.reduce(function (all, group) {
                        return all.concat(group.items);
                    }, []);
                },
                move: function (delta) {
                    var items = this.flatItems;
                    if (!items.length) {
                        return;
                    }
                    this.activeIndex = (this.activeIndex + delta + items.length) % items.length;
                },
                setActive: function (index) {
                    this.activeIndex = index;
                },
                runActive: function () {
                    var item = this.flatItems[this.activeIndex];
                    if (item) {
                        this.run(item);
                    }
                },
                run: function (item) {
                    if (!item) {
                        return;
                    }
                    if (item.href) {
                        window.location.href = item.href;
                        return;
                    }
                    window.MDJUIToast.show({
                        type: 'success',
                        title: 'Команда выполнена',
                        text: item.label
                    });
                    var modal = window.bootstrap && window.bootstrap.Modal
                        ? window.bootstrap.Modal.getInstance(this.$root)
                        : null;
                    if (modal) {
                        modal.hide();
                    }
                }
            };
        });

        Alpine.data('mdContextMenu', function () {
            return {
                open: false,
                x: 0,
                y: 0,
                show: function (event) {
                    var rect = this.$el ? this.$el.getBoundingClientRect() : { left: 20, top: 20 };
                    var pointX = typeof event.clientX === 'number' && event.clientX > 0 ? event.clientX : rect.left + 20;
                    var pointY = typeof event.clientY === 'number' && event.clientY > 0 ? event.clientY : rect.top + 20;
                    this.open = true;
                    this.x = pointX;
                    this.y = pointY;
                    var self = this;
                    this.$nextTick(function () {
                        var menu = self.$refs.menu;
                        if (!menu) {
                            return;
                        }
                        var rect = menu.getBoundingClientRect();
                        self.x = Math.max(8, Math.min(self.x, window.innerWidth - rect.width - 8));
                        self.y = Math.max(8, Math.min(self.y, window.innerHeight - rect.height - 8));
                    });
                },
                hide: function () {
                    this.open = false;
                },
                choose: function (label) {
                    this.hide();
                    window.MDJUIToast.show({
                        type: 'success',
                        title: 'Действие выполнено',
                        text: label
                    });
                }
            };
        });

        Alpine.data('mdValidationForm', function () {
            return {
                name: '',
                email: '',
                submitted: false,
                get nameError() {
                    return this.submitted && !String(this.name || '').trim() ? 'Укажите название устройства.' : '';
                },
                get emailError() {
                    var value = String(this.email || '').trim();
                    if (!this.submitted) {
                        return '';
                    }
                    if (!value) {
                        return 'Укажите e-mail для уведомлений.';
                    }
                    return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value) ? '' : 'Проверьте формат e-mail.';
                },
                submit: function () {
                    this.submitted = true;
                    if (this.nameError || this.emailError) {
                        return;
                    }
                    window.MDJUIToast.show({
                        type: 'success',
                        title: 'Форма отправлена',
                        text: this.name + ' · ' + this.email
                    });
                }
            };
        });

    }

    registerAlpineComponents();

    // Совместимость дополнений: старый вызов рисует существующий md-toast,
    // а не запускает второй renderer с собственными цветами и позицией.
    if (window.jQuery) {
        window.jQuery.toast = function (options) {
            var id;
            var current = {};
            var handle = {
                reset: function (scope) {
                    if (scope === 'all') window.MDJUIToast.dismiss();
                    else if (id != null) window.MDJUIToast.dismiss(id);
                    return handle;
                },
                update: function (next) {
                    if (id != null) window.MDJUIToast.dismiss(id);
                    current = Object.assign(current, typeof next === 'string' ? {text: next} : (next || {}));
                    var text = Array.isArray(current.text) ? current.text.join('\n') : current.text;
                    if (text || current.heading) id = window.MDJUIToast.show({
                        title: current.heading || '', text: text || '', type: current.icon || 'info',
                        duration: current.hideAfter === false ? 0 : current.hideAfter
                    });
                    return handle;
                }
            };
            if (options !== undefined) handle.update(options);
            return handle;
        };
        window.jQuery.toast.options = {position: 'top-right', stack: 3};
    }
})(window, document);

