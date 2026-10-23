<?php
// 2026-10-23 — подключение Qdrant и управление индексом знаний.
$hasModel = false;
foreach ($embeddingModels as $model) if ((int)$model['ID'] === (int)$settings['EMBEDDING_MODEL_ID']) $hasModel = true;
$reasonLabels = array('not_configured' => 'не подключён', 'embeddings_missing' => 'нет модели эмбеддингов',
    'authentication' => 'ключ отклонён', 'timeout' => 'таймаут', 'network' => 'нет связи',
    'invalid_response' => 'неверный ответ', 'redirect' => 'перенаправление');
$ragReason = (string)($ragStatus['reason'] ?? '');
$statusTone = !empty($ragStatus['available']) ? 'success' : ($ragReason === 'not_configured' ? 'info' : 'warning');
?>
<div class="md-ai-knowledge">
    <div class="md-ui-kpi-grid">
        <?= MDJUI::kpi(array('label' => 'Документов в базе', 'value' => $documents['documents'], 'icon' => 'book-2',
            'tone' => 'primary', 'meta' => 'docs/** и модули установки')) ?>
        <?= MDJUI::kpi(array('label' => 'Моделей эмбеддингов', 'value' => count($embeddingModels), 'icon' => 'vector',
            'tone' => count($embeddingModels) > 0 ? 'success' : 'warning')) ?>
        <?= MDJUI::kpi(array('label' => 'Готовых индексов', 'value' => count(array_filter($indexes, static fn(array $row): bool => $row['STATUS'] === 'ready')),
            'icon' => 'database', 'tone' => 'info')) ?>
        <?= MDJUI::kpi(array('label' => 'Qdrant', 'value' => !empty($ragStatus['available']) ? 'Доступен' : 'Нет',
            'icon' => 'plug-connected', 'tone' => $statusTone,
            'meta' => $reasonLabels[$ragReason] ?? $ragReason)) ?>
    </div>

    <?= MDJUI::alert($statusTone, !empty($ragStatus['available']) ? 'RAG доступен' : ($ragReason === 'not_configured' ? 'Qdrant не подключён' : 'RAG недоступен'),
        (string)($ragStatus['hint'] ?? 'Qdrant не обязателен: обычные агенты используют текстовый поиск.'),
        array('actions' => MDJUI::button('Проверить снова', array('type' => 'button', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'refresh',
            'attrs' => array('data-ai-rag-probe' => ''))) . MDJUI::button('К моделям', array('href' => $url('/models'), 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'cpu')))) ?>

    <div class="md-ai-split">
        <?php ob_start(); ?>
        <form method="post" action="<?= $e($url('/knowledge')) ?>" class="md-ui-form md-ui-form-stack" autocomplete="off">
            <input type="hidden" name="_token" value="<?= $e($token) ?>">
            <input type="hidden" name="operation" value="save_qdrant">
            <?= MDJUI::input(array('name' => 'qdrant_url', 'type' => 'url', 'label' => 'Адрес API Qdrant',
                'value' => $settings['QDRANT_URL'], 'placeholder' => 'http://127.0.0.1:6333',
                'description' => 'Пусто — Qdrant отключён. Коллекции создаются автоматически.')) ?>
            <?= MDJUI::input(array('name' => 'token', 'type' => 'password', 'label' => 'API-ключ Qdrant',
                'description' => !empty($settings['HAS_TOKEN']) ? 'Ключ сохранён. Пустое поле не меняет его; при смене адреса ключ нужно задать заново.' : 'Необязателен для локального Qdrant.',
                'password_toggle' => true)) ?>
            <?= MDJUI::input(array('name' => 'timeout', 'type' => 'number', 'label' => 'Таймаут, секунд',
                'value' => $settings['TIMEOUT'], 'min' => 1, 'max' => 30, 'required' => true)) ?>
            <div class="md-ui-form-actions">
                <?= MDJUI::button('Сохранить подключение', array('type' => 'submit', 'icon' => 'device-floppy')) ?>
            </div>
        </form>
        <?php $qdrantForm = ob_get_clean(); ?>
        <?= MDJUI::card(array('title' => 'Подключение Qdrant', 'icon' => 'database', 'compact' => true,
            'subtitle' => 'Отдельный сервис; в стандартную поставку не входит.'), $qdrantForm) ?>

        <?php if ($embeddingModels === array()): ?>
            <?= MDJUI::card(array('title' => 'Параметры индекса', 'icon' => 'adjustments-horizontal', 'compact' => true,
                'subtitle' => 'Изменение модели или разбиения требует новой сборки.'), MDJUI::emptyState(array(
                'icon' => 'vector',
                'title' => 'Сначала добавьте модель эмбеддингов',
                'text' => 'Создайте модель с возможностью «Эмбеддинги» и приоритетом, затем выберите её здесь.',
                'action' => MDJUI::button('Добавить модель', array('href' => $url('/models/add'), 'icon' => 'plus')),
                'compact' => true,
            ))) ?>
        <?php else: ?>
        <?php ob_start(); ?>
        <form method="post" action="<?= $e($url('/knowledge')) ?>" class="md-ui-form md-ui-form-stack">
            <input type="hidden" name="_token" value="<?= $e($token) ?>">
            <input type="hidden" name="operation" value="save_index_settings">
            <?php
            $options = array('' => 'Выберите модель эмбеддингов');
            foreach ($embeddingModels as $model) {
                $options[(string)$model['ID']] = $model['TITLE'] . ' · ' . $model['MODEL'] . ' · приоритет ' . (int)$model['EMBEDDING_PRIORITY'];
            }
            ?>
            <?= MDJUI::select(array('name' => 'embedding_model_id', 'label' => 'Модель эмбеддингов', 'selected' => (string)$settings['EMBEDDING_MODEL_ID'],
                'options' => $options, 'searchable' => true,
                'description' => 'Модель должна быть включена и иметь возможность «Эмбеддинги».')) ?>
            <div class="md-ui-form-grid">
                <?= MDJUI::input(array('name' => 'chunk_size', 'type' => 'number', 'label' => 'Размер фрагмента, символов',
                    'value' => $settings['CHUNK_SIZE'], 'min' => 300, 'max' => 4000, 'required' => true)) ?>
                <?= MDJUI::input(array('name' => 'chunk_overlap', 'type' => 'number', 'label' => 'Перекрытие, символов',
                    'value' => $settings['CHUNK_OVERLAP'], 'min' => 0, 'max' => 800, 'required' => true)) ?>
                <?= MDJUI::input(array('name' => 'top_k', 'type' => 'number', 'label' => 'Фрагментов в ответе',
                    'value' => $settings['TOP_K'], 'min' => 1, 'max' => 20, 'required' => true)) ?>
                <?= MDJUI::input(array('name' => 'max_context', 'type' => 'number', 'label' => 'Предел контекста, символов',
                    'value' => $settings['MAX_CONTEXT'], 'min' => 1000, 'max' => 60000, 'required' => true)) ?>
            </div>
            <div class="md-ui-form-actions">
                <?= MDJUI::button('Сохранить параметры', array('type' => 'submit', 'icon' => 'device-floppy')) ?>
            </div>
        </form>
        <?php $indexForm = ob_get_clean(); ?>
        <?= MDJUI::card(array('title' => 'Параметры индекса', 'icon' => 'adjustments-horizontal', 'compact' => true,
            'subtitle' => 'Изменение модели или разбиения требует новой сборки.'), $indexForm) ?>
        <?php endif; ?>
    </div>

    <?= MDJUI::card(array(
        'title' => 'Индексы',
        'subtitle' => 'Новый индекс собирается в отдельной коллекции; рабочий переключается только после успеха.',
        'icon' => 'vector',
        'flush' => true,
    ), (function () use ($indexes, $embeddingModels, $settings, $url, $e, $token) {
        if ($embeddingModels === array()) {
            return MDJUI::emptyState(array('icon' => 'vector', 'title' => 'Нет модели эмбеддингов',
                'text' => 'Создайте модель с возможностью «Эмбеддинги» и отметьте её в параметрах индекса.',
                'action' => MDJUI::button('Перейти к моделям', array('href' => $url('/models/add'), 'icon' => 'plus'))));
        }
        ob_start(); ?>
        <div class="md-ai-index-list" data-ai-index-list>
            <?php if ($indexes === array()): ?>
                <div class="md-ai-index-empty"><?= MDJUI::emptyState(array('icon' => 'database', 'title' => 'Индекс ещё не собран',
                    'text' => 'Выберите модель и запустите сборку. Это можно сделать повторно после изменения документации.',
                    'compact' => true)) ?></div>
            <?php endif; ?>
            <?php foreach ($indexes as $row): ?>
                <article class="md-ai-index" data-ai-index="<?= (int)$row['MODEL_ID'] ?>" data-model-id="<?= (int)$row['MODEL_ID'] ?>"
                         data-status="<?= $e($row['STATUS']) ?>">
                    <div class="md-ai-index__head">
                        <div>
                            <strong><?= $e($row['MODEL_TITLE']) ?></strong>
                            <div class="md-ai-muted">
                                Коллекция <code><?= $e($row['COLLECTION']) ?></code> · размерность <?= (int)$row['DIMENSION'] ?>
                            </div>
                        </div>
                        <div class="md-ai-index__badges">
                            <?= MDJUI::badge(match ($row['STATUS']) {
                                'ready' => 'Готов', 'building' => 'Сборка', 'error' => 'Ошибка', 'cancelled' => 'Отменён', default => 'Пусто',
                            }, match ($row['STATUS']) {
                                'ready' => 'success', 'building' => 'info', 'error' => 'danger', 'cancelled' => 'warning', default => 'neutral',
                            }, array('dot' => true)) ?>
                            <?php if ((int)$row['ACTIVE'] === 1 && $row['STATUS'] === 'ready'): ?><?= MDJUI::badge('Рабочий', 'primary') ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="md-ai-index__stats">
                        <span>Документов: <strong><?= (int)$row['DOCUMENTS'] ?></strong></span>
                        <span>Фрагментов: <strong><?= (int)$row['CHUNKS'] ?></strong></span>
                        <span>Обработано: <strong data-ai-index-processed><?= (int)$row['PROCESSED'] ?></strong></span>
                    </div>
                    <?php $percent = (int)$row['CHUNKS'] > 0 ? min(100, (int)round((int)$row['PROCESSED'] / (int)$row['CHUNKS'] * 100)) : 0; ?>
                    <?= MDJUI::progress($percent, array('size' => 'sm', 'tone' => $row['STATUS'] === 'error' ? 'danger' : 'primary',
                        'label' => 'Прогресс сборки', 'show_value' => true, 'attrs' => array('data-ai-index-progress' => ''))) ?>
                    <?php if ($row['ERROR'] !== ''): ?>
                        <?= MDJUI::alert('danger', 'Сборка не завершена', $row['ERROR']) ?>
                    <?php endif; ?>
                    <div class="md-ai-index__actions">
                        <?php if ($row['STATUS'] === 'building'): ?>
                            <?= MDJUI::button('Остановить', array('type' => 'button', 'variant' => 'secondary', 'size' => 'sm',
                                'icon' => 'player-stop', 'attrs' => array('data-ai-index-cancel' => (int)$row['MODEL_ID']))) ?>
                        <?php else: ?>
                            <?= MDJUI::button($row['STATUS'] === 'ready' ? 'Пересобрать' : 'Собрать', array('type' => 'button', 'size' => 'sm',
                                'icon' => 'refresh', 'attrs' => array('data-ai-index-start' => (int)$row['MODEL_ID']))) ?>
                        <?php endif; ?>
                        <?php if ((int)$row['ACTIVE'] !== 1 && $row['STATUS'] !== 'building'): ?>
                            <form method="post" action="<?= $e($url('/knowledge')) ?>" class="md-ai-inline-form">
                                <input type="hidden" name="_token" value="<?= $e($token) ?>">
                                <input type="hidden" name="operation" value="delete_index">
                                <input type="hidden" name="model_id" value="<?= (int)$row['MODEL_ID'] ?>">
                                <?= MDJUI::button('Удалить', array('type' => 'submit', 'variant' => 'ghost', 'size' => 'sm',
                                    'icon' => 'trash', 'attrs' => array('data-md-confirm' => 'Удалить индекс и его коллекцию?'))) ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="md-ai-muted md-ai-index-foot">
            <?= MDJUI::icon('info-circle') ?>
            Индексация выполняется порциями из админки. Закрытие страницы не ломает готовый индекс; сборку можно запустить заново.
        </div>
        <?php return (string)ob_get_clean();
    })()) ?>
</div>
