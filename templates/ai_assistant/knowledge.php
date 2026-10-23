<?php
// 2026-10-23 — подключение Qdrant, вектор и управление индексом знаний.
$reasonLabels = array('not_configured' => 'не подключён', 'embeddings_missing' => 'нет модели эмбеддингов',
    'authentication' => 'ключ отклонён', 'timeout' => 'таймаут', 'network' => 'нет связи',
    'invalid_response' => 'неверный ответ', 'redirect' => 'перенаправление');
$ragReason = (string)($ragStatus['reason'] ?? '');
$statusTone = !empty($ragStatus['available']) ? 'success' : ($ragReason === 'not_configured' ? 'info' : 'warning');
$embeddingIds = array_map(static fn(array $model): int => (int)$model['ID'], $embeddingModels);
$selectedModelId = (int)$settings['EMBEDDING_MODEL_ID'];
if (!in_array($selectedModelId, $embeddingIds, true)) $selectedModelId = $embeddingIds[0] ?? 0;
$hasSelectedModel = in_array($selectedModelId, $embeddingIds, true);
$canIndex = $hasSelectedModel && !empty($ragStatus['available']);
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
        array('actions' => MDJUI::button('Индексировать базу', array('type' => 'button', 'variant' => 'secondary', 'size' => 'sm',
            'icon' => 'database-plus', 'disabled' => !$canIndex,
            'attrs' => array('data-ai-index-start' => (string)$selectedModelId)))
            . MDJUI::button('Проверить снова', array('type' => 'button', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'refresh',
            'attrs' => array('data-ai-rag-probe' => ''))) . MDJUI::button('К моделям', array('href' => $url('/models'), 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'cpu')))) ?>

    <?php
    $modelOptions = array('' => 'Выберите модель эмбеддингов');
    foreach ($embeddingModels as $model) {
        $modelOptions[(string)$model['ID']] = $model['TITLE'] . ' · ' . $model['MODEL'] . ' · приоритет ' . (int)$model['EMBEDDING_PRIORITY'];
    }
    ob_start();
    ?>
    <form method="post" action="<?= $e($url('/knowledge')) ?>" class="md-ui-form md-ui-form-stack" autocomplete="off">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <input type="hidden" name="operation" value="save_knowledge">
        <div class="md-ai-form-section">
            <h3>Подключение</h3>
            <p>Qdrant — один сервис. «Инстансы» и отдельные коллекции вручную создавать не нужно: рабочая коллекция появится при индексации.</p>
        </div>
        <?= MDJUI::input(array('name' => 'qdrant_url', 'type' => 'url', 'label' => 'Адрес API Qdrant',
            'value' => $settings['QDRANT_URL'], 'placeholder' => 'http://127.0.0.1:6333',
            'description' => 'Пусто — Qdrant отключён. Коллекция создаётся автоматически при первой сборке.')) ?>
        <div class="md-ui-form-grid">
            <?= MDJUI::input(array('name' => 'token', 'type' => 'password', 'label' => 'API-ключ Qdrant',
                'description' => !empty($settings['HAS_TOKEN']) ? 'Ключ сохранён. Пустое поле не меняет его.' : 'Необязателен для локального Qdrant.',
                'password_toggle' => true)) ?>
            <?= MDJUI::input(array('name' => 'timeout', 'type' => 'number', 'label' => 'Таймаут, секунд',
                'value' => $settings['TIMEOUT'], 'min' => 1, 'max' => 30, 'required' => true)) ?>
        </div>
        <?php if (!empty($settings['HAS_TOKEN'])): ?>
            <?= MDJUI::checkbox(array('name' => 'clear_token', 'value' => '1', 'label' => 'Очистить сохранённый ключ Qdrant')) ?>
        <?php endif; ?>

        <div class="md-ai-form-section">
            <h3>Модель и коллекция</h3>
            <p>Для OpenAI <code>text-embedding-3-large</code>: размерность 3072, Cosine, HNSW m=16, ef_construct=100. Имя вектора можно оставить пустым — тогда используется безымянный вектор Qdrant.</p>
        </div>
        <?= MDJUI::select(array('name' => 'embedding_model_id', 'label' => 'Модель эмбеддингов', 'selected' => (string)$selectedModelId,
            'options' => $modelOptions, 'searchable' => true,
            'description' => 'Модель должна быть включена и иметь возможность «Эмбеддинги».')) ?>
        <div class="md-ui-form-grid">
            <?= MDJUI::input(array('name' => 'vector_name', 'label' => 'Имя вектора', 'value' => $settings['VECTOR_NAME'],
                'placeholder' => 'default (безымянный)', 'maxlength' => 64,
                'description' => 'Пусто — стандартный безымянный вектор. Нужно только для нескольких векторов в коллекции.')) ?>
            <?= MDJUI::input(array('name' => 'vector_size', 'type' => 'number', 'label' => 'Размерность вектора',
                'value' => $settings['VECTOR_SIZE'], 'min' => 0, 'max' => 65536, 'required' => true,
                'description' => '0 — определить по ответу модели. Для text-embedding-3-large — 3072.')) ?>
            <?= MDJUI::select(array('name' => 'distance', 'label' => 'Метрика', 'selected' => (string)$settings['DISTANCE'],
                'options' => array('Cosine' => 'Cosine', 'Dot' => 'Dot', 'Euclid' => 'Euclid', 'Manhattan' => 'Manhattan'),
                'description' => 'Для OpenAI-эмбеддингов используется Cosine.')) ?>
            <?= MDJUI::input(array('name' => 'hnsw_m', 'type' => 'number', 'label' => 'HNSW m',
                'value' => $settings['HNSW_M'], 'min' => 4, 'max' => 128, 'required' => true,
                'description' => 'Число связей на узел. Стандарт Qdrant — 16.')) ?>
            <?= MDJUI::input(array('name' => 'hnsw_ef_construct', 'type' => 'number', 'label' => 'HNSW ef_construct',
                'value' => $settings['HNSW_EF_CONSTRUCT'], 'min' => 4, 'max' => 1024, 'required' => true,
                'description' => 'Глубина построения графа. Стандарт Qdrant — 100.')) ?>
        </div>
        <div class="md-ai-form-section">
            <h3>Разбиение и выдача</h3>
        </div>
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
            <?= MDJUI::button('Сохранить всё', array('type' => 'submit', 'icon' => 'device-floppy')) ?>
            <?= MDJUI::button('Параметры OpenAI Large', array('type' => 'button', 'variant' => 'secondary', 'icon' => 'wand',
                'attrs' => array('data-ai-vector-preset' => ''))) ?>
        </div>
    </form>
    <?php $knowledgeForm = ob_get_clean(); ?>
    <?= MDJUI::card(array('title' => 'Qdrant и индекс знаний', 'icon' => 'database', 'compact' => true,
        'subtitle' => 'Одна форма сохраняет подключение, модель и параметры коллекции.'), $knowledgeForm) ?>

    <?= MDJUI::card(array(
        'title' => 'Индексы',
        'subtitle' => 'Новый индекс собирается в отдельной коллекции; рабочий переключается только после успеха.',
        'icon' => 'vector',
        'flush' => true,
        'actions' => MDJUI::button('Индексировать базу', array('type' => 'button', 'size' => 'sm', 'icon' => 'database-plus',
            'disabled' => !$canIndex, 'attrs' => array('data-ai-index-start' => (string)$selectedModelId))),
    ), (function () use ($indexes, $embeddingModels, $settings, $selectedModelId, $canIndex, $url, $e, $token) {
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
                    'action' => $canIndex ? MDJUI::button('Индексировать базу', array('type' => 'button', 'icon' => 'database-plus',
                        'attrs' => array('data-ai-index-start' => (string)$selectedModelId))) : '',
                    'compact' => true)) ?></div>
            <?php endif; ?>
            <?php foreach ($indexes as $row): ?>
                <article class="md-ai-index" data-ai-index="<?= (int)$row['MODEL_ID'] ?>" data-model-id="<?= (int)$row['MODEL_ID'] ?>"
                         data-status="<?= $e($row['STATUS']) ?>">
                    <div class="md-ai-index__head">
                        <div>
                            <strong><?= $e($row['MODEL_TITLE']) ?></strong>
                            <?php $vectorName = (string)($row['VECTOR_NAME'] ?? ''); ?>
                            <div class="md-ai-muted">
                                Коллекция <code><?= $e($row['COLLECTION']) ?></code> · размерность <?= (int)$row['DIMENSION'] ?>
                                · вектор <?= $e($vectorName !== '' ? $vectorName : 'default') ?>
                                · <?= $e((string)($row['DISTANCE'] ?? 'Cosine')) ?>
                                · HNSW <?= (int)($row['HNSW_M'] ?? 16) ?>/<?= (int)($row['HNSW_EF_CONSTRUCT'] ?? 100) ?>
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
