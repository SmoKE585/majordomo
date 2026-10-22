<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/** Activity feed, contextual hints and read-only code samples. */
?>
<section class="md-ui-section" id="blocks" data-md-doc-section>
    <div class="md-ui-section__intro">
        <?= MDJUI::sectionHeading(array(
            'eyebrow' => '08 · Blocks',
            'title' => 'Лента, подсказки и код',
            'description' => 'Три блока для страниц-инструкций и карточек: история действий, контекстная подсказка и пример кода с подсветкой и быстрым копированием.',
            'level' => 2,
        )) ?>
        <span class="md-ui-section__kicker">md-activity · md-hint · md-code-sample</span>
    </div>

    <?php md_ui_demo(array(
        'title' => 'Activity feed: лента действий',
        'desc' => 'Хронология событий с датами, тоном, автором и ссылкой на источник. Компактный режим подходит для боковой колонки карточки объекта.',
        'api' => 'MDJUI::activityFeed()',
        'badge' => 'компонент',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::activityFeed([
    'eyebrow' => 'История',
    'title' => 'Лента активности',
    'subtitle' => 'Последние действия в системе',
    'limit' => 4,
    'more_href' => '?action=objects',
    'more_label' => 'Вся история',
    'items' => [
        [
            'date' => 'Сегодня',
            'title' => 'Кухня · Основной свет',
            'text' => 'Яркость изменена с 0% на 72%.',
            'time' => '2 мин назад',
            'icon' => 'bulb',
            'tone' => 'primary',
            'actor' => 'Сценарий «Доброе утро»',
            'badge' => 'Выполнено',
            'badge_tone' => 'success',
            'href' => '?action=objects',
            'unread' => true,
        ],
        [
            'date' => 'Вчера',
            'title' => 'Ошибка системы',
            'text' => 'Цикл MQTT перезапущен.',
            'time' => 'вчера, 18:04',
            'icon' => 'alert-triangle',
            'tone' => 'warning',
        ],
    ],
]) ?>
CODE,
    ), function () use ($activity) {
        ?>
        <div class="md-ui-demo__grid md-ui-demo__grid--fit">
            <?= MDJUI::activityFeed(array(
                'eyebrow' => 'История',
                'title' => 'Лента активности',
                'subtitle' => 'Последние действия в системе',
                'icon' => 'history',
                'actions' => MDJUI::badge('Онлайн', 'success', array('dot' => true)),
                'items' => $activity,
                'limit' => 4,
                'more_href' => '?action=objects',
                'more_label' => 'Вся история',
            )) ?>
            <?= MDJUI::activityFeed(array(
                'eyebrow' => 'Компактно',
                'title' => 'Последние события',
                'icon' => 'activity',
                'compact' => true,
                'items' => array_slice($activity, 0, 3),
            )) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Hint: подсказка на странице',
        'desc' => 'Hint объясняет, как работает настройка, и остаётся рядом с формой. Это не статус операции: для результата используйте alert или toast. Поддерживает маркированный список, нумерованные шаги, ссылку и закрытие.',
        'api' => 'MDJUI::hint()',
        'badge' => 'компонент',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::hint([
    'tone' => 'tip',
    'title' => 'Как работает история свойства',
    'text' => 'История хранится столько дней, сколько указано в настройках свойства.',
    'points' => [
        'Значение записывается только при изменении.',
        'Старые точки удаляются фоновым циклом.',
    ],
    'link' => '?action=knowledge_base',
    'link_label' => 'Открыть инструкцию',
]) ?>

<?= MDJUI::hint([
    'tone' => 'warning',
    'title' => 'Перед сменой базы данных',
    'steps' => [
        'Сделайте резервную копию.',
        'Проверьте доступы к новой базе.',
        'Сохраните настройки и дождитесь перезапуска циклов.',
    ],
    'dismissible' => true,
]) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-alert-stack">
            <?= MDJUI::hint(array(
                'tone' => 'tip',
                'title' => 'Как работает история свойства',
                'text' => 'История хранится столько дней, сколько указано в настройках свойства.',
                'points' => array(
                    'Значение записывается только при изменении.',
                    'Старые точки удаляются фоновым циклом.',
                ),
                'link' => '?action=knowledge_base',
                'link_label' => 'Открыть инструкцию',
            )) ?>
            <?= MDJUI::hint(array(
                'tone' => 'warning',
                'title' => 'Перед сменой базы данных',
                'steps' => array(
                    'Сделайте резервную копию.',
                    'Проверьте доступы к новой базе.',
                    'Сохраните настройки и дождитесь перезапуска циклов.',
                ),
                'dismissible' => true,
                'actions' => MDJUI::button('К резервным копиям', array('variant' => 'secondary', 'size' => 'sm', 'href' => '?action=saverestore')),
            )) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Code sample: PHP и Python с подсветкой',
        'desc' => 'Read-only пример кода с переключением языка, номерами строк и кнопкой копирования. Подсветка грузится отдельным lazy-chunk только на страницах с примерами; без JavaScript код остаётся читаемым.',
        'api' => 'MDJUI::codeSample()',
        'badge' => 'компонент',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::codeSample([
    'eyebrow' => 'Пример',
    'title' => 'Изменить яркость из метода',
    'caption' => 'Метод объекта Kitchen.Light',
    'tabs' => [
        [
            'language' => 'php',
            'label' => 'PHP',
            'code' => '$brightness = 72;' . "\n"
                . '$this->setProperty("Kitchen.Light.Brightness", $brightness);',
        ],
        [
            'language' => 'python',
            'label' => 'Python',
            'code' => 'brightness = 72' . "\n"
                . 'callMethod("Kitchen.Light.setBrightness", brightness)',
        ],
    ],
]) ?>
CODE,
    ), function () { ?>
        <?= MDJUI::codeSample(array(
            'eyebrow' => 'Пример',
            'title' => 'Изменить яркость из метода',
            'caption' => 'Метод объекта Kitchen.Light · подсветка и копирование',
            'tabs' => array(
                array(
                    'language' => 'php',
                    'label' => 'PHP',
                    'code' => <<<'PHP'
<?php
$brightness = 72;

// Свойство объекта меняется сразу, история пишется автоматически.
$this->setProperty('Kitchen.Light.Brightness', $brightness);

if ($brightness > 50) {
    say('Кухня: яркость ' . $brightness . '%');
}
PHP,
                ),
                array(
                    'language' => 'python',
                    'label' => 'Python',
                    'code' => <<<'PY'
brightness = 72

# Свойство объекта и история изменения.
setProperty("Kitchen.Light.Brightness", brightness)

if brightness > 50:
    say("Кухня: яркость " + str(brightness) + "%")
PY,
                ),
            ),
        )) ?>
        <div class="md-ui-note md-ui-note--spaced">
            <?= MDJUI::icon('info-circle') ?>
            <span>Кнопка копирует текущую вкладку. Переключение языка работает с клавиатуры стрелками и Home/End.</span>
        </div>
    <?php }); ?>
    <?php md_ui_demo(array('title' => 'Раскрывающийся блок и модальное окно', 'desc' => 'Общие компоненты инструкций и подтверждений.', 'api' => 'MDJUI::disclosure() · MDJUI::modal()', 'badge' => 'компонент', 'canvas_class' => 'md-ui-demo__canvas--block', 'code' => '<?= MDJUI::disclosure(["title" => "Описание"], "<p>Содержимое</p>") ?>'), function () { ?>
        <?= MDJUI::disclosure(array('title' => 'Описание операции', 'description' => 'Раскрывается мышью и клавиатурой', 'badge' => 'GET', 'badge_tone' => 'success'), '<p>Параметры, инструкции и примеры остаются внутри общего компонента.</p>') ?>
        <?= MDJUI::button('Открыть модальное окно', array('variant' => 'secondary', 'attrs' => array('data-bs-toggle' => 'modal', 'data-bs-target' => '#md-kit-modal'))) ?>
        <?= MDJUI::modal(array('id' => 'md-kit-modal', 'title' => 'Общее модальное окно', 'footer' => MDJUI::button('Закрыть', array('variant' => 'secondary', 'attrs' => array('data-bs-dismiss' => 'modal')))), '<p>Bootstrap управляет фокусом, Esc и возвратом к кнопке.</p>') ?>
    <?php }); ?>
</section>
