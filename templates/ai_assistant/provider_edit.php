<?php
// 2026-10-23 — форма провайдера.
$isNew = $provider === null;
$record = $provider ?? array('ID' => 0, 'TITLE' => '', 'BASE_URL' => '', 'PROTOCOL' => 'openai_chat', 'TIMEOUT' => 60, 'ENABLED' => 1);
$action = $isNew ? $url('/providers/add') : $url('/providers/' . (int)$record['ID']);
?>
<div class="md-ai-narrow">
    <?php ob_start(); ?>
    <form method="post" action="<?= $e($action) ?>" class="md-ui-form md-ui-form-stack">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <input type="hidden" name="operation" value="save_provider">
        <input type="hidden" name="id" value="<?= (int)$record['ID'] ?>">
        <?= MDJUI::input(array('name' => 'title', 'label' => 'Название', 'value' => $record['TITLE'],
            'required' => true, 'placeholder' => 'Например, OpenAI')) ?>
        <?= MDJUI::input(array('name' => 'base_url', 'type' => 'url', 'label' => 'Базовый URL API', 'value' => $record['BASE_URL'],
            'required' => true, 'placeholder' => 'https://api.openai.com/v1',
            'description' => 'Без /chat/completions. Локальные адреса разрешены; перенаправления запрещены.')) ?>
        <?= MDJUI::select(array('name' => 'protocol', 'label' => 'Протокол', 'selected' => $record['PROTOCOL'],
            'options' => array('openai_chat' => 'OpenAI Chat Completions'))) ?>
        <?= MDJUI::input(array('name' => 'timeout', 'type' => 'number', 'label' => 'Таймаут, секунд', 'value' => $record['TIMEOUT'],
            'min' => 1, 'max' => 600, 'required' => true)) ?>
        <?= MDJUI::switchControl(array('name' => 'enabled', 'label' => 'Провайдер включён', 'value' => '1',
            'checked' => (int)$record['ENABLED'] === 1)) ?>
        <div class="md-ui-form-actions">
            <?= MDJUI::button('Сохранить', array('type' => 'submit', 'icon' => 'device-floppy')) ?>
            <?= MDJUI::button('Отмена', array('href' => $url('/providers'), 'variant' => 'secondary')) ?>
        </div>
    </form>
    <?php $form = ob_get_clean(); ?>
    <?= MDJUI::card(array('title' => $isNew ? 'Новый провайдер' : 'Провайдер: ' . $record['TITLE'],
        'icon' => 'server', 'subtitle' => 'Адрес и таймаут применяются ко всем моделям провайдера.'), $form) ?>

    <?php if (!$isNew): ?>
        <?php ob_start(); ?>
        <form method="post" action="<?= $e($action) ?>" class="md-ui-form">
            <input type="hidden" name="_token" value="<?= $e($token) ?>">
            <input type="hidden" name="operation" value="delete_provider">
            <input type="hidden" name="id" value="<?= (int)$record['ID'] ?>">
            <input type="hidden" name="confirm_delete" value="1">
            <p class="md-ai-muted">Удалить можно только провайдера без моделей. Сначала перенесите или удалите модели.</p>
            <?= MDJUI::button('Удалить провайдера', array('type' => 'submit', 'variant' => 'danger', 'icon' => 'trash',
                'attrs' => array('data-md-confirm' => 'Удалить провайдера и его настройки?'))) ?>
        </form>
        <?php $danger = ob_get_clean(); ?>
        <?= MDJUI::card(array('title' => 'Удаление', 'icon' => 'alert-triangle', 'compact' => true), $danger) ?>
    <?php endif; ?>
</div>
