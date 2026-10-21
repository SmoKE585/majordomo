<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Render a real linkedobject module instance for the UI Kit.
 *
 * The module is intentionally used here instead of copied markup: the demo
 * exercises the same template, AJAX endpoint and legacy mounting code as old
 * modules do.
 *
 * @param string $field
 * @param string $property
 * @param string $method
 * @param string $value
 * @return string
 */
if (!function_exists('md_ui_render_linkedobject')) {
    function md_ui_render_linkedobject($field, $property, $method, $value = '')
    {
        require_once(ROOT . 'modules/linkedobject/linkedobject.class.php');

    $module = new linkedobject();
    $module->object_field = $field;
    if ($property !== '') {
        $module->property_field = $property;
    }
    if ($method !== '') {
        $module->method_field = $method;
    }
    $module->width = '100%';

    $previous_uniq = array_key_exists('uniq', $GLOBALS) ? $GLOBALS['uniq'] : null;
    $previous_value = array_key_exists($field, $GLOBALS) ? $GLOBALS[$field] : null;
    $previous_property = $property !== '' && array_key_exists($property, $GLOBALS) ? $GLOBALS[$property] : null;
    $previous_method = $method !== '' && array_key_exists($method, $GLOBALS) ? $GLOBALS[$method] : null;

    $GLOBALS['uniq'] = 'ui_kit_' . preg_replace('/[^A-Za-z0-9_]/', '_', $field);
    $GLOBALS[$field] = $value;
    if ($property !== '') {
        $GLOBALS[$property] = '';
    }
    if ($method !== '') {
        $GLOBALS[$method] = '';
    }

    $module->run();
    $result = $module->result;

    if ($previous_value === null) {
        unset($GLOBALS[$field]);
    } else {
        $GLOBALS[$field] = $previous_value;
    }
    if ($property !== '') {
        if ($previous_property === null) {
            unset($GLOBALS[$property]);
        } else {
            $GLOBALS[$property] = $previous_property;
        }
    }
    if ($method !== '') {
        if ($previous_method === null) {
            unset($GLOBALS[$method]);
        } else {
            $GLOBALS[$method] = $previous_method;
        }
    }
    if ($previous_uniq === null) {
        unset($GLOBALS['uniq']);
    } else {
        $GLOBALS['uniq'] = $previous_uniq;
    }

    return $result;
    }
}

$linkedobject_demo_value = '';
require_once(ROOT . 'modules/linkedobject/linkedobject.class.php');
$linkedobject_demo_module = new linkedobject();
$linkedobject_demo_objects = $linkedobject_demo_module->getAvailableObjects();
$linkedobject_demo_titles = array();
foreach ($linkedobject_demo_objects as $linkedobject_demo_object) {
    $linkedobject_demo_title = isset($linkedobject_demo_object['TITLE']) ? (string)$linkedobject_demo_object['TITLE'] : '';
    if ($linkedobject_demo_title !== '' && $linkedobject_demo_title !== 'AllScripts') {
        $linkedobject_demo_titles[] = $linkedobject_demo_title;
    }
}
foreach (array('ClockChime', 'ThisComputer', 'System') as $linkedobject_demo_preferred) {
    if (in_array($linkedobject_demo_preferred, $linkedobject_demo_titles, true)) {
        $linkedobject_demo_value = $linkedobject_demo_preferred;
        break;
    }
}
if ($linkedobject_demo_value === '' && !empty($linkedobject_demo_titles)) {
    $linkedobject_demo_value = $linkedobject_demo_titles[0];
}
?>
<section class="md-ui-section" id="linkedobject" data-md-doc-section>
    <div class="md-ui-section__intro">
        <?= MDJUI::sectionHeading(array(
            'eyebrow' => '03.1 · LinkedObject',
            'title' => 'Связи объектов',
            'description' => 'Выбор объекта, свойства или метода для старых модулей. Компонент забирает существующий native input, сохраняет его name/id и обработчики, а сверху добавляет поиск, свободный ввод, ленивую загрузку свойств и методов и понятные состояния.',
            'level' => 2,
        )) ?>
        <span class="md-ui-section__kicker">&#091;#module name=&quot;linkedobject&quot;#&#093; · MDLinkedObjectUI</span>
    </div>

    <?php md_ui_demo(array(
        'title' => 'Живой пример: объект → свойство → метод',
        'desc' => 'Данные берутся из текущей базы. Выберите объект — свойства и методы загрузятся одним запросом. Поле остаётся обычным input: можно ввести своё значение, а старый обработчик change получит событие.',
        'api' => 'object_field · property_field · method_field · width',
        'id' => 'linkedobject-demo',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
[#module name="linkedobject"
    object_field="linked_object"
    property_field="linked_property"
    method_field="linked_method"
    width="100%"#]

<!-- Native input — обязателен. Компонент найдёт его по id/name,
     перенесёт внутрь своего контрола и сохранит обработчики. -->
<input type="text" name="linked_object" id="linked_object" value="...">
CODE,
    ), function () use ($linkedobject_demo_value) { ?>
        <div class="md-linkedobject-demo">
            <div class="md-linkedobject-demo__grid">
                <div class="md-linkedobject-demo__cell">
                    <input type="text"
                           class="md-input"
                           id="ui_kit_linked_object"
                           name="ui_kit_linked_object"
                           value="<?= MDJUI::e($linkedobject_demo_value) ?>"
                           placeholder="Объект"
                           autocomplete="off"
                           data-md-linkedobject-demo-source="object">
                </div>
                <div class="md-linkedobject-demo__cell">
                    <input type="text"
                           class="md-input"
                           id="ui_kit_linked_property"
                           name="ui_kit_linked_property"
                           value=""
                           placeholder="Свойство"
                           autocomplete="off"
                           data-md-linkedobject-demo-source="property">
                </div>
                <div class="md-linkedobject-demo__cell">
                    <input type="text"
                           class="md-input"
                           id="ui_kit_linked_method"
                           name="ui_kit_linked_method"
                           value=""
                           placeholder="Метод"
                           autocomplete="off"
                           data-md-linkedobject-demo-source="method">
                </div>
            </div>
            <div class="md-linkedobject-demo__module">
                <?= md_ui_render_linkedobject(
                    'ui_kit_linked_object',
                    'ui_kit_linked_property',
                    'ui_kit_linked_method',
                    $linkedobject_demo_value
                ) ?>
            </div>
            <div class="md-linkedobject-demo__log" data-md-linkedobject-demo-log aria-live="polite">
                <span class="md-linkedobject-demo__log-title">Событие <code>change</code> от native input</span>
                <span class="md-linkedobject-demo__log-text" data-md-linkedobject-demo-log-text>пока не было</span>
            </div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Компактная таблица связей',
        'desc' => 'Режим data-md-linkedobject-layout="table" убирает повторные подписи и нижние панели: остаётся строка контролов с поиском, свободным вводом и portal-dropdown. Заголовки колонок и aria-label подписывают поля.',
        'api' => 'data-md-linkedobject-layout="table"',
        'canvas_class' => 'md-ui-demo__canvas--block',
    ), function () { ?>
        <div class="md-table-wrap">
            <table class="md-table" data-md-linkedobject-layout="table">
                <thead>
                    <tr>
                        <th>Параметр</th>
                        <th>Связь с объектом</th>
                        <th>Метод</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Событие датчика</td>
                        <td>
                            <input type="text"
                                   class="md-input"
                                   id="ui_kit_table_object"
                                   name="ui_kit_table_object"
                                   value=""
                                   placeholder="Объект"
                                   aria-label="Связанный объект"
                                   autocomplete="off">
                            <?= md_ui_render_linkedobject('ui_kit_table_object', '', '', '') ?>
                        </td>
                        <td><code>onEvent</code></td>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php }); ?>

    <div class="md-ui-demo__canvas md-ui-demo__canvas--block">
        <div class="md-ui-list">
            <div class="md-ui-list__item">
                <?= MDJUI::icon('circle-check') ?>
                <span><strong>Native input остаётся полем формы.</strong> Сохраняются <code>name</code>, <code>id</code>, inline <code>onchange</code> и jQuery-обработчики; компонент только переносит его внутрь своего контрола.</span>
            </div>
            <div class="md-ui-list__item">
                <?= MDJUI::icon('circle-check') ?>
                <span><strong>Свободный ввод.</strong> Метод может быть вызовом с параметрами, а объект — нестандартным именем. Список предлагает варианты, но не запрещает своё значение.</span>
            </div>
            <div class="md-ui-list__item">
                <?= MDJUI::icon('circle-check') ?>
                <span><strong>Один запрос на объект.</strong> Свойства и методы приходят атомарно, кэшируются между всеми экземплярами и не перетирают друг друга при быстром переключении.</span>
            </div>
            <div class="md-ui-list__item">
                <?= MDJUI::icon('circle-check') ?>
                <span><strong>Dropdown в portal.</strong> Список не обрезается таблицей, <code>overflow</code> и старыми модалками; позиция пересчитывается при скролле и изменении размера.</span>
            </div>
        </div>
    </div>
</section>
