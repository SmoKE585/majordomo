<?php
if (!isset($manager, $token) || !class_exists('MDJUI', false)) { http_response_code(404); return; }
$e = static fn($value) => MDJUI::e((string)($value ?? ''));
$url = static fn($path = '') => ROOTHTML . 'panel/modes' . $path . '.html';
$hidden = static function (array $fields) use ($e, $token): void {
    echo '<input type="hidden" name="_token" value="' . $e($token) . '">';
    foreach ($fields as $key => $value) echo '<input type="hidden" name="' . $e($key) . '" value="' . $e($value) . '">';
};
$card = static function (string $title, callable $body, string $subtitle = '', array $props = []): void {
    ob_start(); $body(); $slot = ob_get_clean();
    echo MDJUI::card($props + array('title' => $title, 'subtitle' => $subtitle), $slot);
};
$rulesOf = static function (array $mode): array {
    $rules = json_decode((string)($mode['RULES'] ?? ''), true);
    return is_array($rules) ? $rules : array();
};
$lastChange = static function (array $mode) use ($e): string {
    return (int)($mode['CHANGED_AT'] ?? 0) > 0 ? $e(date('d.m.Y H:i', (int)$mode['CHANGED_AT'])) : 'Ещё не переключался';
};
$sourceLabels = array('admin' => 'Панель', 'code' => 'PHP', 'cli' => 'Консоль',
    'rule:time' => 'Расписание', 'rule:event' => 'Событие', 'rule:condition' => 'Условия');
$modeIds = array_map('intval', array_column($all, 'ID'));
$lede = 'Ночной, Эко, Охрана — независимые состояния с расписанием, событиями и условиями.';
if ($editing && $record) {
    $text = trim((string)preg_replace('/\s+/u', ' ', (string)$record['DESCRIPTION']));
    if ($text !== '') {
        $short = preg_match('/^.{0,220}/us', $text, $match) ? $match[0] : $text;
        $text = $short . (strlen($short) < strlen($text) ? '…' : '');
    }
    $lede = $text !== '' ? $text : 'Код режима для вызовов из PHP: ' . $record['NAME'];
} elseif ($editing) {
    $lede = 'Новый режим: название, постоянный код и правила автоматики.';
}
$conditionView = static function (string $r, string $c, array $condition) use ($modeOptions): void {
    $prefix = 'rules[' . $r . '][conditions][' . $c . ']';
    $ident = 'md-mode-' . $r . '-' . $c;
    ?>
    <fieldset class="md-modes-condition" data-mode-condition>
        <legend class="visually-hidden">Дополнительное условие</legend>
        <div class="md-modes-condition__head">
            <?= MDJUI::select(array('name' => $prefix . '[type]', 'id' => $ident . '-type', 'label' => 'Проверить', 'options' => array('property' => 'Свойство объекта', 'mode' => 'Другой режим', 'time' => 'Время в интервале'), 'selected' => $condition['type'] ?? 'property', 'attrs' => array('data-condition-type' => ''))) ?>
            <?= MDJUI::iconButton('x', array('label' => 'Удалить условие', 'attrs' => array('data-remove-condition' => ''), 'variant' => 'ghost')) ?>
        </div>
        <div class="md-modes-condition__fields">
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
    </fieldset>
<?php };
$ruleView = static function (string $index, array $rule, int $number = 0) use ($conditionView): void {
    $prefix = 'rules[' . $index . ']'; $ident = 'md-mode-rule-' . $index;
    $days = array_map('intval', $rule['days'] ?? array(1, 2, 3, 4, 5, 6, 7));
    ?>
    <fieldset class="md-modes-rule" data-mode-rule>
        <legend class="visually-hidden">Правило переключения</legend>
        <div class="md-modes-rule__head">
            <h3 class="md-modes-rule__title">Правило <span data-rule-number><?= $number > 0 ? (int)$number : '' ?></span></h3>
            <?= MDJUI::iconButton('trash', array('label' => 'Удалить правило', 'variant' => 'danger', 'attrs' => array('data-remove-rule' => ''))) ?>
        </div>
        <div class="md-ui-form-grid">
            <?= MDJUI::select(array('name' => $prefix . '[target]', 'id' => $ident . '-target', 'label' => 'Действие', 'options' => array(1 => 'Включить режим', 0 => 'Выключить режим'), 'selected' => $rule['target'] ?? 1)) ?>
            <?= MDJUI::select(array('name' => $prefix . '[trigger]', 'id' => $ident . '-trigger', 'label' => 'Когда', 'options' => array('time' => 'В указанное время', 'event' => 'При событии', 'condition' => 'Когда выполнятся условия'), 'selected' => $rule['trigger'] ?? 'time', 'attrs' => array('data-rule-trigger' => ''))) ?>
        </div>
        <div data-trigger-fields="time" class="md-modes-schedule">
            <?= MDJUI::input(array('type' => 'time', 'name' => $prefix . '[at]', 'id' => $ident . '-at', 'label' => 'Время', 'value' => $rule['at'] ?? '23:00')) ?>
            <fieldset class="md-modes-days"><legend class="md-field__label">Дни недели</legend>
                <?php foreach (array(1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс') as $day => $label) echo MDJUI::checkbox(array('name' => $prefix . '[days][]', 'id' => $ident . '-day-' . $day, 'value' => $day, 'label' => $label, 'checked' => in_array($day, $days, true))); ?>
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
        <div class="md-modes-rule__actions"><?= MDJUI::button('Добавить условие', array('variant' => 'ghost', 'icon' => 'plus', 'size' => 'sm', 'attrs' => array('data-add-condition' => ''))) ?></div>
    </fieldset>
<?php };
?>
<link rel="stylesheet" href="<?= $e(ROOTHTML) ?>assets/modules/modes/modes.css?v=20261022-2">
<script src="<?= $e(ROOTHTML) ?>assets/modules/modes/modes.js?v=20261022-2" defer></script>
<div class="md-ui-page md-ui-page--stack md-modes-page" data-md-modes>
    <header class="md-ui-hero">
        <div>
            <div class="md-ui-hero__eyebrow"><?= MDJUI::icon('adjustments-horizontal') ?> Управление домом</div>
            <h1 class="md-ui-hero__title"><?= $e($editing ? ($record ? $record['TITLE'] : 'Новый режим') : 'Режимы') ?></h1>
            <p class="md-ui-hero__lede"><?= $e($lede) ?></p>
            <?php if ($editing && $record): ?>
            <div class="md-ui-hero__meta">
                <span class="md-ui-hero__meta-item"><?= MDJUI::icon('code') ?>Код: <code><?= $e($record['NAME']) ?></code></span>
                <span class="md-ui-hero__meta-item"><?= MDJUI::icon('clock') ?>Последнее переключение: <strong><?= $lastChange($record) ?></strong></span>
                <span class="md-ui-hero__meta-item"><?= MDJUI::icon('robot') ?><?= $record['ENABLED'] ? 'Автоматика включена' : 'Автоматика на паузе' ?></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="md-ui-hero__actions">
            <?= MDJUI::button($editing ? 'Все режимы' : 'Добавить режим', array('href' => $editing ? $url() : $url('/add'), 'icon' => $editing ? 'arrow-left' : 'plus', 'variant' => $editing ? 'secondary' : 'primary')) ?>
            <?php if ($editing && $record): ?>
            <form method="post" action="<?= $e($url('/' . $id)) ?>" class="md-modes-hero-form">
                <?php $hidden(array('operation' => 'delete', 'confirm_delete' => '1')); ?>
                <div class="dropdown">
                    <button type="button" class="md-btn md-btn--ghost" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="md-btn__label">Действия</span><i class="ti ti-chevron-down" aria-hidden="true"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end md-menu">
                        <?php if ($record['ACTIVE']): ?>
                        <li><span class="dropdown-item-text md-field__hint">Сначала выключите режим</span></li>
                        <li><button type="submit" class="dropdown-item is-danger" disabled><i class="ti ti-trash" aria-hidden="true"></i>Удалить режим</button></li>
                        <?php else: ?>
                        <li><button type="submit" class="dropdown-item is-danger" data-md-confirm="Удалить режим «<?= $e($record['TITLE']) ?>»? История переключений сохранится."><i class="ti ti-trash" aria-hidden="true"></i>Удалить режим</button></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </form>
            <?php endif; ?>
            <?= MDJUI::button('Инструкция', array('href' => ROOTHTML . 'panel/knowledge_base/user/modes.html', 'variant' => 'ghost', 'icon' => 'book')) ?>
        </div>
    </header>
    <?php if ($error !== '') echo MDJUI::alert('danger', 'Не удалось выполнить действие', $error); ?>
    <?php if ($success !== '') echo MDJUI::alert('success', 'Готово', $success); ?>
    <?php if (!$cycleEnabled) echo MDJUI::alert('warning', 'Цикл Modes остановлен или отключён', 'Расписание и задержки требуют cycle_states. Включите цикл в X-Ray или через консоль. Переключения из PHP доступны.'); ?>
    <?php if ($editing): ?>
        <?php if ($record): ?>
            <?php ob_start(); ?>
            <div class="md-modes-status__main">
                <?= MDJUI::status($record['ACTIVE'] ? 'online' : 'idle', $record['ACTIVE'] ? 'Режим включён' : 'Режим выключен') ?>
                <?= MDJUI::badge($record['ENABLED'] ? 'Автоматика включена' : 'Правила на паузе', $record['ENABLED'] ? 'primary' : 'neutral') ?>
                <span class="md-field__hint">Кнопка и PHP меняют состояние, не отключая автоматику.</span>
            </div>
            <form method="post" action="<?= $e($url('/' . $id)) ?>">
                <?php $hidden(array('operation' => 'set', 'active' => $record['ACTIVE'] ? 0 : 1)); ?>
                <?= MDJUI::button($record['ACTIVE'] ? 'Выключить сейчас' : 'Включить сейчас', array('type' => 'submit', 'variant' => 'secondary', 'icon' => 'power')) ?>
            </form>
            <?= MDJUI::card(array('compact' => true, 'class' => 'md-modes-status', 'body_class' => 'md-modes-status__body'), ob_get_clean()) ?>
            <?php if ($record['LAST_ERROR'] !== '') echo MDJUI::alert('danger', 'Ошибка последнего действия', $record['LAST_ERROR']); ?>
        <?php endif; ?>
        <form method="post" action="<?= $e($id ? $url('/' . $id) : $url('/add')) ?>" class="md-ui-form md-ui-form-stack">
            <?php $hidden(array('operation' => 'save')); ?>
            <?php $card('Основное', static function () use ($form, $id): void { ?>
                <div class="md-ui-form-stack">
                    <div class="md-ui-form-grid">
                        <?= MDJUI::input(array('name' => 'title', 'label' => 'Название', 'value' => $form['TITLE'], 'required' => true, 'attrs' => array('maxlength' => 190), 'placeholder' => 'Никого нет дома')) ?>
                        <?= MDJUI::input(array('name' => 'name', 'label' => 'Код для PHP', 'value' => $form['NAME'], 'required' => true, 'readonly' => $id > 0, 'attrs' => array('maxlength' => 100, 'pattern' => '[a-z][a-z0-9_]*'), 'placeholder' => 'away', 'hint' => 'Постоянный код режима для вызовов из других модулей.')) ?>
                    </div>
                    <?= MDJUI::textarea(array('name' => 'description', 'label' => 'Описание', 'value' => $form['DESCRIPTION'], 'rows' => 2, 'hint' => 'Показывается в списке и в заголовке режима.')) ?>
                    <?= MDJUI::switchControl(array('name' => 'enabled', 'label' => 'Автоматические правила включены', 'checked' => (bool)$form['ENABLED'], 'description' => 'Управление из PHP и кнопками доступно всегда. Оно не отключает автоматические правила.')) ?>
                </div>
            <?php }, 'Название, постоянный код и описание режима.'); ?>
            <?php $card('Автоматические правила', static function () use ($form, $ruleView): void { ?>
                <div class="md-ui-form-stack">
                    <div data-mode-rules class="md-ui-form-stack">
                        <?php $number = 0; foreach ($form['RULES'] as $index => $rule) $ruleView((string)$index, $rule, ++$number); ?>
                    </div>
                    <p class="md-field__hint" data-rules-empty>Без правил режим переключается кнопкой или из PHP. Добавьте отдельные правила включения и выключения.</p>
                    <div class="md-modes-rule__actions"><?= MDJUI::button('Добавить правило', array('variant' => 'secondary', 'icon' => 'plus', 'attrs' => array('data-add-rule' => ''))) ?></div>
                </div>
            <?php }, 'Каждое событие или новое выполнение условий срабатывает один раз. Внешняя команда сохраняется до следующего срабатывания правила.'); ?>
            <div class="md-modes-actions">
                <?php foreach (array('on' => 'При включении', 'off' => 'При выключении') as $direction => $label): $action = $form[strtoupper($direction) . '_ACTION']; ?>
                    <?php $card($label, static function () use ($direction, $action, $scripts): void { ?>
                        <div class="md-ui-form-stack" data-mode-action>
                            <?= MDJUI::select(array('name' => $direction . '_type', 'label' => 'Действие', 'options' => array('none' => 'Без действия', 'method' => 'Вызвать метод', 'script' => 'Выполнить сценарий', 'php' => 'Выполнить PHP-код'), 'selected' => $action['type'] ?? 'none', 'attrs' => array('data-action-type' => ''))) ?>
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
                    <?php }, $direction === 'on' ? 'Что выполнить после включения режима.' : 'Что выполнить после выключения режима.'); ?>
                <?php endforeach; ?>
            </div>
            <div class="md-ui-form-actions"><?= MDJUI::button('Сохранить', array('type' => 'submit', 'icon' => 'device-floppy')) ?><?= MDJUI::button('Отмена', array('href' => $url(), 'variant' => 'secondary')) ?></div>
        </form>
        <template data-rule-template><?php $ruleView('__R__', array()); ?></template>
        <template data-condition-template><?php $conditionView('__R__', '__C__', array()); ?></template>
        <?php if ($record): ?>
            <?= MDJUI::codeSample(array('language' => 'php', 'title' => 'Использовать в других модулях', 'code' => "if (isModeActive('" . $record['NAME'] . "')) {\n    // Режим включён\n}\nenableMode('" . $record['NAME'] . "');\ndisableMode('" . $record['NAME'] . "');", 'caption' => 'Вызовы сразу меняют состояние. Расписание и события продолжают работать.')) ?>
        <?php endif; ?>
    <?php else: ?>
        <?php $activeCount = count(array_filter($all, static fn($item) => (bool)$item['ACTIVE'])); ?>
        <?php $automatedCount = count(array_filter($all, static fn($item) => (bool)$item['ENABLED'] && count($rulesOf($item)) > 0)); ?>
        <div class="md-ui-kpi-grid md-ui-kpi-grid--compact">
            <?= MDJUI::kpi(array('label' => 'Всего режимов', 'value' => count($all), 'icon' => 'adjustments-horizontal')) ?>
            <?= MDJUI::kpi(array('label' => 'Включены сейчас', 'value' => $activeCount, 'icon' => 'power', 'tone' => 'success')) ?>
            <?= MDJUI::kpi(array('label' => 'С автоматикой', 'value' => $automatedCount, 'icon' => 'clock')) ?>
        </div>
        <?php if (!$all) echo MDJUI::emptyState(array('icon' => 'moon-stars', 'title' => 'Создайте первый режим', 'text' => 'Например, «Ночной» по расписанию или «Никого нет дома» по состоянию телефонов.', 'action' => MDJUI::button('Добавить режим', array('href' => $url('/add'), 'icon' => 'plus')))); ?>
        <div class="md-modes-grid">
            <?php foreach ($all as $mode): ?>
                <?php ob_start(); ?>
                <div class="md-modes-card__state">
                    <?= MDJUI::status($mode['ACTIVE'] ? 'online' : 'idle', $mode['ACTIVE'] ? 'Включён' : 'Выключен') ?>
                    <?= MDJUI::badge($mode['ENABLED'] ? 'Автоматика' : 'Правила на паузе', $mode['ENABLED'] ? 'primary' : 'neutral') ?>
                </div>
                <?php if ($mode['DESCRIPTION'] !== '') : ?><p class="md-field__hint"><?= $e($mode['DESCRIPTION']) ?></p><?php endif; ?>
                <?php if ($mode['LAST_ERROR'] !== '') echo MDJUI::alert('danger', 'Ошибка действия', $mode['LAST_ERROR']); ?>
                <div class="md-modes-card__actions">
                    <form method="post" action="<?= $e($url()) ?>"><?php $hidden(array('operation' => 'set', 'id' => $mode['ID'], 'active' => $mode['ACTIVE'] ? 0 : 1)); ?><?= MDJUI::button($mode['ACTIVE'] ? 'Выключить' : 'Включить', array('type' => 'submit', 'variant' => 'secondary', 'icon' => 'power')) ?></form>
                    <?= MDJUI::button('Настроить', array('href' => $url('/' . $mode['ID']), 'variant' => 'ghost', 'icon' => 'settings')) ?>
                </div>
                <?php $slot = ob_get_clean(); ?>
                <?php $ruleCount = count($rulesOf($mode)); ?>
                <?php echo MDJUI::card(array(
                    'title' => $mode['TITLE'],
                    'icon' => $mode['ACTIVE'] ? 'circle-check' : 'circle',
                    'body_class' => 'md-modes-card',
                    'footer' => '<span>Код: <code>' . $e($mode['NAME']) . '</code> · ' . $ruleCount . ' ' . MDJUI::plural($ruleCount, 'правило', 'правила', 'правил') . '</span><span>' . $lastChange($mode) . '</span>',
                ), $slot); ?>
            <?php endforeach; ?>
        </div>
        <?= MDJUI::hint(array('title' => 'Режимы работают вместе', 'text' => 'Для охраны через 30 минут отсутствия создайте правило «Когда выполнятся условия», добавьте режим «Никого нет дома: включён» и задержку 1800 секунд. Если кто-то вернётся раньше, ожидание отменится.')) ?>
    <?php endif; ?>
    <?php if ($history): ?>
        <?php $historyColumns = $editing ? array('Время', 'Состояние', 'Источник', 'Результат') : array('Время', 'Режим', 'Состояние', 'Источник', 'Результат'); ?>
        <?php $card('Последние переключения', static function () use ($history, $historyColumns, $sourceLabels, $editing, $modeIds): void { ?>
            <div class="md-table__scroll"><table class="md-table">
                <thead><tr><?php foreach ($historyColumns as $column): ?><th scope="col"><?= $column ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($history as $entry): ?>
                    <tr>
                        <td class="md-modes-history__time"><?= MDJUI::e(date('d.m.Y H:i:s', (int)$entry['ADDED'])) ?></td>
                        <?php if (!$editing): ?><td><?php if (in_array((int)$entry['MODE_ID'], $modeIds, true)): ?><a href="<?= MDJUI::e(ROOTHTML . 'panel/modes/' . (int)$entry['MODE_ID'] . '.html') ?>"><?= MDJUI::e($entry['TITLE']) ?></a><?php else: ?><?= MDJUI::e($entry['TITLE']) ?><?php endif; ?></td><?php endif; ?>
                        <td><?= MDJUI::badge($entry['ACTIVE'] ? 'Включён' : 'Выключен', $entry['ACTIVE'] ? 'success' : 'neutral') ?></td>
                        <td><?= MDJUI::badge($sourceLabels[$entry['SOURCE']] ?? ($entry['SOURCE'] !== '' ? $entry['SOURCE'] : '—'), 'outline', array('attrs' => array('title' => $entry['SOURCE']))) ?></td>
                        <td><?= $entry['ERROR'] === '' ? MDJUI::badge('Выполнено', 'success', array('dot' => true)) : '<span class="md-modes-history__error">' . MDJUI::e($entry['ERROR']) . '</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php }, $editing ? 'Журнал переходов этого режима, последние 100 записей.' : 'Журнал переходов всех режимов, последние 100 записей.', array('flush' => true)); ?>
    <?php endif; ?>
</div>
