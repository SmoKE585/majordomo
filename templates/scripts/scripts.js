(function (window, document) {
    'use strict';

    var STORAGE_KEY = 'md-scripts-open-categories-v1';

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

    function readJsonStorage(key, fallback) {
        try {
            var raw = window.localStorage.getItem(key);
            if (!raw) {
                return fallback;
            }
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function writeJsonStorage(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            // Ignore storage failures.
        }
    }

    function toggleVisible(node, visible) {
        if (!node) {
            return;
        }
        node.hidden = !visible;
    }

    function setBreadcrumbLabel(root) {
        var page = root.querySelector('[data-scripts-page]');
        var breadcrumb = document.querySelector('.breadcrumb');
        var note;

        if (!page || !breadcrumb) {
            return;
        }

        var existing = breadcrumb.querySelector('[data-scripts-breadcrumb]');
        if (existing) {
            existing.remove();
        }

        note = document.createElement('li');
        note.className = 'md-scripts-breadcrumb-muted';
        note.setAttribute('data-scripts-breadcrumb', '1');
        if (!page.querySelector('[data-scripts-edit-page]')) {
            return;
        }
        note.textContent = page.dataset.scriptsEditMode === 'edit'
            ? (page.dataset.scriptsBreadcrumbEdit || 'Edit record')
            : (page.dataset.scriptsBreadcrumbNew || 'New record');
        if (note.textContent) {
            breadcrumb.appendChild(note);
        }
    }

    function initSearchPage(root) {
        var page = root.querySelector('[data-scripts-page]');
        var searchForm = page ? page.querySelector('[data-scripts-search-form]') : null;
        var exportForm = page ? page.querySelector('[data-scripts-export-form]') : null;
        var exportButton = page ? page.querySelector('[data-scripts-export-submit]') : null;
        var exportLabel = exportButton ? (exportButton.getAttribute('data-scripts-export-label') || exportButton.textContent || 'Export') : '';
        var exportInputs = page ? page.querySelectorAll('[data-scripts-export-checkbox]') : [];
        var categories = page ? page.querySelectorAll('details[data-scripts-category]') : [];
        var importPanel = page ? page.querySelector('[data-scripts-import]') : null;
        var searchInput = page ? page.querySelector('[data-scripts-filter]') : null;
        var recentlyUpdated = page ? page.querySelector('[data-scripts-recently-updated]') : null;
        var searchButtons = page ? page.querySelectorAll('[data-scripts-search-open]') : [];
        var titleValue = searchInput ? String(searchInput.value || '').trim() : '';
        var openState = readJsonStorage(STORAGE_KEY, {});
        var hasTitleFilter = titleValue.length > 0;

        function applyExportState() {
            var total = 0;
            exportInputs.forEach(function (input) {
                if (input.checked) {
                    total += 1;
                }
            });
            if (exportButton) {
                exportButton.textContent = total ? (exportLabel + ' ' + total) : exportLabel;
                exportButton.disabled = total === 0;
            }
        }

        function setCategoryState(details) {
            var categoryId = details.getAttribute('data-category-id') || '';
            var savedState = Object.prototype.hasOwnProperty.call(openState, categoryId) ? !!openState[categoryId] : null;
            if (hasTitleFilter) {
                details.open = true;
                return;
            }
            if (savedState === null) {
                details.open = categories.length === 1;
                return;
            }
            details.open = savedState;
        }

        if (!page) {
            return;
        }
        if (page.dataset.scriptsSearchBound === '1') {
            return;
        }
        page.dataset.scriptsSearchBound = '1';

        categories.forEach(function (details) {
            setCategoryState(details);
            details.addEventListener('toggle', function () {
                var id = details.getAttribute('data-category-id') || '';
                openState[id] = details.open;
                writeJsonStorage(STORAGE_KEY, openState);
            });
        });

        exportInputs.forEach(function (input) {
            input.addEventListener('change', applyExportState);
        });
        applyExportState();

        if (searchButtons.length) {
            searchButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var term = button.getAttribute('data-scripts-search-term') || '';
                    if (window.MDJAdminUI && typeof window.MDJAdminUI.openSearch === 'function') {
                        window.MDJAdminUI.openSearch(term);
                        return;
                    }
                    if (window.MDJAdminSearch && typeof window.MDJAdminSearch.setFilter === 'function') {
                        window.MDJAdminSearch.setFilter(term);
                    }
                });
            });
        }

        if (recentlyUpdated) {
            recentlyUpdated.addEventListener('change', function () {
                if (recentlyUpdated.value) {
                    recentlyUpdated.form.submit();
                }
            });
        }

        if (searchForm) {
            searchForm.addEventListener('submit', function () {
                if (searchInput && searchInput.value.trim() === '') {
                    return;
                }
            });
        }

        if (importPanel) {
            var summary = importPanel.querySelector('summary');
            if (summary) {
                summary.addEventListener('click', function () {
                    window.setTimeout(function () {
                        writeJsonStorage('md-scripts-import-open-v1', !!importPanel.open);
                    }, 0);
                });
            }
            importPanel.open = !!readJsonStorage('md-scripts-import-open-v1', false);
        }

        page.querySelectorAll('[data-scripts-confirm]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                var message = link.getAttribute('data-scripts-confirm') || '';
                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    }

    function initEditPage(root) {
        var page = root.querySelector('[data-scripts-edit-page]');
        var descriptionToggleButtons = page ? page.querySelectorAll('[data-scripts-description-toggle]') : [];
        var descriptionBlock = document.getElementById('description_div');
        var descriptionPreviewBlock = document.getElementById('description_div_show');
        var autoHideAlerts = page ? page.querySelectorAll('[data-scripts-auto-hide]') : [];
        var moreToggle = page ? page.querySelector('[data-scripts-more-toggle]') : null;
        var morePanel = page ? page.querySelector('[data-scripts-more-actions]') : null;
        var scheduleToggle = page ? page.querySelector('[data-scripts-schedule-toggle]') : null;
        var schedulePanel = page ? page.querySelector('[data-scripts-schedule-panel]') : null;
        var errorNode = page ? page.querySelector('[data-scripts-error-line]') : null;
        var checkCodeButton = page ? page.querySelector('[data-scripts-check-code]') : null;
        var form = page ? page.querySelector('form') : null;
        var codeMirrorLines = [];

        if (!page) {
            return;
        }
        if (page.dataset.scriptsEditBound === '1') {
            return;
        }
        page.dataset.scriptsEditBound = '1';

        autoHideAlerts.forEach(function (node) {
            window.setTimeout(function () {
                node.style.transition = 'opacity .2s ease';
                node.style.opacity = '0';
                window.setTimeout(function () {
                    toggleVisible(node, false);
                }, 220);
            }, 4500);
        });

        if (descriptionToggleButtons.length && descriptionBlock && descriptionPreviewBlock) {
            descriptionToggleButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var previewVisible = !descriptionPreviewBlock.hidden;
                    toggleVisible(descriptionPreviewBlock, !previewVisible);
                    toggleVisible(descriptionBlock, previewVisible);
                });
            });
        }

        if (moreToggle && morePanel) {
            moreToggle.addEventListener('click', function () {
                morePanel.hidden = !morePanel.hidden;
            });
        }

        if (scheduleToggle && schedulePanel) {
            scheduleToggle.addEventListener('change', function () {
                schedulePanel.classList.toggle('is-collapsed', !scheduleToggle.checked);
            });
        }

        if (errorNode) {
            if (errorNode.getAttribute('data-scripts-error-enabled') === '1' && errorNode.getAttribute('data-scripts-error-settings') === '1') {
                var errorLine = Number(errorNode.getAttribute('data-scripts-error-line') || 0);
                var errorText = errorNode.getAttribute('data-scripts-error-text') || '';
                var errorUp = errorNode.getAttribute('data-scripts-error-up') === '1';
                if (window.CodeMirror && errorLine > 0) {
                    window.setTimeout(function () {
                        codeMirrorLines = Array.prototype.slice.call(document.querySelectorAll('div pre.CodeMirror-line'));
                        var index = Math.max(0, errorLine - 1);
                        var lineNode = codeMirrorLines[index];
                        if (!lineNode) {
                            return;
                        }
                        lineNode.id = 'errorline_' + errorLine;
                        if (!lineNode.querySelector('.cm-error.md-scripts-code-error-line')) {
                            var errorBlock = document.createElement('div');
                            errorBlock.className = 'cm-error md-scripts-code-error-line';
                            errorBlock.textContent = 'Code error -> ' + errorText;
                            lineNode.appendChild(errorBlock);
                        }
                        if (errorUp) {
                            window.location.hash = '#errorline_' + errorLine;
                        }
                    }, 100);
                } else {
                    errorNode.textContent = errorText;
                }
            }
        }

        if (checkCodeButton && form) {
            checkCodeButton.addEventListener('click', function () {
                if (typeof window.ajaxAutosave === 'function') {
                    window.ajaxAutosave('checkcode');
                }
            });
        }

        setBreadcrumbLabel(root);
    }

    function init(root) {
        initSearchPage(root);
        initEditPage(root);
    }

    window.MDScriptsUI = {
        init: init,
        escapeHtml: escapeHtml
    };

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('scripts', window.MDScriptsUI);
    }

    document.addEventListener('DOMContentLoaded', function () {
        init(document);
    });
})(window, document);
