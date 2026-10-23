<?php
// 2026-10-23 — общие поля назначения для создания и редактирования webhook.
if (!isset($hookPrefix, $modules, $handlerOptions, $scriptOptions) || !class_exists('MDJUI', false)) { http_response_code(404); return; }
$hookTargetEscape = static fn($value) => MDJUI::e((string)$value);
?>
<div class="md-ui-form-stack" x-data="{ handlerType: 'module' }" data-hook-target-ui>
    <?= MDJUI::select(array('id' => $hookPrefix . '-type', 'name' => 'handler_type', 'label' => 'Тип обработчика', 'selected' => 'module',
        'options' => array('module' => 'Модуль', 'script' => 'Сценарий', 'code' => 'PHP-код'), 'required' => true,
        'attrs' => array('x-model' => 'handlerType', 'data-hook-type-select' => ''))) ?>
    <fieldset class="md-api-hook-target md-ui-form-stack" x-show="handlerType === 'module'" :disabled="handlerType !== 'module'" data-hook-target="module">
        <legend class="visually-hidden">Обработчик модуля</legend>
        <?= MDJUI::select(array('id' => $hookPrefix . '-module', 'name' => 'module', 'label' => 'Установленный модуль', 'options' => $modules,
            'placeholder' => 'Выберите модуль', 'required' => true, 'attrs' => array('data-hook-module-select' => ''))) ?>
        <?= MDJUI::field(array('label' => 'PHP-обработчик', 'for' => $hookPrefix . '-handler', 'description' => 'PHP-файл внутри выбранного модуля.'),
            '<select class="md-select" id="' . $hookTargetEscape($hookPrefix) . '-handler" name="handler" required data-hook-handler-select><option value="">Сначала выберите модуль</option>'
            . $renderHookHandlerOptions($handlerOptions) . '</select>') ?>
    </fieldset>
    <fieldset class="md-api-hook-target md-ui-form-stack" x-show="handlerType === 'script'" :disabled="handlerType !== 'script'" x-cloak data-hook-target="script">
        <legend class="visually-hidden">Готовый сценарий</legend>
        <?= MDJUI::select(array('id' => $hookPrefix . '-script', 'name' => 'script_id', 'label' => 'Сценарий', 'options' => $scriptOptions,
            'placeholder' => 'Выберите сценарий', 'required' => true, 'searchable' => true,
            'hint' => $scriptOptions === array() ? 'Сначала создайте сценарий в разделе «Сценарии».' : 'Запускается актуальный код выбранного сценария.')) ?>
    </fieldset>
    <fieldset class="md-api-hook-target md-ui-form-stack" x-show="handlerType === 'code'" :disabled="handlerType !== 'code'" x-cloak data-hook-target="code">
        <legend class="visually-hidden">Собственный PHP-код</legend>
        <label class="md-field__label" for="<?= $hookTargetEscape($hookPrefix) ?>-code">PHP-код</label>
        <div class="md-code-editor" data-code-editor data-code-editor-lazy="1" data-code-editor-mode="php" data-code-editor-theme="auto"
             data-code-editor-autosave="0" data-code-editor-min-lines="6" data-code-editor-max-lines="22" data-code-editor-autofocus="0">
            <div class="md-code-editor__toolbar" data-code-editor-toolbar>
                <span class="md-code-editor__lang-badge">PHP</span>
                <div class="md-code-editor__toolbar-spacer"></div>
                <button type="button" class="md-code-editor__icon-btn" data-code-editor-action="search" title="Найти (Ctrl+F)" aria-label="Найти в коде"><?= MDJUI::icon('search') ?></button>
                <button type="button" class="md-code-editor__icon-btn" data-code-editor-action="fullscreen" title="Полный экран (F11)" aria-label="Развернуть редактор"><?= MDJUI::icon('maximize') ?></button>
            </div>
            <?= MDJUI::textarea(array('id' => $hookPrefix . '-code', 'name' => 'code', 'rows' => 8,
                'attrs' => array('spellcheck' => 'false', 'aria-required' => 'true', 'aria-describedby' => $hookPrefix . '-code-help'))) ?>
        </div>
        <div class="md-field__hint" id="<?= $hookTargetEscape($hookPrefix) ?>-code-help">Без тегов &lt;?php. Штатные функции MajorDoMo доступны. Код сохраняется только кнопкой формы.</div>
    </fieldset>
    <div class="md-field__hint">Для сценария и своего кода: return массива — JSON, echo — HTML. При наличии echo возвращается только HTML. Запрос доступен через $_GET, $_POST, $_FILES и php://input.</div>
</div>
