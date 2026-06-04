(function () {
    function boot(root) {
        var page = root.querySelector('[data-saverestore-page]');
        if (!page) {
            return;
        }
        if (page.getAttribute('data-saverestore-booted') === '1') {
            return;
        }
        page.setAttribute('data-saverestore-booted', '1');

        var modal = page.querySelector('[data-saverestore-update-modal]');
        var modalShouldStayOpen = page.getAttribute('data-saverestore-open-update') === '1';
        var openButtons = page.querySelectorAll('[data-saverestore-update-open]');
        var closeButtons = page.querySelectorAll('[data-saverestore-update-close]');
        var panelButtons = page.querySelectorAll('[data-saverestore-panel]');
        var panelBodies = page.querySelectorAll('[data-saverestore-panel-body]');
        var autoToggle = page.querySelector('[data-saverestore-auto-toggle]');
        var autoSettings = page.querySelector('[data-saverestore-auto-settings]');
        var fileInput = page.querySelector('[data-saverestore-file-input]');
        var fileLabel = page.querySelector('[data-saverestore-file-label]');

        function setModalOpen(isOpen) {
            if (!modal) {
                return;
            }
            modal.classList.toggle('is-open', isOpen);
            document.body.classList.toggle('md-saverestore-modal-open', isOpen);
        }

        function setPanel(panelId) {
            panelButtons.forEach(function (button) {
                button.classList.toggle('is-active', button.getAttribute('data-saverestore-panel') === panelId);
            });
            panelBodies.forEach(function (panel) {
                var isActive = panel.id === panelId;
                panel.classList.toggle('is-active', isActive);
                panel.hidden = !isActive;
            });
        }

        function syncAutoSettings() {
            if (!autoToggle || !autoSettings) {
                return;
            }
            autoSettings.classList.toggle('is-hidden', autoToggle.value !== '1');
        }

        function syncCheckCards() {
            page.querySelectorAll('[data-saverestore-check-card]').forEach(function (card) {
                var input = card.querySelector('input[type="checkbox"]');
                if (!input) {
                    return;
                }
                card.classList.toggle('is-selected', !!input.checked);
            });
        }

        openButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                setModalOpen(true);
                window.systemUpdateSetStatus('Выберите параметры обновления и запустите процесс.', 'active', 10, 'prepare');
            });
        });

        closeButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                if (modalShouldStayOpen) {
                    return;
                }
                setModalOpen(false);
            });
        });

        if (modal) {
            modal.addEventListener('click', function (event) {
                if (event.target === modal && !modalShouldStayOpen) {
                    setModalOpen(false);
                }
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal && modal.classList.contains('is-open') && !modalShouldStayOpen) {
                setModalOpen(false);
            }
        });

        panelButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                setPanel(button.getAttribute('data-saverestore-panel'));
            });
        });

        if (autoToggle) {
            autoToggle.addEventListener('change', syncAutoSettings);
            syncAutoSettings();
        }

        page.querySelectorAll('[data-saverestore-check-card] input[type="checkbox"]').forEach(function (checkbox) {
            checkbox.addEventListener('change', syncCheckCards);
        });
        syncCheckCards();

        if (fileInput && fileLabel) {
            var defaultLabel = fileLabel.textContent;
            fileInput.addEventListener('change', function () {
                if (fileInput.files && fileInput.files.length > 0) {
                    fileLabel.textContent = 'Выбрано файлов: ' + fileInput.files.length;
                } else {
                    fileLabel.textContent = defaultLabel;
                }
            });
        }

        setPanel('uploadBackup');

        if (modalShouldStayOpen) {
            setModalOpen(true);
            window.systemUpdateSetStatus('Подготовка обновления. Журнал выполнения появится ниже.', 'active', 20, 'prepare');
        }
    }

    window.systemUpdateSetStatus = function (text, state, percent, step) {
        var label = document.getElementById('systemUpdateStatusText');
        var icon = document.getElementById('systemUpdateStatusIcon');
        var progress = document.querySelector('#systemUpdateProgress .md-saverestore-progress__bar');
        if (label) {
            label.textContent = text || '';
        }
        if (typeof percent === 'undefined' || percent === null) {
            percent = state === 'success' || state === 'error' ? 100 : 35;
        }
        if (icon) {
            icon.classList.remove('is-success', 'is-error');
            if (state === 'success') {
                icon.classList.add('is-success');
            } else if (state === 'error') {
                icon.classList.add('is-error');
            }
        }
        if (progress) {
            progress.style.width = percent + '%';
            progress.style.background = state === 'error'
                ? 'linear-gradient(90deg, #dc3545, #ef6a78)'
                : state === 'success'
                    ? 'linear-gradient(90deg, #198754, #54b47b)'
                    : 'linear-gradient(90deg, var(--md-admin-primary, #4792d1), #6ab1df)';
        }
        if (step) {
            var activeNode = document.getElementById('systemUpdateStep_' + step);
            var activeOrder = activeNode ? parseInt(activeNode.getAttribute('data-order'), 10) : NaN;
            document.querySelectorAll('.md-saverestore-step').forEach(function (item) {
                var order = parseInt(item.getAttribute('data-order'), 10);
                var isActive = item.id === 'systemUpdateStep_' + step;
                item.classList.toggle('is-active', isActive);
                item.classList.toggle('is-done', !Number.isNaN(activeOrder) && !Number.isNaN(order) && order < activeOrder);
            });
        }
    };

    window.systemUpdateFinish = function (text, state) {
        window.onbeforeunload = null;
        window.systemUpdateSetStatus(text || 'Операция завершена, выполняется переход...', state || 'success', 100, 'finish');
    };

        if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
            window.MDJAdminUI.registerModuleUI('saverestore', {
                init: boot
            });
        }

    document.addEventListener('DOMContentLoaded', function () {
        boot(document);
    });
})();
