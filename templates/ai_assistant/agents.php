<?php
// 2026-10-23 — список настраиваемых агентов.
$ready = 0;
foreach ($agents as $agent) if ((int)$agent['ENABLED'] === 1 && $agent['MODEL_TITLE'] !== '') $ready++;
$profileLabels = array('assistant' => 'Помощник MajorDoMo', 'editor' => 'Помощник редактора', 'custom' => 'Свой агент');
?>
<div class="md-ui-kpi-grid md-ui-kpi-grid--compact">
    <?= MDJUI::kpi(array('label' => 'Всего агентов', 'value' => count($agents), 'icon' => 'robot', 'tone' => 'primary')) ?>
    <?= MDJUI::kpi(array('label' => 'Готовы к работе', 'value' => $ready, 'icon' => 'circle-check', 'tone' => 'success',
        'meta' => 'Включены и имеют модель')) ?>
    <?= MDJUI::kpi(array('label' => 'Стандартные профили', 'value' => count(array_filter($agents, static fn(array $agent): bool => $agent['PROFILE'] !== 'custom')),
        'icon' => 'id-badge-2', 'tone' => 'info')) ?>
</div>

<?= MDJUI::card(array(
    'title' => 'Агенты',
    'subtitle' => 'Инструкции, модель, инструменты и источники знаний. Текст промпта не даёт новых прав.',
    'icon' => 'robot',
    'flush' => true,
    'actions' => MDJUI::button('Создать агента', array('href' => $url('/agents/add'), 'icon' => 'plus', 'size' => 'sm')),
), (function () use ($agents, $profileLabels, $url, $e) {
    if ($agents === array()) {
        return MDJUI::emptyState(array('icon' => 'robot', 'title' => 'Агентов нет',
            'text' => 'Создайте агента или восстановите стандартный профиль.',
            'action' => MDJUI::button('Создать агента', array('href' => $url('/agents/add'), 'icon' => 'plus'))));
    }
    ob_start(); ?>
    <div class="md-table-wrap" data-md-table>
        <div class="md-table__toolbar">
            <div class="md-table__search">
                <i class="ti ti-search" aria-hidden="true"></i>
                <input type="search" data-md-table-search placeholder="Поиск по названию или модели" aria-label="Поиск">
            </div>
            <div class="md-table__meta" data-md-table-meta>Агентов: <?= count($agents) ?></div>
        </div>
        <div class="md-table__scroll">
            <table class="md-table">
                <thead><tr><th>Агент</th><th>Профиль</th><th>Модель</th><th>Инструменты</th><th>Знания</th><th>Состояние</th>
                    <th class="text-end"><span class="md-visually-hidden">Действия</span></th></tr></thead>
                <tbody data-md-table-body>
                <?php foreach ($agents as $agent): ?>
                    <tr data-md-row data-search="<?= $e($agent['TITLE'] . ' ' . $agent['MODEL_TITLE']) ?>">
                        <td>
                            <div class="md-table__name">
                                <span class="md-table__icon"><i class="ti ti-robot" aria-hidden="true"></i></span>
                                <div class="md-cell__body">
                                    <strong><?= $e($agent['TITLE']) ?></strong>
                                    <small><?= $e(mb_substr($agent['INSTRUCTIONS'], 0, 90)) ?>…</small>
                                </div>
                            </div>
                        </td>
                        <td><?= MDJUI::badge($profileLabels[$agent['PROFILE']] ?? 'Свой', $agent['PROFILE'] === 'custom' ? 'neutral' : 'info') ?></td>
                        <td><?= $agent['MODEL_TITLE'] !== '' ? $e($agent['MODEL_TITLE']) : MDJUI::badge('Не назначена', 'warning') ?></td>
                        <td><?= count($agent['TOOLS']) ?></td>
                        <td><?= MDJUI::badge($agent['KNOWLEDGE_MODE'] === 'rag' ? 'RAG' : 'Текст', $agent['KNOWLEDGE_MODE'] === 'rag' ? 'info' : 'outline') ?></td>
                        <td><?= MDJUI::badge((int)$agent['ENABLED'] === 1 ? 'Включён' : 'Выключен', (int)$agent['ENABLED'] === 1 ? 'success' : 'neutral', array('dot' => true)) ?></td>
                        <td><div class="md-table__actions"><?= MDJUI::iconButton('pencil', array('label' => 'Изменить', 'href' => $url('/agents/' . (int)$agent['ID']), 'size' => 'sm')) ?></div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="md-table__empty" data-md-table-empty><?= MDJUI::emptyState(array('icon' => 'search', 'title' => 'Ничего не найдено', 'compact' => true)) ?></div>
    </div>
    <?php return (string)ob_get_clean();
})()) ?>
