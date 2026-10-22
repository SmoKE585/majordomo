<?php
if (!isset($manager, $token) || !class_exists('MDJUI', false)) { http_response_code(404); return; }
$e = static fn($value) => MDJUI::e((string)($value ?? ''));
$url = static fn($path = '') => ROOTHTML . 'panel/modes' . $path . '.html';
$hidden = static function (array $fields) use ($e, $token): void {
    echo '<input type="hidden" name="_token" value="' . $e($token) . '">';
    foreach ($fields as $key => $value) echo '<input type="hidden" name="' . $e($key) . '" value="' . $e($value) . '">';
};
$card = static function (string $title, callable $body, string $subtitle = ''): void {
    ob_start(); $body(); $slot = ob_get_clean();
    echo MDJUI::card(array('title' => $title, 'subtitle' => $subtitle), $slot);
};
$conditionView = static function (string $r, string $c, array $condition) use ($modeOptions, $e): void {
    $prefix = 'rules[' . $r . '][conditions][' . $c . ']';
    $ident = 'md-mode-' . $r . '-' . $c;
    ?>
    <fieldset class="md-modes-condition" data-mode-condition>
        <legend class="visually-hidden">Дополнительное условие</legend>
        <div class="md-modes-condition__fields">
            <?= MDJUI::select(array('name' => $prefix . '[type]', 'id' => $ident . '-type', 'label' => 'Проверить', 'options' => array('property' => 'Свойство объекта', 'mode' => 'Другой режим', 'time' => 'Время в интервале'), 'selected' => $condition['type'] ?? 'property', 'attrs' => array('data-condition-type' => ''))) ?>
            <div data-condition-fields="property" class="md-modes-condition__values">
                <?= MDJUI::input(array('name' => $prefix . '[property]', 'id' => $ident . '-property', 'label' => 'Объект.свойство', 'value' => $condition['property'] ?? '', 'placeholder' => 'ThisComputer.SomebodyHome')) ?>
                <?= MDJUI::select(array('name' => $prefix . '[operator]', 'id' => $ident . '-operator', 'label' => 'Сравнение', 'options' => array('eq' => 'Равно', 'ne' => 'Не равно', 'gt' => 'Больше', 'gte' => 'Не меньше', 'lt' => 'Меньше', 'lte' => 'Не больше'), 'selected' => $condition['operator'] ?? 'eq')) ?>
                <?= MDJUI::input(array('name' => $prefix . '[value]', 'id' => $ident . '-value', 'label' => 'Значение', 'value' => $condition['value'] ?? '0')) ?>
            </div>
            <div data-condition-fields="mode" class="md-modes-condition__values">
                <?= MDJUI::select(array('name' => $prefix . '[mode]', 'id' => $ident . '-mode', 'label' => 'Режим', 'options' => $modeOptions, 'selected' => $condition['mode'] ?? '')) ?>
                <?= MDJUI::select(array('name' => $prefix . '[state]', 'id' => $ident . '-state', 'label' => 'Состояние', 'options' => array(1 => 'Включён', 0 => 'Выключен'), 'selected' => $condition['state'] ?? 1)) ?>
            </div>
            <div data-condition-fields="time" class="md-modes-condition__values">
                <?= MDJUI::input(array('type' => 'time', 'name' => $prefix . '[start]', 'id' => $ident . '-start', 'label' => 'С', 'value' => $condition['start'] ?? '23:00')) ?>
                <?= MDJUI::input(array('type' => 'time', 'name' => $prefix . '[end]', 'id' => $ident . '-end', 'label' => 'До', 'value' => $condition['end'] ?? '07:00')) ?>
            </div>
        </div>
        <?= MDJUI::iconButton('x', array('label' => 'Удалить условие', 'attrs' => array('data-remove-condition' => ''), 'variant' => 'ghost')) ?>
    </fieldset>
<?php };
$ruleView = static function (string $index, array $rule) use ($conditionView, $e): void {
    $prefix = 'rules[' . $index . ']'; $ident = 'md-mode-rule-' . $index;
    ?>
    <fieldset class="md-modes-rule" data-mode-rule>
        <legend class="visually-hidden">Правило переключения</legend>
        <div class="md-modes-rule__head"><h3 class="md-modes-rule__title">Правило <span data-rule-number></span></h3><?= MDJUI::iconButton('trash', array('label' => 'Удалить правило', 'variant' => 'danger', 'attrs' => array('data-remove-rule' => ''))) ?></div>
        <div class="md-ui-form-grid">
            <?= MDJUI::select(array('name' => $prefix . '[target]', 'id' => $ident . '-target', 'label' => 'Действие', 'options' => array(1 => 'Включить режим', 0 => 'Выключить режим'), 'selected' => $rule['target'] ?? 1)) ?>
            <?= MDJUI::select(array('name' => $prefix . '[trigger]', 'id' => $ident . '-trigger', 'label' => 'Когда', 'options' => array('time' => 'В указанное время', 'event' => 'При событии', 'condition' => 'Когда выполнятся условия'), 'selected' => $rule['trigger'] ?? 'time', 'attrs' => array('data-rule-trigger' => ''))) ?>
        </div>
        <div data-trigger-fields="time" class="md-modes-schedule">
            <?= MDJUI::input(array('type' => 'time', 'name' => $prefix . '[at]', 'id' => $ident . '-at', 'label' => 'Время', 'value' => $rule['at'] ?? '23:00')) ?>
            <fieldset class="md-modes-days"><legend class="md-field__label">Дни недели</legend>
                <?php foreach (array(1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс') as $day => $label) echo MDJUI::checkbox(array('name' => $prefix . '[days][]', 'id' => $ident . '-day-' . $day, 'value' => $day, 'label' => $label, 'checked' => in_array($day, array_map('intval', $rule['days'] ?? array(1,2,3,4,5,6,7)), true))); ?>
            </fieldset>
        </div>
        <div data-trigger-fields="event"><?= MDJUI::input(array('name' => $prefix . '[event]', 'id' => $ident . '-event', 'label' => 'Имя события MajorDoMo', 'value' => $rule['event'] ?? '', 'placeholder' => 'NOBODY_HOME', 'hint' => 'События processSubscriptions() и системной шины. PHP-код здесь не нужен.')) ?></div>
        <div class="md-ui-form-grid">
            <?= MDJUI::select(array('name' => $prefix . '[match]', 'id' => $ident . '-match', 'label' => 'Условия', 'options' => array('all' => 'Выполнены все', 'any' => 'Выполнено хотя бы одно'), 'selected' => $rule['match'] ?? 'all')) ?>
            <?= MDJUI::input(array('type' => 'number', 'name' => $prefix . '[delay]', 'id' => $ident . '-delay', 'label' => 'Задержка, секунд', 'value' => $rule['delay'] ?? 0, 'min' => 0, 'max' => 604800, 'hint' => '0 — сразу. 1800 — через 30 минут. При нарушении условий ожидание отменяется.')) ?>
        </div>
        <div class="md-ui-form-stack" data-mode-conditions>
            <?php foreach (($rule['conditions'] ?? array()) as $c => $condition) $conditionView($index, (string)$c, $condition); ?>
        </div>
        <?= MDJUI::button('Добавить условие', array('variant' => 'ghost', 'icon' => 'plus', 'size' => 'sm', 'attrs' => array('data-add-condition' => ''))) ?>
    </fieldset>
<?php };
?>
<link rel="stylesheet" href="<?= $e(ROOTHTML) ?>assets/modules/modes/modes.css?v=20261022-1">
<script src="<?= $e(ROOTHTML) ?>assets/modules/modes/modes.js?v=20261022-1" defer></script>
<div class="md-ui-page md-ui-page--stack md-modes-page" data-md-modes>
    <header class="md-ui-hero">
        <div>
            <div class="md-ui-hero__eyebrow"><?= MDJUI::icon('adjustments-horizontal') ?> Управление домом</div>
            <h1 class="md-ui-hero__title"><?= $e($editing ? ($record ? $record['TITLE'] : 'Новый режим') : 'Режимы') ?></h1>
            <p class="md-ui-hero__lede">Ночной, Эко, Охрана — независимые состояния с расписанием, событиями и условиями.</p>
        </div>
        <div class="md-ui-hero__actions">
            <?= MDJUI::button($editing ? 'Все режимы' : 'Добавить режим', array('href' => $editing ? $url() : $url('/add'), 'icon' => $editing ? 'arrow-left' : 'plus', 'variant' => $editing ? 'secondary' : 'primary')) ?>
            <?= MDJUI::button('Инструкция', array('href' => ROOTHTML . 'panel/knowledge_base/user/modes.html', 'variant' => 'ghost', 'icon' => 'book')) ?>
        </div>
    </header>
    <?php if ($error !== '') echo MDJUI::alert('danger', 'Не удалось выполнить действие', $error); ?>
    <?php if ($success !== '') echo MDJUI::alert('success', 'Готово', $success); ?>
    <?php if (!$cycleEnabled) echo MDJUI::alert('warning', 'Цикл Modes остановлен или отключён', 'Расписание и задержки требуют cycle_states. Включите цикл в X-Ray или через консоль. Переключения из PHP доступны.'); ?>
    <?php if ($editing): ?>
        <?php if ($record): ?>
            <div class="md-modes-statebar">
                <?= MDJUI::status($record['ACTIVE'] ? 'online' : 'idle', $record['ACTIVE'] ? 'Режим включён' : 'Режим выключен') ?>
                <span class="md-field__hint">Автоматика <?= $record['ENABLED'] ? 'включена' : 'приостановлена' ?></span>
                <form method="post" action="<?= $e($url('/' . $id)) ?>"><?php $hidden(array('operation' => 'set', 'active' => $record['ACTIVE'] ? 0 : 1)); ?><?= MDJUI::button($record['ACTIVE'] ? 'Выключить сейчас' : 'Включить сейчас', array('type' => 'submit', 'variant' => 'secondary', 'icon' => 'power')) ?></form>
            </div>
            <?php if ($record['LAST_ERROR'] !== '') echo MDJUI::alert('danger', 'Ошибка последнего действия', $record['LAST_ERROR']); ?>
        <?php endif; ?>
        <form method="post" action="<?= $e($id ? $url('/' . $id) : $url('/add')) ?>" class="md-ui-form md-ui-form-stack" data-mode-form>
            <?php $hidden(array('operation' => 'save')); ?>
            <?php $card('Основное', static function () use ($form, $id): void { ?>
                <div class="md-ui-form-grid">
                    <?= MDJUI::input(array('name' => 'title', 'label' => 'Название', 'value' => $form['TITLE'], 'required' => true, 'attrs' => array('maxlength' => 190), 'placeholder' => 'Никого нет дома')) ?>
                    <?= MDJUI::input(array('name' => 'name', 'label' => 'Код для PHP', 'value' => $form['NAME'], 'required' => true, 'readonly' => $id > 0, 'attrs' => array('maxlength' => 100, 'pattern' => '[a-z][a-z0-9_]*'), 'placeholder' => 'away', 'hint' => 'Постоянный код режима для вызовов из других модулей.')) ?>
                </div>
                <?= MDJUI::textarea(array('name' => 'description', 'label' => 'Описание', 'value' => $form['DESCRIPTION'], 'rows' => 2)) ?>
                <?= MDJUI::switchControl(array('name' => 'enabled', 'label' => 'Автоматические правила включены', 'checked' => (bool)$form['ENABLED'], 'description' => 'Управление из PHP и кнопками доступно всегда. Оно не отключает автоматические правила.')) ?>
            <?php }); ?>
            <?php $card('Автоматические правила', static function () use ($form, $ruleView): void { ?>
                <div data-mode-rules class="md-ui-form-stack">
                    <?php foreach ($form['RULES'] as $index => $rule) $ruleView((string)$index, $rule); ?>
                </div>
                <p class="md-field__hint" data-rules-empty>Без правил режим переключается кнопкой или из PHP. Добавьте отдельные правила включения и выключения.</p>
                <?= MDJUI::button('Добавить правило', array('variant' => 'secondary', 'icon' => 'plus', 'attrs' => array('data-add-rule' => ''))) ?>
            <?php }, 'Каждое событие или новое выполнение условий срабатывает один раз. Внешняя команда сохраняется до следующего срабатывания правила.'); ?>
            <?php foreach (array('on' => 'При включении', 'off' => 'При выключении') as $direction => $label): $action = $form[strtoupper($direction) . '_ACTION']; ?>
                <?php $card($label, static function () use ($direction, $action, $scripts): void { ?>
                    <div data-mode-action>
                        <?= MDJUI::select(array('name' => $direction . '_type', 'label' => 'Действие при переходе', 'options' => array('none' => 'Без действия', 'method' => 'Вызвать метод', 'script' => 'Выполнить сценарий', 'php' => 'Выполнить PHP-код'), 'selected' => $action['type'] ?? 'none', 'attrs' => array('data-action-type' => ''))) ?>
                        <div data-action-fields="method"><?= MDJUI::input(array('name' => $direction . '_value', 'label' => 'Объект.метод', 'value' => ($action['type'] ?? '') === 'method' ? $action['value'] : '', 'placeholder' => 'Alarm.arm')) ?></div>
                        <div data-action-fields="script"><?= MDJUI::select(array('name' => $direction . '_value', 'label' => 'Сценарий', 'options' => array('' => 'Выберите сценарий') + array_column($scripts, 'TITLE', 'ID'), 'selected' => ($action['type'] ?? '') === 'script' ? $action['value'] : '')) ?></div>
                        <div data-action-fields="php">
                            <label class="md-field__label" for="md-mode-<?= $direction ?>-code">PHP-код без тегов</label>
                            <div class="md-code-editor" data-code-editor data-code-editor-language="php" data-code-editor-autosave="0" data-code-editor-autofocus="0" data-code-editor-min-lines="8">
                                <div class="md-code-editor__toolbar" data-code-editor-toolbar><div class="md-code-editor__toolbar-left"><span class="md-code-editor__badge">PHP</span></div><div class="md-code-editor__toolbar-right" data-code-editor-actions></div></div>
                                <textarea class="md-textarea" id="md-mode-<?= $direction ?>-code" name="<?= $direction ?>_value" rows="8" spellcheck="false"><?= MDJUI::e(($action['type'] ?? '') === 'php' ? $action['value'] : '') ?></textarea>
                            </div>
                            <p class="md-field__hint">Доступны функции MajorDoMo и $mode: name, active, previous, source, details. Повторная установка того же состояния действие не запускает.</p>
                        </div>
                    </div>
                <?php }); ?>
            <?php endforeach; ?>
            <div class="md-ui-form-actions"><?= MDJUI::button('Сохранить', array('type' => 'submit', 'icon' => 'device-floppy')) ?><?= MDJUI::button('Отмена', array('href' => $url(), 'variant' => 'secondary')) ?></div>
        </form>
        <template data-rule-template><?php $ruleView('__R__', array()); ?></template>
        <template data-condition-template><?php $conditionView('__R__', '__C__', array()); ?></template>
        <?php if ($record): ?>
            <?= MDJUI::codeSample(array('language' => 'php', 'title' => 'Использовать в других модулях', 'code' => "if (isModeActive('" . $record['NAME'] . "')) {\n    // Режим включён\n}\nenableMode('" . $record['NAME'] . "');\ndisableMode('" . $record['NAME'] . "');", 'caption' => 'Вызовы сразу меняют состояние. Расписание и события продолжают работать.')) ?>
            <details class="md-modes-delete"><summary>Удаление режима</summary><p class="md-field__hint">Выключите режим и удалите ссылки на него из условий других режимов.</p><form method="post" action="<?= $e($url('/' . $id)) ?>" data-mode-delete><?php $hidden(array('operation' => 'delete')); ?><?= MDJUI::button('Удалить режим', array('type' => 'submit', 'variant' => 'danger', 'icon' => 'trash')) ?></form></details>
        <?php endif; ?>
    <?php else: ?>
        <div class="md-ui-widget-grid">
            <?= MDJUI::kpi(array('label' => 'Всего режимов', 'value' => count($all), 'icon' => 'adjustments-horizontal')) ?>
            <?= MDJUI::kpi(array('label' => 'Включены сейчас', 'value' => count(array_filter($all, static fn($item) => (bool)$item['ACTIVE'])), 'icon' => 'power', 'tone' => 'success')) ?>
            <?= MDJUI::kpi(array('label' => 'С автоматикой', 'value' => count(array_filter($all, static fn($item) => (bool)$item['ENABLED'] && $item['RULES'] !== '[]')), 'icon' => 'clock')) ?>
        </div>
        <?php if (!$all) echo MDJUI::emptyState(array('icon' => 'moon-stars', 'title' => 'Создайте первый режим', 'text' => 'Например, «Ночной» по расписанию или «Никого нет дома» по состоянию телефонов.', 'action' => MDJUI::button('Добавить режим', array('href' => $url('/add'), 'icon' => 'plus')))); ?>
        <div class="md-modes-grid">
            <?php foreach ($all as $mode): ob_start(); ?>
                <div class="md-modes-statebar"><?= MDJUI::status($mode['ACTIVE'] ? 'online' : 'idle', $mode['ACTIVE'] ? 'Включён' : 'Выключен') ?><?= MDJUI::badge($mode['ENABLED'] ? 'Автоматика' : 'Правила на паузе', $mode['ENABLED'] ? 'primary' : 'neutral') ?></div>
                <p class="md-field__hint"><?= $e($mode['DESCRIPTION'] ?: 'Код: ' . $mode['NAME']) ?></p>
                <?php $ruleCount = count(json_decode($mode['RULES'], true, 64, JSON_THROW_ON_ERROR)); ?>
                <p class="md-field__hint">Правил: <?= $ruleCount ?> · <?= $mode['CHANGED_AT'] ? $e(date('d.m.Y H:i:s', (int)$mode['CHANGED_AT'])) : 'Ещё не переключался' ?></p>
                <?php if ($mode['LAST_ERROR']) echo MDJUI::alert('danger', 'Ошибка действия', $mode['LAST_ERROR']); ?>
                <div class="md-modes-card__actions">
                    <form method="post" action="<?= $e($url()) ?>"><?php $hidden(array('operation' => 'set', 'id' => $mode['ID'], 'active' => $mode['ACTIVE'] ? 0 : 1)); ?><?= MDJUI::button($mode['ACTIVE'] ? 'Выключить' : 'Включить', array('type' => 'submit', 'variant' => 'secondary', 'icon' => 'power')) ?></form>
                    <?= MDJUI::button('Настроить', array('href' => $url('/' . $mode['ID']), 'variant' => 'ghost', 'icon' => 'settings')) ?>
                </div>
            <?php $slot = ob_get_clean(); echo MDJUI::card(array('title' => $mode['TITLE'], 'icon' => $mode['ACTIVE'] ? 'circle-check' : 'circle'), $slot); endforeach; ?>
        </div>
        <?= MDJUI::hint(array('title' => 'Режимы работают вместе', 'text' => 'Для охраны через 30 минут отсутствия создайте правило «Когда выполнятся условия», добавьте режим «Никого нет дома: включён» и задержку 1800 секунд. Если кто-то вернётся раньше, ожидание отменится.')) ?>
    <?php endif; ?>
    <?php if ($history): $card('Последние переключения', static function () use ($history, $e): void { ?>
        <div class="md-table__scroll"><table class="md-table"><thead><tr><th>Время</th><th>Режим</th><th>Состояние</th><th>Источник</th><th>Результат</th></tr></thead><tbody>
            <?php foreach ($history as $entry): ?><tr><td><?= $e(date('d.m.Y H:i:s', (int)$entry['ADDED'])) ?></td><td><?= $e($entry['TITLE']) ?></td><td><?= MDJUI::badge($entry['ACTIVE'] ? 'Включён' : 'Выключен', $entry['ACTIVE'] ? 'success' : 'neutral') ?></td><td><code><?= $e($entry['SOURCE']) ?></code></td><td><?= $entry['ERROR'] === '' ? 'Выполнено' : $e($entry['ERROR']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php }); endif; ?>
</div>
