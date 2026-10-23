<?php
// 2026-10-23 — модели провайдеров и их возможности.
?>
<?= MDJUI::card(array(
    'title' => 'Модели',
    'subtitle' => 'Чат, Tools и эмбеддинги настраиваются для каждой модели отдельно.',
    'icon' => 'cpu',
    'flush' => true,
    'actions' => $providersCount > 0
        ? MDJUI::button('Добавить модель', array('href' => $url('/models/add'), 'icon' => 'plus', 'size' => 'sm'))
        : MDJUI::button('Добавить провайдера', array('href' => $url('/providers/add'), 'icon' => 'plus', 'size' => 'sm')),
), (function () use ($models, $providersCount, $url, $e) {
    if ($models === array()) {
        return MDJUI::emptyState(array('icon' => 'cpu', 'title' => $providersCount > 0 ? 'Моделей пока нет' : 'Сначала добавьте провайдера',
            'text' => $providersCount > 0
                ? 'Создайте модель с точным идентификатором и проверьте соединение.'
                : 'Модель создаётся внутри провайдера: укажите адрес OpenAI-совместимого API.',
            'action' => $providersCount > 0
                ? MDJUI::button('Добавить модель', array('href' => $url('/models/add'), 'icon' => 'plus'))
                : MDJUI::button('Добавить провайдера', array('href' => $url('/providers/add'), 'icon' => 'plus'))));
    }
    ob_start(); ?>
    <div class="md-table-wrap" data-md-table data-md-table-page-size="20" data-md-table-unit-one="модель" data-md-table-unit-few="модели" data-md-table-unit-many="моделей">
        <div class="md-table__toolbar">
            <div class="md-table__search">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input type="search" data-md-table-search placeholder="Поиск по названию, провайдеру, ID" aria-label="Поиск">
            </div>
            <div class="md-table__meta" data-md-table-meta>Моделей: <?= count($models) ?></div>
        </div>
        <div class="md-table__scroll">
            <table class="md-table">
                <thead><tr>
                    <th>Модель</th><th>Провайдер</th><th>Возможности</th><th>Контекст</th><th>Состояние</th>
                    <th class="text-end"><span class="md-visually-hidden">Действия</span></th>
                </tr></thead>
                <tbody data-md-table-body>
                <?php foreach ($models as $model): ?>
                    <tr data-md-row data-search="<?= $e($model['TITLE'] . ' ' . $model['MODEL'] . ' ' . $model['PROVIDER_TITLE']) ?>">
                        <td>
                            <div class="md-table__name">
                                <span class="md-table__icon"><i class="ti ti-cpu" aria-hidden="true"></i></span>
                                <div class="md-cell__body">
                                    <strong><?= $e($model['TITLE']) ?></strong>
                                    <small><code><?= $e($model['MODEL']) ?></code></small>
                                </div>
                            </div>
                        </td>
                        <td><?= $e($model['PROVIDER_TITLE']) ?></td>
                        <td>
                            <div class="md-ai-chips">
                                <?php foreach ($model['CAPABILITIES'] as $capability): ?>
                                    <?= MDJUI::badge($capability === 'chat' ? 'Чат' : ($capability === 'tools' ? 'Tools' : 'Эмбеддинги'),
                                        $capability === 'embeddings' ? 'info' : 'primary') ?>
                                <?php endforeach; ?>
                                <?php if ($model['HAS_TOKEN']): ?><?= MDJUI::badge('Токен', 'outline', array('dot' => true)) ?><?php endif; ?>
                            </div>
                        </td>
                        <td><?= (int)$model['CONTEXT_WINDOW'] ?> / <?= (int)$model['MAX_OUTPUT'] ?></td>
                        <td><?= MDJUI::badge((int)$model['ENABLED'] === 1 ? 'Включена' : 'Выключена', (int)$model['ENABLED'] === 1 ? 'success' : 'neutral', array('dot' => true)) ?></td>
                        <td>
                            <div class="md-table__actions">
                                <button type="button" class="md-icon-btn md-icon-btn--sm" data-ai-probe
                                        data-model-id="<?= (int)$model['ID'] ?>" data-capability="<?= in_array('tools', $model['CAPABILITIES'], true) ? 'tools' : 'chat' ?>"
                                        title="Проверить соединение" aria-label="Проверить соединение"><i class="ti ti-plug-connected" aria-hidden="true"></i></button>
                                <?= MDJUI::iconButton('pencil', array('label' => 'Изменить', 'href' => $url('/models/' . (int)$model['ID']), 'size' => 'sm')) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="md-table__empty" data-md-table-empty><?= MDJUI::emptyState(array('icon' => 'search', 'title' => 'Ничего не найдено', 'compact' => true)) ?></div>
    </div>
    <?php return (string)ob_get_clean();
})()) ?>
