<?php
// 2026-10-23 — диагностика модуля без раскрытия секретов.
$readyIndexes = count(array_filter($indexes, static fn(array $row): bool => $row['STATUS'] === 'ready'));
?>
<div class="md-ui-kpi-grid">
    <?= MDJUI::kpi(array('label' => 'Модуль', 'value' => !empty($configured) ? 'Готов' : 'Нужна настройка',
        'icon' => 'power', 'tone' => !empty($configured) ? 'success' : 'warning', 'meta' => 'Схема v' . $schema)) ?>
    <?= MDJUI::kpi(array('label' => 'Провайдеры / модели', 'value' => $providers . ' / ' . count($models),
        'icon' => 'server', 'tone' => 'primary', 'meta' => 'Моделей эмбеддингов: ' . $embeddingCount)) ?>
    <?= MDJUI::kpi(array('label' => 'Индексы знаний', 'value' => $readyIndexes, 'icon' => 'database',
        'tone' => $readyIndexes > 0 ? 'success' : 'info', 'meta' => count($indexes) . ' записей')) ?>
    <?= MDJUI::kpi(array('label' => 'Диалоги / сообщения', 'value' => $dialogs . ' / ' . $messages,
        'icon' => 'messages', 'tone' => 'info')) ?>
</div>

<div class="md-ai-split">
    <?php ob_start(); ?>
        <div class="md-ai-diag-list">
            <div><span>Схема БД</span><strong><?= (int)$schema ?></strong></div>
            <div><span>Состояние модуля</span><strong>установлен</strong></div>
            <div><span>Агентов</span><strong><?= count($agents) ?></strong></div>
            <div><span>Qdrant</span><strong><?= !empty($ragStatus['available']) ? 'доступен' : 'недоступен' ?></strong></div>
        </div>
        <div class="md-ai-probe-result" data-ai-probe-result hidden></div>
        <div class="md-ui-form-actions">
            <?= MDJUI::button('Проверить Qdrant', array('type' => 'button', 'variant' => 'secondary', 'icon' => 'plug-connected',
                'attrs' => array('data-ai-rag-probe' => ''))) ?>
            <?= MDJUI::button('Проверить рабочую модель', array('type' => 'button', 'variant' => 'secondary', 'icon' => 'cpu',
                'attrs' => array('data-ai-probe' => '', 'data-model-id' => $models !== array() ? (int)($models[0]['ID'] ?? 0) : 0,
                    'data-capability' => 'chat'))) ?>
        </div>
    <?php $diag = ob_get_clean(); ?>
    <?= MDJUI::card(array('title' => 'Состояние', 'icon' => 'activity', 'compact' => true,
        'subtitle' => 'Секреты и полные ответы моделей в диагностику не попадают.'), $diag) ?>

    <?php ob_start(); ?>
        <p class="md-ai-muted">Команда проверяет доступность Qdrant и наличие модели эмбеддингов. Индекс не изменяется.</p>
        <?= MDJUI::codeSample(array('language' => 'bash', 'title' => 'Проверка из терминала',
            'code' => "php majordomo ai:rag-check --json\nphp majordomo ai:index --all --json", 'copy' => true)) ?>
    <?php $cli = ob_get_clean(); ?>
    <?= MDJUI::card(array('title' => 'CLI', 'icon' => 'terminal-2', 'compact' => true,
        'subtitle' => 'Команды не раскрывают токены.'), $cli) ?>
</div>

<?= MDJUI::card(array('title' => 'Модели', 'icon' => 'cpu', 'flush' => true), (function () use ($models, $e, $url) {
    if ($models === array()) return MDJUI::emptyState(array('icon' => 'cpu', 'title' => 'Моделей нет', 'compact' => true));    ob_start(); ?>
    <div class="md-table__scroll">
        <table class="md-table">
            <thead><tr><th>Модель</th><th>Возможности</th><th>Токен</th><th>Состояние</th><th class="text-end">Проверка</th></tr></thead>
            <tbody>
            <?php foreach ($models as $model): ?>
                <tr>
                    <td><strong><?= $e($model['TITLE']) ?></strong><br><small><code><?= $e($model['MODEL']) ?></code></small></td>
                    <td>
                        <div class="md-ai-chips">
                            <?php foreach ($model['CAPABILITIES'] as $capability): ?>
                                <?= MDJUI::badge($capability === 'chat' ? 'Чат' : ($capability === 'tools' ? 'Tools' : 'Эмбеддинги'),
                                    $capability === 'embeddings' ? 'info' : 'primary') ?>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td><?= $model['HAS_TOKEN'] ? MDJUI::badge('задан', 'success') : MDJUI::badge('нет', 'neutral') ?></td>
                    <td><?= (int)$model['ENABLED'] === 1 ? MDJUI::badge('включена', 'success') : MDJUI::badge('выключена', 'neutral') ?></td>
                    <td class="text-end">
                        <button type="button" class="md-btn md-btn--secondary md-btn--sm" data-ai-probe
                                data-model-id="<?= (int)$model['ID'] ?>"
                                data-capability="<?= in_array('tools', $model['CAPABILITIES'], true) ? 'tools' : (in_array('chat', $model['CAPABILITIES'], true) ? 'chat' : 'embeddings') ?>">
                            <?= MDJUI::icon('plug-connected') ?> Проверить
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php return (string)ob_get_clean();
})()) ?>

<?= MDJUI::card(array('title' => 'Агенты', 'icon' => 'robot', 'flush' => true, 'compact' => true), (function () use ($agents, $profiles, $modelTitles, $e, $url) {    if ($agents === array()) return MDJUI::emptyState(array('icon' => 'robot', 'title' => 'Агентов нет', 'compact' => true));
    ob_start(); ?>
    <div class="md-table__scroll">
        <table class="md-table">
            <thead><tr><th>Агент</th><th>Профиль</th><th>Модель</th><th>Инструменты</th><th>Знания</th><th>Состояние</th></tr></thead>
            <tbody>
            <?php foreach ($agents as $agent): ?>
                <tr>
                    <td><a href="<?= $e($url('/agents/' . (int)$agent['ID'])) ?>"><?= $e($agent['TITLE']) ?></a></td>
                    <td><?= MDJUI::badge($profiles[$agent['PROFILE']] ?? 'Свой', $agent['PROFILE'] === 'custom' ? 'neutral' : 'info') ?></td>
                    <td><?= (int)$agent['MODEL_ID'] > 0 ? $e($modelTitles[(int)$agent['MODEL_ID']] ?? ('#' . (int)$agent['MODEL_ID'])) : MDJUI::badge('Не назначена', 'warning') ?></td>
                    <td><?= count($agent['TOOLS']) ?></td>
                    <td><?= MDJUI::badge($agent['KNOWLEDGE_MODE'] === 'rag' ? 'RAG' : 'Текст', $agent['KNOWLEDGE_MODE'] === 'rag' ? 'info' : 'outline') ?></td>
                    <td><?= (int)$agent['ENABLED'] === 1 ? MDJUI::badge('Включён', 'success') : MDJUI::badge('Выключен', 'neutral') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php return (string)ob_get_clean();
})()) ?>

<?= MDJUI::card(array('title' => 'Последние запуски', 'icon' => 'history', 'flush' => true, 'compact' => true), (function () use ($runs, $e) {
    if ($runs === array()) return MDJUI::emptyState(array('icon' => 'history', 'title' => 'Запусков ещё не было', 'compact' => true));
    ob_start(); ?>
    <div class="md-table__scroll">
        <table class="md-table">
            <thead><tr><th>Запуск</th><th>Статус</th><th>Ошибка</th><th>Обновлён</th></tr></thead>
            <tbody>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td><code><?= $e(substr((string)$run['ID'], 0, 12)) ?>…</code></td>
                    <td><?= MDJUI::badge((string)$run['STATUS'], $run['STATUS'] === 'done' ? 'success' : ($run['STATUS'] === 'error' ? 'danger' : 'neutral')) ?></td>
                    <td><?= $e(mb_substr((string)$run['ERROR'], 0, 160)) ?></td>
                    <td><?= (int)$run['UPDATED_AT'] > 0 ? date('d.m H:i:s', (int)$run['UPDATED_AT']) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php return (string)ob_get_clean();
})()) ?>
