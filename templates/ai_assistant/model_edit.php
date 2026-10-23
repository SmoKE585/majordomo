<?php
// 2026-10-23 — форма модели: возможности, токен, параметры и лимиты.
$isNew = $model === null;
$record = $model ?? array('ID' => 0, 'PROVIDER_ID' => array_key_first($providerOptions), 'TITLE' => '', 'MODEL' => '',
    'CAPABILITIES' => array('chat'), 'PARAMETERS' => array(), 'CONTEXT_WINDOW' => 32768, 'MAX_OUTPUT' => 4096,
    'ENABLED' => 1, 'EMBEDDING_PRIORITY' => 100, 'HAS_TOKEN' => false);
$action = $isNew ? $url('/models/add') : $url('/models/' . (int)$record['ID']);
$parameters = is_array($record['PARAMETERS']) ? $record['PARAMETERS'] : array();
?>
<div class="md-ai-narrow">
    <?php if (empty($providersAvailable)): ?>
        <?= MDJUI::card(array('title' => 'Новая модель', 'icon' => 'cpu',
            'subtitle' => 'Модель создаётся внутри провайдера.'), MDJUI::emptyState(array(
            'icon' => 'server',
            'title' => 'Сначала добавьте провайдера',
            'text' => 'Укажите адрес OpenAI-совместимого API, затем вернитесь сюда.',
            'action' => MDJUI::button('Добавить провайдера', array('href' => $url('/providers/add'), 'icon' => 'plus')),
        ))) ?>
    <?php else: ?>
    <?php ob_start(); ?>
    <form method="post" action="<?= $e($action) ?>" class="md-ui-form md-ui-form-stack" autocomplete="off">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <input type="hidden" name="operation" value="save_model">
        <input type="hidden" name="id" value="<?= (int)$record['ID'] ?>">
        <div class="md-ui-form-grid">
            <?= MDJUI::input(array('name' => 'title', 'label' => 'Название', 'value' => $record['TITLE'], 'required' => true,
                'placeholder' => 'Например, GPT-4o')) ?>
            <?= MDJUI::select(array('name' => 'provider_id', 'label' => 'Провайдер', 'selected' => (string)$record['PROVIDER_ID'],
                'options' => $providerOptions, 'required' => true, 'placeholder' => 'Выберите провайдера')) ?>
        </div>
        <?= MDJUI::input(array('name' => 'model', 'label' => 'Идентификатор модели', 'value' => $record['MODEL'], 'required' => true,
            'placeholder' => 'gpt-4o-mini', 'description' => 'Точное имя, которое принимает API. Не название в интерфейсе провайдера.')) ?>
        <?= MDJUI::input(array('name' => 'token', 'type' => 'password', 'label' => 'API-токен',
            'description' => $record['HAS_TOKEN'] ? 'Токен уже сохранён. Оставьте поле пустым, чтобы не менять его.' : 'Необязателен для локальных серверов без авторизации.',
            'password_toggle' => true, 'autocomplete' => 'new-password')) ?>
        <?php if ($record['HAS_TOKEN']): ?>
            <?= MDJUI::checkbox(array('name' => 'clear_token', 'value' => '1', 'label' => 'Очистить сохранённый токен')) ?>
        <?php endif; ?>

        <fieldset class="md-ai-fieldset">
            <legend>Возможности</legend>
            <div class="md-ai-checks">
                <?php foreach (array('chat' => 'Чат', 'tools' => 'Вызов инструментов', 'embeddings' => 'Эмбеддинги') as $key => $label): ?>
                    <?= MDJUI::checkbox(array('name' => 'capabilities[]', 'value' => $key, 'label' => $label,
                        'checked' => in_array($key, $record['CAPABILITIES'], true))) ?>
                <?php endforeach; ?>
            </div>
        </fieldset>

        <div class="md-ui-form-grid">
            <?= MDJUI::input(array('name' => 'context_window', 'type' => 'number', 'label' => 'Контекст, токенов',
                'value' => $record['CONTEXT_WINDOW'], 'min' => 256, 'required' => true)) ?>
            <?= MDJUI::input(array('name' => 'max_output', 'type' => 'number', 'label' => 'Максимум ответа, токенов',
                'value' => $record['MAX_OUTPUT'], 'min' => 1, 'required' => true)) ?>
            <?= MDJUI::input(array('name' => 'temperature', 'label' => 'temperature', 'value' => $parameters['temperature'] ?? '',
                'placeholder' => 'по умолчанию', 'description' => '0–2. Пусто — значение провайдера.')) ?>
            <?= MDJUI::input(array('name' => 'top_p', 'label' => 'top_p', 'value' => $parameters['top_p'] ?? '',
                'placeholder' => 'по умолчанию', 'description' => '0–1. Пусто — значение провайдера.')) ?>
        </div>
        <?= MDJUI::input(array('name' => 'embedding_priority', 'type' => 'number', 'label' => 'Приоритет эмбеддингов',
            'value' => $record['EMBEDDING_PRIORITY'], 'min' => 1, 'max' => 10000,
            'description' => 'Меньше — раньше. Нужен только моделям с возможностью «Эмбеддинги».')) ?>
        <?= MDJUI::switchControl(array('name' => 'enabled', 'label' => 'Модель включена', 'value' => '1',
            'checked' => (int)$record['ENABLED'] === 1)) ?>
        <div class="md-ui-form-actions">
            <?= MDJUI::button('Сохранить', array('type' => 'submit', 'icon' => 'device-floppy')) ?>
            <?= MDJUI::button('Отмена', array('href' => $url('/models'), 'variant' => 'secondary')) ?>
            <?php if (!$isNew): ?>
                <?= MDJUI::button('Проверить соединение', array('type' => 'button', 'variant' => 'secondary', 'icon' => 'plug-connected',
                    'attrs' => array('data-ai-probe' => '', 'data-model-id' => (int)$record['ID'],
                        'data-capability' => in_array('tools', $record['CAPABILITIES'], true) ? 'tools' : (in_array('chat', $record['CAPABILITIES'], true) ? 'chat' : 'embeddings')))) ?>
            <?php endif; ?>
        </div>
        <div class="md-ai-probe-result" data-ai-probe-result hidden></div>
    </form>
    <?php $form = ob_get_clean(); ?>
    <?= MDJUI::card(array('title' => $isNew ? 'Новая модель' : 'Модель: ' . $record['TITLE'],
        'icon' => 'cpu', 'subtitle' => 'Возможности проверяются при назначении агенту и индексатору.'), $form) ?>
    <?php endif; ?>

    <?php if (!$isNew): ?>
        <?php ob_start(); ?>
        <form method="post" action="<?= $e($action) ?>" class="md-ui-form">
            <input type="hidden" name="_token" value="<?= $e($token) ?>">
            <input type="hidden" name="operation" value="delete_model">
            <input type="hidden" name="id" value="<?= (int)$record['ID'] ?>">
            <input type="hidden" name="confirm_delete" value="1">
            <p class="md-ai-muted">Модель, назначенная агенту или индексу знаний, не удаляется. Сначала измените их настройки.</p>
            <?= MDJUI::button('Удалить модель', array('type' => 'submit', 'variant' => 'danger', 'icon' => 'trash',
                'attrs' => array('data-md-confirm' => 'Удалить модель и её токен?'))) ?>
        </form>
        <?php $danger = ob_get_clean(); ?>
        <?= MDJUI::card(array('title' => 'Удаление', 'icon' => 'alert-triangle', 'compact' => true), $danger) ?>
    <?php endif; ?>
</div>
