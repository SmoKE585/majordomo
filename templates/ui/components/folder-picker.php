<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Folder picker dialog.
 *
 * Props: id, endpoint, eyebrow, title, subtitle, note, class, attrs.
 * The input is not part of this component: buttons opt in with
 * data-md-folder-picker="<input id>" and data-md-folder-picker-modal="<this id>".
 */
$props += array(
    'id' => 'mdFolderPicker',
    'endpoint' => '',
    'eyebrow' => 'Файловая система',
    'title' => 'Выбор папки',
    'subtitle' => 'Навигация ограничена папкой проекта.',
    'note' => 'Показаны только папки внутри проекта. Скрытые каталоги и node_modules не выводятся.',
    'class' => '',
    'attrs' => array(),
);

$id = (string)$props['id'];
$titleId = $id . '-title';
$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('modal', 'fade', 'md-modal', 'md-folder-picker', $props['class']));
$attrs['id'] = $id;
$attrs['tabindex'] = '-1';
$attrs['aria-labelledby'] = $titleId;
$attrs['aria-hidden'] = 'true';
$attrs['data-md-folder-picker-dialog'] = true;
$attrs['data-md-folder-endpoint'] = (string)$props['endpoint'];
?>
<div<?= MDJUI::attrs($attrs) ?>>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header md-folder-picker__header">
                <div class="md-folder-picker__heading">
                    <div class="md-folder-picker__eyebrow"><?= MDJUI::e($props['eyebrow']) ?></div>
                    <h2 class="modal-title" id="<?= MDJUI::e($titleId) ?>"><?= MDJUI::e($props['title']) ?></h2>
                    <p class="md-folder-picker__subtitle"><?= MDJUI::e($props['subtitle']) ?></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>

            <div class="modal-body md-folder-picker__body">
                <div class="md-folder-picker__toolbar">
                    <button type="button" class="md-btn md-btn--secondary md-btn--sm" data-md-folder-up disabled>
                        <?= MDJUI::icon('arrow-up') ?>
                        <span class="md-btn__label">Вверх</span>
                    </button>
                    <nav class="md-folder-picker__breadcrumbs" data-md-folder-breadcrumbs aria-label="Текущий путь"></nav>
                </div>

                <div class="md-folder-picker__searchrow">
                    <div class="md-input-shell md-input-shell--sm md-folder-picker__search">
                        <?= MDJUI::icon('search', array('class' => 'md-input-icon')) ?>
                        <input class="md-input" type="search" data-md-folder-search placeholder="Фильтр по папкам" aria-label="Фильтр по папкам" autocomplete="off">
                    </div>
                    <span class="md-folder-picker__count" data-md-folder-count></span>
                </div>

                <div class="md-folder-picker__note">
                    <?= MDJUI::icon('info-circle') ?>
                    <span><?= MDJUI::e($props['note']) ?></span>
                </div>

                <div class="md-folder-picker__notice" data-md-folder-notice role="status" hidden></div>

                <div class="md-folder-picker__list" data-md-folder-list role="listbox" aria-label="Папки проекта"></div>

                <div class="md-folder-picker__empty" data-md-folder-empty hidden>
                    <?= MDJUI::icon('folder-off') ?>
                    <div>
                        <strong>Здесь нет вложенных папок</strong>
                        <span>Можно выбрать текущую папку или подняться выше.</span>
                    </div>
                </div>

                <div class="md-folder-picker__error" data-md-folder-error role="alert" hidden></div>
            </div>

            <div class="modal-footer md-folder-picker__footer">
                <div class="md-folder-picker__selection">
                    <span class="md-folder-picker__selection-label">Будет выбрано</span>
                    <code class="md-folder-picker__current" data-md-folder-current title=""></code>
                </div>
                <div class="md-folder-picker__actions">
                    <button type="button" class="md-btn md-btn--secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="md-btn md-btn--primary" data-md-folder-choose>
                        <?= MDJUI::icon('folder-check') ?>
                        <span class="md-btn__label">Выбрать эту папку</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
