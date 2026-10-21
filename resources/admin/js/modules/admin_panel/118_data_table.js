/*
 * Data table controller.
 *
 * Progressive enhancement for server-rendered tables:
 *
 *   <div data-md-table data-md-table-page-size="6">
 *     ...search / filter inputs...
 *     <tbody data-md-table-body>
 *       <tr data-md-row data-search="..." data-status="online" data-sort-name="...">
 *
 * The controller never fetches data. It sorts, filters, paginates and manages
 * row selection locally, so it works with any module that renders a table.
 */
(function (window, document) {
    'use strict';

    var instances = new WeakMap();

    function normalize(value) {
        return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function capitalize(value) {
        return value.charAt(0).toUpperCase() + value.slice(1);
    }

    function rowId(row) {
        return row.getAttribute('data-row-id') || '';
    }

    function sortValue(row, key) {
        var attr = 'sort' + capitalize(key);
        if (row.dataset && typeof row.dataset[attr] !== 'undefined') {
            return row.dataset[attr];
        }
        var cell = row.querySelector('[data-sort-key="' + key + '"]');
        if (cell) {
            return cell.dataset.sortValue || cell.textContent;
        }
        return '';
    }

    function isNumeric(value) {
        return value !== '' && !isNaN(Number(String(value).replace(',', '.')));
    }

    function compare(a, b, direction) {
        var left = String(a);
        var right = String(b);
        var result;
        if (isNumeric(left) && isNumeric(right)) {
            result = Number(left.replace(',', '.')) - Number(right.replace(',', '.'));
        } else {
            result = left.localeCompare(right, 'ru', { numeric: true, sensitivity: 'base' });
        }
        return direction === 'desc' ? -result : result;
    }

    function DataTable(root) {
        this.root = root;
        this.body = root.querySelector('[data-md-table-body]');
        this.table = root.querySelector('table');
        this.search = root.querySelector('[data-md-table-search]');
        this.filters = Array.prototype.slice.call(root.querySelectorAll('[data-md-table-filter]'));
        this.checkAll = root.querySelector('[data-md-table-check-all]');
        this.empty = root.querySelector('[data-md-table-empty]');
        this.meta = root.querySelector('[data-md-table-meta]');
        this.count = root.querySelector('[data-md-table-count]');
        this.pagination = root.querySelector('[data-md-table-pagination]');
        this.pageSize = Math.max(1, parseInt(root.getAttribute('data-md-table-page-size') || '6', 10));
        this.unit = root.getAttribute('data-md-table-unit') || 'записей';
        this.unitOne = root.getAttribute('data-md-table-unit-one') || '';
        this.unitFew = root.getAttribute('data-md-table-unit-few') || this.unitOne;
        this.unitMany = root.getAttribute('data-md-table-unit-many') || this.unit;
        this.rows = this.body ? Array.prototype.slice.call(this.body.querySelectorAll('[data-md-row]')) : [];
        this.page = 1;
        this.sortKey = '';
        this.sortDirection = 'asc';
        this.selected = new Set();
        this.filtered = this.rows.slice();
        this.searchTimer = 0;

        this.bind();
        this.render();
    }

    DataTable.prototype.bind = function () {
        var self = this;

        if (this.search) {
            this.search.addEventListener('input', function () {
                clearTimeout(self.searchTimer);
                self.searchTimer = setTimeout(function () {
                    self.page = 1;
                    self.render();
                }, 140);
            });
        }

        this.filters.forEach(function (filter) {
            filter.addEventListener('change', function () {
                self.page = 1;
                self.render();
            });
        });

        this.root.querySelectorAll('[data-md-table-sort]').forEach(function (button) {
            button.addEventListener('click', function () {
                var key = button.getAttribute('data-md-table-sort');
                if (self.sortKey === key) {
                    self.sortDirection = self.sortDirection === 'asc' ? 'desc' : 'asc';
                } else {
                    self.sortKey = key;
                    self.sortDirection = 'asc';
                }
                self.page = 1;
                self.render();
            });
        });

        if (this.checkAll) {
            this.checkAll.addEventListener('change', function () {
                self.pageRows().forEach(function (row) {
                    var checkbox = row.querySelector('[data-md-row-check]');
                    if (!checkbox) {
                        return;
                    }
                    checkbox.checked = self.checkAll.checked;
                    self.setSelected(row, self.checkAll.checked);
                });
                self.updateSelection();
            });
        }

        if (this.body) {
            this.body.addEventListener('change', function (event) {
                var checkbox = event.target.closest('[data-md-row-check]');
                if (!checkbox) {
                    return;
                }
                var row = checkbox.closest('[data-md-row]');
                if (row) {
                    self.setSelected(row, checkbox.checked);
                    self.updateSelection();
                }
            });
        }

        if (this.pagination) {
            this.pagination.addEventListener('click', function (event) {
                var button = event.target.closest('[data-md-table-page]');
                if (!button) {
                    return;
                }
                var page = parseInt(button.getAttribute('data-md-table-page'), 10);
                if (!isNaN(page)) {
                    self.page = page;
                    self.render();
                }
            });
        }
    };

    DataTable.prototype.pageRows = function () {
        if (!this.pagination) {
            return this.filtered;
        }
        var start = (this.page - 1) * this.pageSize;
        return this.filtered.slice(start, start + this.pageSize);
    };

    DataTable.prototype.setSelected = function (row, selected) {
        var id = rowId(row);
        if (selected) {
            this.selected.add(id);
            row.classList.add('is-selected');
        } else {
            this.selected.delete(id);
            row.classList.remove('is-selected');
        }
    };

    DataTable.prototype.formatCount = function (count) {
        if (!this.unitOne) {
            return count + ' ' + this.unit;
        }
        var abs = Math.abs(count) % 100;
        var last = abs % 10;
        var unit = this.unitMany;
        if (abs < 11 || abs > 19) {
            if (last === 1) {
                unit = this.unitOne;
            } else if (last > 1 && last < 5) {
                unit = this.unitFew;
            }
        }
        return count + ' ' + unit;
    };

    DataTable.prototype.updateSelection = function () {
        var selectedOnPage = this.pageRows().filter(function (row) {
            return row.classList.contains('is-selected');
        }).length;
        if (this.checkAll) {
            this.checkAll.checked = selectedOnPage > 0 && selectedOnPage === this.pageRows().length;
            this.checkAll.indeterminate = selectedOnPage > 0 && selectedOnPage < this.pageRows().length;
        }
        if (this.meta && this.selected.size > 0) {
            this.meta.textContent = 'Выбрано: ' + this.selected.size;
        } else if (this.meta) {
            this.meta.textContent = this.formatCount(this.filtered.length);
        }
    };

    DataTable.prototype.applyFilters = function () {
        var query = this.search ? normalize(this.search.value) : '';
        var filters = this.filters.map(function (filter) {
            return {
                key: filter.getAttribute('data-md-table-filter'),
                value: filter.value
            };
        });

        this.filtered = this.rows.filter(function (row) {
            var haystack = normalize(row.getAttribute('data-search') || row.textContent);
            if (query && haystack.indexOf(query) === -1) {
                return false;
            }
            for (var i = 0; i < filters.length; i += 1) {
                var filter = filters[i];
                if (filter.value && row.getAttribute('data-' + filter.key) !== filter.value) {
                    return false;
                }
            }
            return true;
        });

        if (this.sortKey) {
            var key = this.sortKey;
            var direction = this.sortDirection;
            this.filtered.sort(function (a, b) {
                return compare(sortValue(a, key), sortValue(b, key), direction);
            });
        }
    };

    DataTable.prototype.renderPagination = function () {
        if (!this.pagination) {
            return;
        }
        var pages = Math.max(1, Math.ceil(this.filtered.length / this.pageSize));
        if (this.page > pages) {
            this.page = pages;
        }
        var items = [];
        items.push('<li class="page-item' + (this.page === 1 ? ' disabled' : '') + '"><button type="button" class="page-link" data-md-table-page="' + (this.page - 1) + '" aria-label="Предыдущая страница">&lsaquo;</button></li>');
        var start = Math.max(1, this.page - 2);
        var end = Math.min(pages, start + 4);
        start = Math.max(1, end - 4);
        for (var i = start; i <= end; i += 1) {
            items.push('<li class="page-item' + (i === this.page ? ' active' : '') + '"><button type="button" class="page-link" data-md-table-page="' + i + '">' + i + '</button></li>');
        }
        items.push('<li class="page-item' + (this.page === pages ? ' disabled' : '') + '"><button type="button" class="page-link" data-md-table-page="' + (this.page + 1) + '" aria-label="Следующая страница">&rsaquo;</button></li>');
        this.pagination.innerHTML = items.join('');
    };

    DataTable.prototype.render = function () {
        this.applyFilters();
        var pageRows = this.pageRows();
        var selectedIds = this.selected;

        this.rows.forEach(function (row) {
            row.hidden = true;
        });
        pageRows.forEach(function (row) {
            row.hidden = false;
            var checkbox = row.querySelector('[data-md-row-check]');
            if (checkbox) {
                checkbox.checked = selectedIds.has(rowId(row));
            }
        });

        this.root.querySelectorAll('[data-md-table-sort]').forEach(function (button) {
            var key = button.getAttribute('data-md-table-sort');
            var icon = button.querySelector('i');
            button.classList.toggle('is-sorted', key === this.sortKey);
            button.setAttribute('aria-sort', key === this.sortKey ? (this.sortDirection === 'asc' ? 'ascending' : 'descending') : 'none');
            if (icon) {
                icon.className = key === this.sortKey
                    ? (this.sortDirection === 'asc' ? 'ti ti-arrow-up' : 'ti ti-arrow-down')
                    : 'ti ti-arrows-up-down';
            }
        }, this);

        if (this.empty) {
            this.empty.classList.toggle('is-visible', this.filtered.length === 0);
        }
        if (this.table) {
            this.table.hidden = this.filtered.length === 0;
        }

        var from = this.filtered.length === 0 ? 0 : (this.pagination ? (this.page - 1) * this.pageSize + 1 : 1);
        var to = this.pagination ? Math.min(this.filtered.length, this.page * this.pageSize) : this.filtered.length;
        if (this.count) {
            this.count.textContent = 'Показано ' + from + '–' + to + ' из ' + this.filtered.length;
        }
        if (this.meta && this.selected.size === 0) {
            this.meta.textContent = this.formatCount(this.filtered.length);
        }
        this.renderPagination();
        this.updateSelection();
    };

    function initDataTables(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('[data-md-table]').forEach(function (element) {
            if (!instances.has(element)) {
                instances.set(element, new DataTable(element));
            }
        });
    }

    window.MDJUITable = {
        init: initDataTables,
        DataTable: DataTable
    };

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('data-table', { init: initDataTables });
    }
})(window, document);


