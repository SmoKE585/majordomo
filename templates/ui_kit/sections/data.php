<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/** Tables and data display. */
function md_ui_signal_icon($signal)
{
    if ($signal >= 75) {
        return 'antenna-bars-5';
    }
    if ($signal >= 50) {
        return 'antenna-bars-4';
    }
    if ($signal >= 25) {
        return 'antenna-bars-3';
    }
    if ($signal > 0) {
        return 'antenna-bars-2';
    }
    return 'antenna-bars-1';
}

$updatedSort = array(
    '12 сек назад' => 12,
    '4 сек назад' => 4,
    '1 мин назад' => 60,
    '18 мин назад' => 1080,
    '2 ч назад' => 7200,
    '34 сек назад' => 34,
    '2 мин назад' => 120,
    '3 ч назад' => 10800,
);
?>
<section class="md-ui-section" id="data" data-md-doc-section>
    <div class="md-ui-section__intro">
        <?= MDJUI::sectionHeading(array(
            'eyebrow' => '06 · Data',
            'title' => 'Таблицы и данные',
            'description' => 'Таблица — для плотных сравнимых данных. Поиск, фильтр, сортировка, выбор строк и действия живут в одной панели и не дублируются в заголовке страницы.',
            'level' => 2,
        )) ?>
        <span class="md-ui-section__kicker">md-table · data-md-table</span>
    </div>

    <?php md_ui_demo(array(
        'title' => 'Data Table',
        'desc' => 'Серверный рендер строк + локальные сортировка, поиск, фильтр и пагинация. Выбор строк сохраняется между страницами, действия — в строке.',
        'api' => 'data-md-table',
        'canvas_class' => 'md-ui-demo__canvas--flush',
        'code' => <<<'CODE'
<div class="md-table-wrap" data-md-table data-md-table-page-size="6" data-md-table-unit="устройств">
  <div class="md-table__toolbar">
    <div class="md-table__search">
      <i class="ti ti-search"></i>
      <input type="search" data-md-table-search placeholder="Поиск по устройству">
    </div>
    <select class="md-table__filter" data-md-table-filter="state">...</select>
    <div class="md-table__meta" data-md-table-meta>8 устройств</div>
  </div>
  <div class="md-table__scroll">
    <table class="md-table">
      <thead>
        <tr>
          <th class="md-table__check"><input type="checkbox" data-md-raw-checkbox data-md-table-check-all></th>
          <th><button class="md-table__sort" data-md-table-sort="name">...</button></th>
        </tr>
      </thead>
      <tbody data-md-table-body>
        <tr data-md-row data-row-id="101" data-search="..." data-state="online" data-sort-name="...">
          ...
        </tr>
      </tbody>
    </table>
  </div>
  <div class="md-table__empty" data-md-table-empty>...</div>
  <div class="md-table__footer">
    <span data-md-table-count>...</span>
    <ul class="pagination md-pagination" data-md-table-pagination></ul>
  </div>
</div>
CODE,
    ), function () use ($tableRows, $updatedSort) { ?>
        <div class="md-table-wrap" data-md-table data-md-table-page-size="6" data-md-table-unit="устройств">
            <div class="md-table__toolbar">
                <div class="md-table__search">
                    <?= MDJUI::icon('search') ?>
                    <input type="search" data-md-table-search placeholder="Поиск по устройству или помещению" aria-label="Поиск по устройствам">
                </div>
                <select class="md-table__filter" data-md-table-filter="state" aria-label="Фильтр по состоянию">
                    <option value="">Все состояния</option>
                    <option value="online">Онлайн</option>
                    <option value="warning">Внимание</option>
                    <option value="offline">Офлайн</option>
                    <option value="error">Ошибка</option>
                </select>
                <select class="md-table__filter" data-md-table-filter="class" aria-label="Фильтр по классу">
                    <option value="">Все классы</option>
                    <option value="Свет">Свет</option>
                    <option value="Климат">Климат</option>
                    <option value="Датчик">Датчик</option>
                    <option value="Безопасность">Безопасность</option>
                    <option value="Вентиляция">Вентиляция</option>
                </select>
                <div class="md-table__meta" data-md-table-meta><?= count($tableRows) ?> устройств</div>
            </div>
            <div class="md-table__scroll">
                <table class="md-table">
                    <thead>
                    <tr>
                        <th class="md-table__check">
                            <label class="md-check" aria-label="Выбрать все строки">
                                <input class="md-check__input" type="checkbox" data-md-raw-checkbox data-md-table-check-all aria-label="Выбрать все строки">
                                <span class="md-check__box" aria-hidden="true"><?= MDJUI::icon('check2') ?></span>
                            </label>
                        </th>
                        <th><button type="button" class="md-table__sort" data-md-table-sort="name">Устройство <?= MDJUI::icon('arrow-down-up') ?></button></th>
                        <th>Помещение</th>
                        <th>Состояние</th>
                        <th><button type="button" class="md-table__sort" data-md-table-sort="signal">Сигнал <?= MDJUI::icon('arrow-down-up') ?></button></th>
                        <th>Ответственный</th>
                        <th><button type="button" class="md-table__sort" data-md-table-sort="updated">Обновлено <?= MDJUI::icon('arrow-down-up') ?></button></th>
                        <th class="text-end">Действия</th>
                    </tr>
                    </thead>
                    <tbody data-md-table-body>
                    <?php foreach ($tableRows as $row):
                        $signal = (int)$row['signal'];
                        $search = mb_strtolower($row['name'] . ' ' . $row['class'] . ' ' . $row['room'] . ' ' . $row['state_label'], 'UTF-8');
                        ?>
                        <tr data-md-row
                            data-row-id="<?= (int)$row['id'] ?>"
                            data-search="<?= MDJUI::e($search) ?>"
                            data-state="<?= MDJUI::e($row['state']) ?>"
                            data-class="<?= MDJUI::e($row['class']) ?>"
                            data-sort-name="<?= MDJUI::e($row['name']) ?>"
                            data-sort-room="<?= MDJUI::e($row['room']) ?>"
                            data-sort-signal="<?= $signal ?>"
                            data-sort-updated="<?= (int)(isset($updatedSort[$row['updated']]) ? $updatedSort[$row['updated']] : 99999) ?>">
                            <td class="md-table__check">
                                <label class="md-check" aria-label="Выбрать <?= MDJUI::e($row['name']) ?>">
                                    <input class="md-check__input" type="checkbox" data-md-raw-checkbox data-md-row-check aria-label="Выбрать <?= MDJUI::e($row['name']) ?>">
                                    <span class="md-check__box" aria-hidden="true"><?= MDJUI::icon('check2') ?></span>
                                </label>
                            </td>
                            <td>
                                <div class="md-table__name">
                                    <span class="md-table__icon"><?= MDJUI::icon($row['icon']) ?></span>
                                    <span><strong><?= MDJUI::e($row['name']) ?></strong><small><?= MDJUI::e($row['class']) ?> · <?= MDJUI::e($row['value']) ?></small></span>
                                </div>
                            </td>
                            <td data-sort-key="room"><?= MDJUI::e($row['room']) ?></td>
                            <td><?= MDJUI::status($row['state'], $row['state_label']) ?></td>
                            <td>
                                <span class="md-table__signal">
                                    <?= MDJUI::icon(md_ui_signal_icon($signal)) ?>
                                    <?= $signal > 0 ? $signal . '%' : '—' ?>
                                </span>
                            </td>
                            <td>
                                <?= MDJUI::avatarGroup(array(
                                    array('name' => 'Анна Смирнова', 'tone' => 'primary'),
                                    array('name' => 'Игорь Волков', 'tone' => 'success'),
                                    array('name' => 'Мария Ким', 'tone' => 'warning'),
                                ), array('max' => 2, 'size' => 'sm')) ?>
                            </td>
                            <td><?= MDJUI::lastSeen(array('label' => '', 'value' => $row['updated'])) ?></td>
                            <td>
                                <div class="md-table__actions">
                                    <?= MDJUI::iconButton('pencil', array('label' => 'Редактировать', 'size' => 'sm')) ?>
                                    <div class="dropdown">
                                        <button type="button" class="md-icon-btn md-icon-btn--sm" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Действия">
                                            <?= MDJUI::icon('three-dots') ?>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end md-menu">
                                            <li><button type="button" class="dropdown-item"><?= MDJUI::icon('power') ?>Включить</button></li>
                                            <li><button type="button" class="dropdown-item"><?= MDJUI::icon('arrow-repeat') ?>Перезапустить</button></li>
                                            <li><button type="button" class="dropdown-item"><?= MDJUI::icon('clock-history') ?>История</button></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li><button type="button" class="dropdown-item text-danger"><?= MDJUI::icon('trash3') ?>Удалить</button></li>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="md-table__empty" data-md-table-empty>
                <?= MDJUI::emptyState(array('icon' => 'search', 'title' => 'Ничего не найдено', 'text' => 'Измените поисковый запрос или сбросьте фильтры.', 'compact' => true)) ?>
            </div>
            <div class="md-table__footer">
                <span class="md-ui-muted" data-md-table-count>Показано 1–6 из <?= count($tableRows) ?></span>
                <ul class="pagination md-pagination" data-md-table-pagination></ul>
            </div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Badges, status и progress',
        'desc' => 'Статус — точка + текст, badge — короткая метка. Progress показывает долю и подписывается значением, если число важно.',
        'api' => 'MDJUI::badge() · MDJUI::status() · MDJUI::progress()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::badge('Онлайн', 'success', ['dot' => true]) ?>
<?= MDJUI::status('error', 'Ошибка связи') ?>
<?= MDJUI::progress(72, ['label' => 'Яркость', 'show_value' => true]) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-badge-row">
            <?= MDJUI::badge('Онлайн', 'success', array('dot' => true)) ?>
            <?= MDJUI::badge('Внимание', 'warning') ?>
            <?= MDJUI::badge('Ошибка', 'danger') ?>
            <?= MDJUI::badge('Инфо', 'info') ?>
            <?= MDJUI::badge('Основной', 'primary') ?>
            <?= MDJUI::badge('Нейтральный') ?>
            <?= MDJUI::badge('Контур', 'outline') ?>
        </div>
        <div class="md-ui-status-row">
            <?= MDJUI::status('online', 'Онлайн') ?>
            <?= MDJUI::status('warning', 'Низкий заряд') ?>
            <?= MDJUI::status('error', 'Ошибка связи') ?>
            <?= MDJUI::status('offline', 'Офлайн') ?>
            <?= MDJUI::status('idle', 'Неактивно') ?>
        </div>
        <div class="md-ui-progress-stack">
            <?= MDJUI::progress(92, array('label' => 'Сигнал Zigbee', 'show_value' => true, 'tone' => 'success')) ?>
            <?= MDJUI::progress(64, array('label' => 'Заряд батареи', 'show_value' => true, 'tone' => 'warning')) ?>
            <?= MDJUI::progress(38, array('label' => 'Загрузка сценария', 'show_value' => true, 'tone' => 'primary')) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Tooltip и avatar group',
        'desc' => 'Tooltip — короткая подсказка по наведению, не документация. Avatar group схлопывает участников после третьего и показывает +N.',
        'api' => 'Bootstrap tooltip · MDJUI::avatarGroup()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<button class="md-icon-btn" data-bs-toggle="tooltip" data-bs-title="Обновить состояние">
  <i class="ti ti-refresh"></i>
</button>

<?= MDJUI::avatarGroup($people, ['max' => 3]) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-tooltip-row">
            <button type="button" class="md-icon-btn md-icon-btn--secondary" data-bs-toggle="tooltip" data-bs-title="Обновить состояние">
                <?= MDJUI::icon('arrow-clockwise') ?>
            </button>
            <button type="button" class="md-btn md-btn--secondary" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Обновит данные всех устройств">
                <?= MDJUI::icon('broadcast') ?><span class="md-btn__label">Синхронизировать</span>
            </button>
            <span data-bs-toggle="tooltip" data-bs-title="Последнее обновление 4 секунды назад"><?= MDJUI::status('online', 'Онлайн') ?></span>
            <span class="md-ui-demo__divider" aria-hidden="true"></span>
            <?= MDJUI::avatarGroup(array(
                array('name' => 'Анна Смирнова', 'tone' => 'primary'),
                array('name' => 'Игорь Волков', 'tone' => 'success'),
                array('name' => 'Мария Ким', 'tone' => 'warning'),
                array('name' => 'Дмитрий Орлов', 'tone' => 'info'),
                array('name' => 'Ольга Белова', 'tone' => 'danger'),
            ), array('max' => 3)) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Состояния загрузки и отсутствия данных',
        'desc' => 'Во время запроса показывайте skeleton или строку загрузки рядом с таблицей. Empty state появляется только после завершения запроса.',
        'api' => 'skeleton · spinner · empty state',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<div class="md-table__loading">
  <span class="md-spinner" aria-hidden="true"></span>
  Загружаем устройства…
</div>

<?= MDJUI::emptyState(['title' => 'Нет данных', 'text' => '...']) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-state-grid">
            <div class="md-card">
                <div class="md-card__body">
                    <div class="md-table__loading"><span class="md-spinner" aria-hidden="true"></span>Загружаем устройства…</div>
                    <?= MDJUI::skeleton(array('width' => '100%')) ?>
                    <?= MDJUI::skeleton(array('width' => '82%')) ?>
                    <?= MDJUI::skeleton(array('width' => '64%')) ?>
                </div>
            </div>
            <div class="md-card">
                <?= MDJUI::emptyState(array('icon' => 'inbox', 'title' => 'Пока нет данных', 'text' => 'Устройства появятся здесь после подключения.', 'compact' => true)) ?>
            </div>
        </div>
    <?php }); ?>
</section>





