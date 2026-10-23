<?php
// 2026-10-23 — форма агента и его разрешений.
$isNew = $agent === null;
$record = $agent ?? array('ID' => 0, 'TITLE' => '', 'PROFILE' => 'custom', 'MODEL_ID' => 0,
    'INSTRUCTIONS' => '', 'TOOLS' => array(), 'SOURCES' => array('core', 'modules'),
    'KNOWLEDGE_MODE' => 'text', 'MAX_STEPS' => 12, 'TIMEOUT' => 120, 'ENABLED' => 1, 'REVISION' => 1);
$action = $isNew ? $url('/agents/add') : $url('/agents/' . (int)$record['ID']);
?>
<div class="md-ai-agent-edit">
    <?php ob_start(); ?>
    <form method="post" action="<?= $e($action) ?>" class="md-ui-form md-ui-form-stack">
        <input type="hidden" name="_token" value="<?= $e($token) ?>">
        <input type="hidden" name="operation" value="save_agent">
        <input type="hidden" name="id" value="<?= (int)$record['ID'] ?>">
        <input type="hidden" name="revision" value="<?= (int)$record['REVISION'] ?>">
        <div class="md-ui-form-grid">
            <?= MDJUI::input(array('name' => 'title', 'label' => 'Название', 'value' => $record['TITLE'], 'required' => true,
                'placeholder' => 'Например, Помощник по освещению')) ?>
            <?= MDJUI::select(array('name' => 'model_id', 'label' => 'Модель', 'selected' => (string)$record['MODEL_ID'],
                'options' => $modelOptions, 'searchable' => true,
                'description' => 'Для Tools у модели должна быть включена эта возможность.')) ?>
        </div>
        <?php if (empty($modelsAvailable)): ?>
            <?= MDJUI::alert('info', 'Нет доступных моделей',
                'Агент может работать без модели, но чат и помощь в редакторе включатся только после её назначения.',
                array('actions' => MDJUI::button('Добавить модель', array('href' => $url('/models/add'), 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'plus')))) ?>
        <?php endif; ?>
        <?= MDJUI::textarea(array('name' => 'instructions', 'label' => 'Системные инструкции', 'value' => $record['INSTRUCTIONS'],
            'rows' => 16, 'required' => true, 'class' => 'md-ai-instructions',
            'description' => 'Опишите роль, порядок поиска фактов и стиль ответа. Инструкции не расширяют серверные разрешения.')) ?>
        <?php if (!$isNew && $record['PROFILE'] !== 'custom'): ?>
            <div class="md-ai-inline-note">
                <?= MDJUI::badge('Стандартный профиль', 'info') ?>
                <span>Версия инструкций: <?= (int)$record['BASE_VERSION'] ?>. Восстановление не меняет выбранную модель.</span>
            </div>
        <?php endif; ?>

        <fieldset class="md-ai-fieldset">
            <legend>Инструменты</legend>
            <div class="md-ai-checks md-ai-checks--wide">
                <?php foreach ($allTools as $tool => $label): ?>
                    <?= MDJUI::checkbox(array('name' => 'tools[]', 'value' => $tool, 'label' => $label,
                        'description' => $tool, 'checked' => in_array($tool, $record['TOOLS'], true))) ?>
                <?php endforeach; ?>
            </div>
            <p class="md-ai-muted">Инструменты только читают установку. Произвольный SQL, shell и запуск кода модели недоступны.</p>
        </fieldset>

        <fieldset class="md-ai-fieldset">
            <legend>Источники знаний</legend>
            <div class="md-ai-checks">
                <?= MDJUI::checkbox(array('name' => 'sources[]', 'value' => 'core', 'label' => 'Документация ядра',
                    'checked' => in_array('core', $record['SOURCES'], true))) ?>
                <?= MDJUI::checkbox(array('name' => 'sources[]', 'value' => 'modules', 'label' => 'Документация установленных модулей',
                    'checked' => in_array('modules', $record['SOURCES'], true))) ?>
            </div>
        </fieldset>

        <?= MDJUI::choiceGroup(array('name' => 'knowledge_mode', 'aria_label' => 'Режим знаний',
            'selected' => $record['KNOWLEDGE_MODE'],
            'options' => array(
                array('value' => 'text', 'label' => 'Текстовый поиск', 'description' => 'Работает без Qdrant, ищет по Markdown.', 'icon' => 'file-search'),
                array('value' => 'rag', 'label' => 'RAG через Qdrant', 'description' => 'Смысловой поиск по готовому индексу.', 'icon' => 'database'),
            ))) ?>
        <div class="md-ai-rag-status">
            <?= MDJUI::badge(!empty($ragStatus['available']) ? 'RAG доступен' : 'RAG недоступен',
                !empty($ragStatus['available']) ? 'success' : 'warning', array('dot' => true)) ?>
            <span><?= $e($ragStatus['hint'] ?? '') ?></span>
        </div>

        <div class="md-ui-form-grid">
            <?= MDJUI::input(array('name' => 'max_steps', 'type' => 'number', 'label' => 'Лимит шагов', 'value' => $record['MAX_STEPS'],
                'min' => 0, 'required' => true, 'description' => '0 — без ограничения. Считаются вызовы инструментов; анализ и вывод ответа не расходуют шаги.')) ?>
            <?= MDJUI::input(array('name' => 'timeout', 'type' => 'number', 'label' => 'Таймаут, секунд', 'value' => $record['TIMEOUT'],
                'min' => 1, 'max' => 600, 'required' => true)) ?>
        </div>
        <?= MDJUI::switchControl(array('name' => 'enabled', 'label' => 'Агент включён', 'value' => '1',
            'checked' => (int)$record['ENABLED'] === 1)) ?>
        <div class="md-ui-form-actions">
            <?= MDJUI::button('Сохранить', array('type' => 'submit', 'icon' => 'device-floppy')) ?>
            <?= MDJUI::button('Отмена', array('href' => $url('/agents'), 'variant' => 'secondary')) ?>
        </div>
    </form>
    <?php $form = ob_get_clean(); ?>
    <?= MDJUI::card(array('title' => $isNew ? 'Новый агент' : 'Агент: ' . $record['TITLE'],
        'icon' => 'robot', 'subtitle' => 'Форма проверяется на сервере; неизвестные разрешения отклоняются.'), $form) ?>

    <?php if (!$isNew && $record['PROFILE'] !== 'custom'): ?>
        <?php ob_start(); ?>
        <form method="post" action="<?= $e($action) ?>" class="md-ui-form">
            <input type="hidden" name="_token" value="<?= $e($token) ?>">
            <input type="hidden" name="operation" value="restore_instructions">
            <input type="hidden" name="id" value="<?= (int)$record['ID'] ?>">
            <input type="hidden" name="revision" value="<?= (int)$record['REVISION'] ?>">
            <p class="md-ai-muted">Заменить пользовательский текст инструкций стандартным для профиля? Модель и остальные настройки сохранятся.</p>
            <?= MDJUI::button('Восстановить стандартные инструкции', array('type' => 'submit', 'variant' => 'outline', 'icon' => 'restore',
                'attrs' => array('data-md-confirm' => 'Заменить инструкции стандартными? Пользовательский текст будет потерян.'))) ?>
        </form>
        <?php $restore = ob_get_clean(); ?>
        <?= MDJUI::card(array('title' => 'Стандартные инструкции', 'icon' => 'restore', 'compact' => true), $restore) ?>
    <?php endif; ?>

    <?php if (!$isNew): ?>
        <div class="md-ai-split">
            <?php ob_start(); ?>
            <form method="post" action="<?= $e($action) ?>" class="md-ui-form">
                <input type="hidden" name="_token" value="<?= $e($token) ?>">
                <input type="hidden" name="operation" value="copy_agent">
                <input type="hidden" name="id" value="<?= (int)$record['ID'] ?>">
                <?= MDJUI::input(array('name' => 'title', 'label' => 'Название копии', 'value' => $record['TITLE'] . ' (копия)', 'required' => true)) ?>
                <?= MDJUI::button('Скопировать агента', array('type' => 'submit', 'variant' => 'secondary', 'icon' => 'copy')) ?>
            </form>
            <?php $copy = ob_get_clean(); ?>
            <?= MDJUI::card(array('title' => 'Копия', 'icon' => 'copy', 'compact' => true), $copy) ?>

            <?php ob_start(); ?>
            <form method="post" action="<?= $e($action) ?>" class="md-ui-form">
                <input type="hidden" name="_token" value="<?= $e($token) ?>">
                <input type="hidden" name="operation" value="delete_agent">
                <input type="hidden" name="id" value="<?= (int)$record['ID'] ?>">
                <input type="hidden" name="revision" value="<?= (int)$record['REVISION'] ?>">
                <input type="hidden" name="confirm_delete" value="1">
                <p class="md-ai-muted">Удалённый агент исчезает из выбора; история диалогов сохраняется.</p>
                <?= MDJUI::button('Удалить агента', array('type' => 'submit', 'variant' => 'danger', 'icon' => 'trash',
                    'attrs' => array('data-md-confirm' => 'Удалить агента?'))) ?>
            </form>
            <?php $danger = ob_get_clean(); ?>
            <?= MDJUI::card(array('title' => 'Удаление', 'icon' => 'alert-triangle', 'compact' => true), $danger) ?>
        </div>
    <?php endif; ?>
</div>
