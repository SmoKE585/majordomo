<?php
// 2026-11-11 — каталог копий на общей таблице: поиск, фильтры и 20 строк на странице.
require_once ROOT . 'lib/ui/MDJUI.php';
$copies = $data['FILES'] ?? array();
$e = static fn($value): string => MDJUI::e((string)$value);
?>
<div class="md-saverestore-note">
    <i class="ti ti-info-circle" aria-hidden="true"></i>
    <div><strong>Резервные копии на сервере</strong>
        <span>Архивы и дампы базы из двух каталогов. Дистрибутив и служебные файлы обновления здесь не показываются. Имена файлов на диске не меняются.</span></div>
</div>
<?php if ($copies !== array()): ?>
<div class="md-table-wrap md-saverestore-catalog" data-md-table data-md-table-page-size="20"
     data-md-table-unit-one="копия" data-md-table-unit-few="копии" data-md-table-unit-many="копий">
    <div class="md-table__toolbar">
        <div class="md-table__search">
            <i class="ti ti-search" aria-hidden="true"></i>
            <input type="search" data-md-table-search aria-label="Поиск резервной копии" placeholder="Название, дата или состав">
        </div>
        <select class="md-table__filter" data-md-table-filter="kind" aria-label="Назначение копии">
            <option value="">Все копии</option>
            <option value="before_update">База перед обновлением</option>
            <option value="database">Копии базы данных</option>
            <option value="archive">Архивы</option>
            <option value="directory">Папки</option>
        </select>
        <select class="md-table__filter" data-md-table-filter="source" aria-label="Место хранения">
            <option value="">Оба каталога</option>
            <option value="local">Каталог обновления</option>
            <option value="storage">Каталог резервных копий</option>
        </select>
        <span class="md-table__meta" data-md-table-meta><?= count($copies) ?> копий</span>
    </div>
    <div class="md-table__scroll">
        <table class="md-table">
            <thead><tr>
                <th><button type="button" class="md-table__sort" data-md-table-sort="name">Копия <i class="ti ti-arrows-up-down" aria-hidden="true"></i></button></th>
                <th><button type="button" class="md-table__sort" data-md-table-sort="date">Дата и хранение <i class="ti ti-arrows-up-down" aria-hidden="true"></i></button></th>
                <th>Действия</th>
            </tr></thead>
            <tbody data-md-table-body>
            <?php foreach ($copies as $copy): ?>
                <tr data-md-row data-row-id="<?= $e($copy['ID']) ?>" data-kind="<?= $e($copy['KIND']) ?>" data-source="<?= $e($copy['SOURCE']) ?>"
                    data-sort-name="<?= $e($copy['TITLE'] . ' ' . $copy['NAME']) ?>" data-sort-date="<?= $e($copy['TIMESTAMP']) ?>"
                    data-search="<?= $e(implode(' ', array($copy['TITLE'], $copy['NAME'], $copy['DATE'], $copy['CONTENTS'], $copy['SOURCE_TITLE']))) ?>">
                    <td class="md-saverestore-catalog__copy">
                        <strong><?= $e($copy['TITLE']) ?></strong>
                        <span><?= $e($copy['CONTENTS']) ?></span>
                        <code><?= $e($copy['NAME']) ?></code>
                    </td>
                    <td class="md-saverestore-catalog__metadata">
                        <time datetime="<?= $e(date('c', $copy['TIMESTAMP'])) ?>"><?= $e($copy['DATE']) ?></time>
                        <small><?= $e($copy['DATE_LABEL']) ?></small>
                        <span title="<?= $e($copy['SOURCE_PATH']) ?>"><?= $e($copy['SOURCE_TITLE']) ?></span>
                        <small><?= $e($copy['SIZE']) ?></small>
                    </td>
                    <td>
                        <div class="md-saverestore-catalog__actions">
                            <?php if ($copy['CAN_RESTORE'] && empty($data['CLEAR_FIRST'])): ?>
                                <?= MDJUI::button('Восстановить', array('href' => '?mode=upload&restore=' . $copy['FILENAME_URL'],
                                    'variant' => 'secondary', 'size' => 'sm', 'attrs' => array('data-md-confirm' => 'Восстановить копию «' . $copy['NAME'] . '»? Текущие данные могут быть заменены.'))) ?>
                            <?php endif; ?>
                            <?php if ($copy['CAN_DOWNLOAD']): ?>
                                <?= MDJUI::button('Скачать', array('href' => $copy['DOWNLOAD_URL'], 'icon' => 'download', 'variant' => 'secondary', 'size' => 'sm', 'attrs' => array('download' => $copy['NAME']))) ?>
                            <?php endif; ?>
                            <?= MDJUI::iconButton('trash', array('href' => '?mode=delete&file=' . $copy['FILENAME_URL'], 'variant' => 'danger',
                                'size' => 'sm', 'label' => 'Удалить копию «' . $copy['NAME'] . '»', 'attrs' => array('data-md-confirm' => 'Удалить копию «' . $copy['NAME'] . '»?'))) ?>
                        </div>
                        <?php if ($copy['NOTE'] !== ''): ?><small class="md-saverestore-catalog__note"><?= $e($copy['NOTE']) ?></small><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="md-table__empty" data-md-table-empty><?= MDJUI::emptyState(array('title' => 'Копии не найдены', 'text' => 'Измените поиск или фильтры.', 'compact' => true)) ?></div>
    <div class="md-table__footer">
        <span data-md-table-count></span>
        <ul class="pagination md-pagination" data-md-table-pagination aria-label="Страницы резервных копий"></ul>
    </div>
</div>
<?php else: ?>
<?= MDJUI::emptyState(array('icon' => 'archive-off', 'title' => 'Резервных копий пока нет', 'text' => 'Создайте копию или загрузите подготовленный архив.', 'compact' => true)) ?>
<?php endif; ?>
