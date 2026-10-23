<?php
// 2026-10-23 — провайдеры OpenAI-совместимого API.
?>
<?= MDJUI::card(array(
    'title' => 'Провайдеры',
    'subtitle' => 'OpenAI-совместимые серверы: OpenAI, локальные модели, шлюзы.',
    'icon' => 'server',
    'flush' => true,
    'actions' => MDJUI::button('Добавить провайдера', array('href' => $url('/providers/add'), 'icon' => 'plus', 'size' => 'sm')),
), (function () use ($providers, $url, $e) {
    if ($providers === array()) {
        return MDJUI::emptyState(array('icon' => 'server', 'title' => 'Провайдеров пока нет',
            'text' => 'Добавьте адрес API и таймаут. Токены задаются у моделей.',
            'action' => MDJUI::button('Добавить провайдера', array('href' => $url('/providers/add'), 'icon' => 'plus'))));
    }
    ob_start(); ?>
    <div class="md-table-wrap" data-md-table>
        <div class="md-table__toolbar">
            <div class="md-table__search">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input type="search" data-md-table-search placeholder="Поиск по названию или адресу" aria-label="Поиск">
            </div>
            <div class="md-table__meta" data-md-table-meta>Провайдеров: <?= count($providers) ?></div>
        </div>
        <div class="md-table__scroll">
            <table class="md-table">
                <thead><tr>
                    <th data-md-table-sort="title">Провайдер</th>
                    <th>Адрес API</th>
                    <th>Моделей</th>
                    <th>Состояние</th>
                    <th class="text-end"><span class="md-visually-hidden">Действия</span></th>
                </tr></thead>
                <tbody data-md-table-body>
                <?php foreach ($providers as $provider): ?>
                    <tr data-md-row data-search="<?= $e($provider['TITLE'] . ' ' . $provider['BASE_URL']) ?>">
                        <td>
                            <div class="md-table__name">
                                <span class="md-table__icon"><i class="ti ti-server" aria-hidden="true"></i></span>
                                <div class="md-cell__body">
                                    <strong><?= $e($provider['TITLE']) ?></strong>
                                    <small><?= $e($provider['PROTOCOL']) ?> · таймаут <?= (int)$provider['TIMEOUT'] ?> с</small>
                                </div>
                            </div>
                        </td>
                        <td><code class="md-ai-code"><?= $e($provider['BASE_URL']) ?></code></td>
                        <td><?= (int)$provider['MODELS'] ?></td>
                        <td><?= MDJUI::badge((int)$provider['ENABLED'] === 1 ? 'Включён' : 'Выключен', (int)$provider['ENABLED'] === 1 ? 'success' : 'neutral', array('dot' => true)) ?></td>
                        <td>
                            <div class="md-table__actions">
                                <?= MDJUI::iconButton('pencil', array('label' => 'Изменить', 'href' => $url('/providers/' . (int)$provider['ID']), 'size' => 'sm')) ?>
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
