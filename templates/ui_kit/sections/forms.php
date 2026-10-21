<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/** Forms and inputs. */
$classOptions = array(
    'light' => 'Свет',
    'climate' => 'Климат',
    'sensor' => 'Датчик',
    'security' => 'Безопасность',
    'vent' => 'Вентиляция',
);
$roomOptions = array(
    'kitchen' => 'Кухня',
    'living' => 'Гостиная',
    'bedroom' => 'Спальня',
    'hall' => 'Прихожая',
    'office' => 'Кабинет',
);
?>
<section class="md-ui-section" id="forms" data-md-doc-section>
    <div class="md-ui-section__intro">
        <?= MDJUI::sectionHeading(array(
            'eyebrow' => '03 · Forms',
            'title' => 'Поля ввода и формы',
            'description' => 'Все контролы одной высоты, с единым label, подсказкой и состоянием ошибки. Ошибка показывается рядом с полем, а не только в toast.',
            'level' => 2,
        )) ?>
        <span class="md-ui-section__kicker">MDJUI::input() · MDJUI::field()</span>
    </div>

    <?php md_ui_demo(array(
        'title' => 'Текстовые поля',
        'desc' => 'Text, number, password и textarea. Пароль получает кнопку показа, число — нативные ограничения и шаг.',
        'api' => 'type · name · value · label · hint · error',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::input([
  'type' => 'text',
  'name' => 'title',
  'label' => 'Название устройства',
  'placeholder' => 'Например, Кухня · Основной свет',
  'hint' => 'Отображается в списке устройств и сценариях.',
  'attrs' => ['wire:model' => 'title'],
]) ?>

<?= MDJUI::input([
  'type' => 'password',
  'name' => 'password',
  'label' => 'Пароль',
  'password_toggle' => true,
]) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-form-grid">
            <?= MDJUI::input(array(
                'type' => 'text',
                'name' => 'device_title',
                'label' => 'Название устройства',
                'value' => 'Кухня · Основной свет',
                'placeholder' => 'Например, Кухня · Основной свет',
                'hint' => 'Отображается в списке устройств и сценариях.',
            )) ?>
            <?= MDJUI::input(array(
                'type' => 'number',
                'name' => 'power',
                'label' => 'Мощность',
                'value' => '9',
                'min' => 0,
                'max' => 100,
                'step' => 1,
                'suffix' => 'Вт',
                'hint' => 'Номинальная мощность нагрузки.',
            )) ?>
            <?= MDJUI::input(array(
                'type' => 'password',
                'name' => 'device_password',
                'label' => 'Пароль устройства',
                'value' => 'secret-pass',
                'password_toggle' => true,
                'hint' => 'Хранится в настройках модуля.',
            )) ?>
            <?= MDJUI::input(array(
                'type' => 'search',
                'name' => 'quick_search',
                'label' => 'Поиск',
                'placeholder' => 'Найти объект…',
                'icon' => 'search',
            )) ?>
        </div>
        <div class="md-ui-form-grid md-ui-form-grid--wide">
            <?= MDJUI::textarea(array(
                'name' => 'description',
                'label' => 'Описание',
                'value' => 'Свет в кухонной зоне: сценарии, расписание и ручное управление.',
                'rows' => 3,
                'hint' => 'До 240 символов.',
            )) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Select, searchable select и multi select',
        'desc' => 'Обычный select — для коротких списков. Searchable подключает Tom Select через data-md-searchable. Multi select поддерживает удаление выбранных элементов.',
        'api' => 'searchable · multiple · options',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::select([
  'name' => 'class_id',
  'label' => 'Класс устройства',
  'options' => ['light' => 'Свет', 'climate' => 'Климат'],
  'selected' => 'light',
]) ?>

<?= MDJUI::select([
  'name' => 'room_id',
  'label' => 'Помещение',
  'options' => $roomOptions,
  'searchable' => true,
  'placeholder' => 'Выберите помещение',
]) ?>

<?= MDJUI::select([
  'name' => 'rooms[]',
  'label' => 'Помещения',
  'options' => $roomOptions,
  'multiple' => true,
  'searchable' => true,
  'selected' => ['kitchen', 'living'],
]) ?>
CODE,
    ), function () use ($classOptions, $roomOptions) { ?>
        <div class="md-ui-form-grid">
            <?= MDJUI::select(array(
                'name' => 'device_class',
                'label' => 'Класс устройства',
                'options' => $classOptions,
                'selected' => 'light',
                'hint' => 'Определяет доступные свойства.',
            )) ?>
            <?= MDJUI::select(array(
                'name' => 'device_room',
                'label' => 'Помещение',
                'options' => $roomOptions,
                'selected' => 'kitchen',
                'searchable' => true,
                'placeholder' => 'Выберите помещение',
            )) ?>
            <?= MDJUI::select(array(
                'name' => 'device_rooms[]',
                'label' => 'Помещения',
                'options' => $roomOptions,
                'selected' => array('kitchen', 'living'),
                'multiple' => true,
                'searchable' => true,
                'hint' => 'Можно выбрать несколько зон.',
            )) ?>
            <?= MDJUI::select(array(
                'name' => 'device_state',
                'label' => 'Состояние',
                'options' => array('' => 'Любое', 'online' => 'Онлайн', 'offline' => 'Офлайн', 'warning' => 'Внимание', 'error' => 'Ошибка'),
                'selected' => '',
            )) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Checkbox, radio group и switch',
        'desc' => 'Checkbox — независимые параметры, radio — один из вариантов, switch — мгновенное включение/выключение без кнопки сохранения.',
        'api' => 'MDJUI::checkbox() · radio() · switchControl()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::checkbox([
  'name' => 'notify',
  'label' => 'Уведомлять о событиях',
  'description' => 'Push и e-mail при изменении состояния.',
  'checked' => true,
]) ?>

<?= MDJUI::radio(['name' => 'mode', 'value' => 'auto', 'label' => 'Авто', 'checked' => true]) ?>
<?= MDJUI::radio(['name' => 'mode', 'value' => 'manual', 'label' => 'Вручную']) ?>

<?= MDJUI::switchControl([
  'name' => 'enabled',
  'label' => 'Сценарий включён',
  'description' => 'Отключённый сценарий не запускается по расписанию.',
  'checked' => true,
]) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-form-columns">
            <div class="md-ui-form-column">
                <div class="md-ui-demo__label">Checkbox</div>
                <?= MDJUI::checkbox(array('name' => 'notify_push', 'label' => 'Push-уведомления', 'description' => 'Мгновенные события на телефон.', 'checked' => true,)) ?>
                <?= MDJUI::checkbox(array('name' => 'notify_email', 'label' => 'E-mail', 'description' => 'Ежедневная сводка и ошибки.', 'checked' => false)) ?>
                <?= MDJUI::checkbox(array('name' => 'notify_sms', 'label' => 'SMS', 'description' => 'Только критические события.', 'checked' => false, 'disabled' => true)) ?>
            </div>
            <div class="md-ui-form-column">
                <div class="md-ui-demo__label">Radio group</div>
                <?= MDJUI::radio(array('name' => 'execution_mode', 'value' => 'auto', 'label' => 'Автоматически', 'description' => 'По расписанию и датчикам.', 'checked' => true)) ?>
                <?= MDJUI::radio(array('name' => 'execution_mode', 'value' => 'manual', 'label' => 'Только вручную')) ?>
                <?= MDJUI::radio(array('name' => 'execution_mode', 'value' => 'disabled', 'label' => 'Отключено', 'disabled' => true)) ?>
            </div>
            <div class="md-ui-form-column">
                <div class="md-ui-demo__label">Switch</div>
                <?= MDJUI::switchControl(array('name' => 'scene_enabled', 'label' => 'Сценарий включён', 'description' => 'Расписание и триггеры активны.', 'checked' => true)) ?>
                <?= MDJUI::switchControl(array('name' => 'scene_notify', 'label' => 'Уведомлять о запуске', 'description' => 'Короткий toast в админке.', 'checked' => false)) ?>
                <?= MDJUI::switchControl(array('name' => 'scene_away', 'label' => 'Режим «Никого нет дома»', 'description' => 'Требуется подтверждение.', 'checked' => false, 'disabled' => true)) ?>
            </div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Выбор варианта карточками',
        'desc' => 'Радио-группа для взаимоисключающих вариантов: заголовок, пояснение и опциональная иконка. Карточка целиком кликабельна, выбранная подсвечивается рамкой и мягким фоном.',
        'api' => 'MDJUI::choiceGroup() · name · options · selected',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::choiceGroup([
  'name' => 'run_type',
  'aria_label' => 'Реализация метода',
  'selected' => 'code',
  'options' => [
    ['value' => 'script', 'label' => 'Сценарий', 'description' => 'Выполнить готовый сценарий', 'icon' => 'file-code'],
    ['value' => 'code', 'label' => 'Код', 'description' => 'PHP или Python прямо в методе', 'icon' => 'code'],
  ],
]) ?>
CODE,
    ), function () { ?>
        <div class="md-field">
            <span class="md-field__label">Реализация метода</span>
            <div class="md-field__control">
                <?= MDJUI::choiceGroup(array(
                    'name' => 'ui_kit_run_type',
                    'aria_label' => 'Реализация метода',
                    'selected' => 'code',
                    'options' => array(
                        array('value' => 'script', 'label' => 'Сценарий', 'description' => 'Выполнить готовый сценарий', 'icon' => 'file-code'),
                        array('value' => 'code', 'label' => 'Код', 'description' => 'PHP или Python прямо в методе', 'icon' => 'code'),
                    ),
                )) ?>
            </div>
            <div class="md-field__hint">Сценарий удобен для повторного использования, код — для локальной логики.</div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Range, date, time и color',
        'desc' => 'Range показывает значение и обновляет его без перезагрузки. Date/time используют нативные контролы с единым оформлением. Color синхронизирует палитру и hex.',
        'api' => 'MDJUI::range() · type: date · time · color',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::range([
  'name' => 'brightness',
  'label' => 'Яркость',
  'value' => 72,
  'min' => 1,
  'max' => 100,
  'unit' => '%',
]) ?>

<?= MDJUI::input(['type' => 'date', 'name' => 'start_date', 'label' => 'Дата запуска', 'value' => '2026-10-20']) ?>
<?= MDJUI::input(['type' => 'time', 'name' => 'start_time', 'label' => 'Время', 'value' => '19:30']) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-form-grid">
            <?= MDJUI::range(array('name' => 'brightness', 'label' => 'Яркость', 'value' => 72, 'min' => 1, 'max' => 100, 'unit' => '%', 'hint' => 'Применяется сразу к выбранной группе.')) ?>
            <?= MDJUI::range(array('name' => 'target_temp', 'label' => 'Уставка температуры', 'value' => 23, 'min' => 16, 'max' => 28, 'step' => 0.5, 'unit' => '°C')) ?>
            <?= MDJUI::input(array('type' => 'date', 'name' => 'start_date', 'label' => 'Дата запуска', 'value' => '2026-10-20')) ?>
            <?= MDJUI::input(array('type' => 'time', 'name' => 'start_time', 'label' => 'Время запуска', 'value' => '19:30')) ?>
        </div>
        <div class="md-ui-form-grid md-ui-form-grid--wide">
            <div class="md-field">
                <label class="md-field__label" for="uiKitColor">Цвет подсветки</label>
                <div class="md-field__control">
                    <div class="md-color-field" x-data="mdColorPicker('#2f7fbd')">
                        <input class="md-color-field__picker" type="color" value="#2f7fbd" x-model="color" @input="hex = color" aria-label="Выбрать цвет">
                        <input class="md-input md-color-field__input" id="uiKitColor" type="text" x-model="hex" @change="applyHex()" spellcheck="false" aria-label="HEX-цвет">
                        <span class="md-color-field__swatch" :style="'--md-color:' + color"></span>
                    </div>
                </div>
                <div class="md-field__hint">HEX-формат, например #2f7fbd.</div>
            </div>
            <div class="md-field">
                <label class="md-field__label">Образец цвета</label>
                <div class="md-field__control">
                    <div class="md-color-sample" x-data="mdColorPicker('#2f7fbd')" :style="'--md-color:' + color">
                        <span x-text="hex"></span>
                    </div>
                </div>
                <div class="md-field__hint">Связан с полем выше через Alpine.</div>
            </div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'File upload',
        'desc' => 'Drag-and-drop зона с выбором файлов и списком. Валидация типа и размера выполняется на сервере; клиентская часть только показывает выбор.',
        'api' => 'mdFileUpload',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<div x-data="mdFileUpload()">
  <label class="md-upload"
         :class="{ 'is-dragging': dragging }"
         @dragover.prevent="dragging = true"
         @dragleave.prevent="dragging = false"
         @drop.prevent="dragging = false; addFiles($event.dataTransfer.files)">
    <input type="file" multiple @change="addFiles($event.target.files)">
    <span class="md-upload__icon">...</span>
    <span class="md-upload__title">Перетащите файлы или выберите</span>
    <span class="md-upload__hint">PNG, JPG, PDF до 10 МБ</span>
  </label>
</div>
CODE,
    ), function () { ?>
        <div class="md-upload-demo" x-data="mdFileUpload()">
            <label class="md-upload"
                   :class="{ 'is-dragging': dragging }"
                   @dragover.prevent="dragging = true"
                   @dragleave.prevent="dragging = false"
                   @drop.prevent="dragging = false; addFiles($event.dataTransfer.files)">
                <input type="file" multiple @change="addFiles($event.target.files)">
                <span class="md-upload__icon"><?= MDJUI::icon('cloud-arrow-up') ?></span>
                <span class="md-upload__title">Перетащите файлы или выберите</span>
                <span class="md-upload__hint">PNG, JPG, PDF · до 10 МБ</span>
            </label>
            <template x-if="files.length > 0">
                <ul class="md-upload__list">
                    <template x-for="(file, index) in files" :key="file.name + index">
                        <li class="md-upload__item">
                            <i class="ti ti-file" aria-hidden="true"></i>
                            <span class="md-upload__name" x-text="file.name"></span>
                            <span class="md-upload__size" x-text="formatSize(file.size)"></span>
                            <button type="button" class="md-icon-btn md-icon-btn--sm" @click="remove(index)" aria-label="Удалить файл">
                                <?= MDJUI::icon('x-lg') ?>
                            </button>
                        </li>
                    </template>
                </ul>
            </template>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Input с иконкой, префиксом, подсказкой и ошибкой',
        'desc' => 'Одна оболочка для всех adornment-вариантов. Ошибка меняет border и показывает текст с иконкой; aria-invalid проставляется автоматически.',
        'api' => 'icon · prefix · suffix · hint · error',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::input(['label' => 'Поиск', 'icon' => 'search', 'placeholder' => 'Введите запрос']) ?>
<?= MDJUI::input(['label' => 'Тариф', 'prefix' => '₽', 'value' => '4,20', 'suffix' => 'кВт·ч']) ?>
<?= MDJUI::input(['label' => 'E-mail', 'value' => 'bad@', 'error' => 'Проверьте формат e-mail']) ?>
<?= MDJUI::input(['label' => 'ID устройства', 'value' => 'kitchen-light', 'disabled' => true]) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-form-grid">
            <?= MDJUI::input(array('label' => 'Поиск', 'name' => 'search_demo', 'icon' => 'search', 'placeholder' => 'Введите запрос')) ?>
            <?= MDJUI::input(array('label' => 'Тариф', 'name' => 'tariff', 'prefix' => '₽', 'value' => '4,20', 'suffix' => 'кВт·ч')) ?>
            <?= MDJUI::input(array('label' => 'E-mail', 'name' => 'email_invalid', 'value' => 'bad@', 'error' => 'Проверьте формат e-mail')) ?>
            <?= MDJUI::input(array('label' => 'ID устройства', 'name' => 'device_id', 'value' => 'kitchen-light', 'disabled' => true)) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Состояния полей',
        'desc' => 'Default, hover, focus, error, disabled и readonly. Focus и hover показаны классами для документации; в реальном интерфейсе они работают от :hover/:focus-visible.',
        'api' => 'is-invalid · disabled · readonly · is-demo-*',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::input(['label' => 'Default', 'value' => 'Кухня · Свет']) ?>
<?= MDJUI::input(['label' => 'Hover', 'value' => 'Кухня · Свет', 'class' => 'is-demo-hover']) ?>
<?= MDJUI::input(['label' => 'Focus', 'value' => 'Кухня · Свет', 'class' => 'is-demo-focus']) ?>
<?= MDJUI::input(['label' => 'Error', 'value' => 'bad@', 'error' => 'Проверьте формат']) ?>
<?= MDJUI::input(['label' => 'Disabled', 'value' => 'Недоступно', 'disabled' => true]) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-form-grid">
            <?= MDJUI::input(array('label' => 'Default', 'name' => 'state_default', 'value' => 'Кухня · Свет')) ?>
            <?= MDJUI::input(array('label' => 'Hover', 'name' => 'state_hover', 'value' => 'Кухня · Свет', 'class' => 'is-demo-hover')) ?>
            <?= MDJUI::input(array('label' => 'Focus', 'name' => 'state_focus', 'value' => 'Кухня · Свет', 'class' => 'is-demo-focus')) ?>
            <?= MDJUI::input(array('label' => 'Error', 'name' => 'state_error', 'value' => 'bad@', 'error' => 'Проверьте формат e-mail')) ?>
            <?= MDJUI::input(array('label' => 'Disabled', 'name' => 'state_disabled', 'value' => 'Недоступно', 'disabled' => true)) ?>
            <?= MDJUI::input(array('label' => 'Readonly', 'name' => 'state_readonly', 'value' => 'kitchen-light', 'readonly' => true, 'hint' => 'ID выдаётся системой.')) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Field Group и inline-валидация',
        'desc' => 'Field group связывает label, описание и сообщение. Валидация показывается после submit и обновляется по мере ввода.',
        'api' => 'MDJUI::field() · Alpine mdValidationForm',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::field([
  'label' => 'Порог температуры',
  'for' => 'threshold',
  'description' => 'Сценарий сработает, когда значение превысит порог.',
  'hint' => 'Допустимый диапазон: 16–28 °C.',
], MDJUI::input(['name' => 'threshold', 'value' => '24', 'suffix' => '°C', 'wrap' => false])) ?>
CODE,
    ), function () { ?>
        <form class="md-ui-form" x-data="mdValidationForm()" @submit.prevent="submit()">
            <div class="md-ui-form-grid">
                <?= MDJUI::field(array(
                    'label' => 'Порог температуры',
                    'for' => 'uiKitThreshold',
                    'description' => 'Сценарий сработает, когда значение превысит порог.',
                    'hint' => 'Допустимый диапазон: 16–28 °C.',
                ), MDJUI::input(array('name' => 'threshold', 'id' => 'uiKitThreshold', 'value' => '24', 'suffix' => '°C', 'wrap' => false))) ?>
                <div class="md-field" :class="{ 'is-invalid': nameError }">
                    <label class="md-field__label" for="uiKitDeviceName">Название устройства <span class="md-field__req" aria-hidden="true">*</span></label>
                    <div class="md-field__control">
                        <input class="md-input" :class="{ 'is-invalid': nameError }" id="uiKitDeviceName" type="text" x-model="name" placeholder="Например, Кухня · Свет" aria-describedby="uiKitDeviceNameHelp">
                    </div>
                    <div class="md-field__error" role="alert" id="uiKitDeviceNameHelp" x-show="nameError" x-cloak><?= MDJUI::icon('exclamation-circle') ?><span x-text="nameError"></span></div>
                    <div class="md-field__hint" x-show="!nameError">Отображается в списке устройств.</div>
                </div>
                <div class="md-field" :class="{ 'is-invalid': emailError }">
                    <label class="md-field__label" for="uiKitDeviceEmail">E-mail для уведомлений</label>
                    <div class="md-field__control">
                        <input class="md-input" :class="{ 'is-invalid': emailError }" id="uiKitDeviceEmail" type="email" x-model="email" placeholder="name@example.com" aria-describedby="uiKitDeviceEmailHelp">
                    </div>
                    <div class="md-field__error" role="alert" id="uiKitDeviceEmailHelp" x-show="emailError" x-cloak><?= MDJUI::icon('exclamation-circle') ?><span x-text="emailError"></span></div>
                    <div class="md-field__hint" x-show="!emailError">Используется для критических событий.</div>
                </div>
                <div class="md-field">
                    <label class="md-field__label" for="uiKitDeviceZone">Помещение</label>
                    <div class="md-field__control">
                        <?= MDJUI::select(array('name' => 'zone', 'id' => 'uiKitDeviceZone', 'options' => $roomOptions, 'selected' => 'kitchen', 'wrap' => false)) ?>
                    </div>
                    <div class="md-field__hint">Можно изменить позже.</div>
                </div>
            </div>
            <div class="md-ui-form-actions">
                <?= MDJUI::button('Отмена', array('variant' => 'secondary')) ?>
                <?= MDJUI::button('Сохранить устройство', array('variant' => 'primary', 'icon' => 'check2', 'type' => 'submit')) ?>
            </div>
        </form>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Folder picker',
        'desc' => 'Read-only выбор каталога для путей: бэкапы, логи, сертификаты. Навигация ограничена корнем проекта; файлы и внешние пути недоступны.',
        'api' => 'MDJUI::folderPicker() · data-md-folder-picker · data-md-folder-picker-modal',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::folderPicker([
  'id' => 'mdUiKitFolderPicker',
  'endpoint' => ROOTHTML . 'ajax/settings.html?op=folder',
]) ?>

<div class="d-flex gap-2">
  <input class="md-input" id="uiKitFolderPath" type="text" value="" aria-label="Папка">
  <button type="button" class="md-btn md-btn--secondary"
          data-md-folder-picker="uiKitFolderPath"
          data-md-folder-picker-modal="mdUiKitFolderPicker">
    Выбрать
  </button>
</div>
CODE,
    ), function () { ?>
        <div class="md-field">
            <label class="md-field__label" for="uiKitFolderPath">Папка резервных копий</label>
            <div class="md-field__control d-flex gap-2">
                <input class="md-input" id="uiKitFolderPath" type="text" value="" autocomplete="off" spellcheck="false" placeholder="Выберите каталог проекта">
                <button type="button" class="md-btn md-btn--secondary" data-md-folder-picker="uiKitFolderPath" data-md-folder-picker-modal="mdUiKitFolderPicker">
                    <?= MDJUI::icon('folder-open') ?>
                    <span class="md-btn__label">Выбрать</span>
                </button>
            </div>
            <div class="md-field__hint">В поле записывается абсолютный путь внутри проекта. Внешние каталоги можно указать вручную.</div>
        </div>
    <?php }); ?>

    <?= MDJUI::folderPicker(array(
        'id' => 'mdUiKitFolderPicker',
        'endpoint' => ROOTHTML . 'ajax/settings.html?op=folder',
        'title' => 'Выбор папки',
        'subtitle' => 'Только каталоги внутри проекта MajorDoMo.',
    )) ?>
</section>






