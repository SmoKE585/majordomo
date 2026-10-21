<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/** Cards and containers. */
?>
<section class="md-ui-section" id="cards" data-md-doc-section>
    <div class="md-ui-section__intro">
        <?= MDJUI::sectionHeading(array(
            'eyebrow' => '05 · Containers',
            'title' => 'Карточки и контейнеры',
            'description' => 'Карточка — основная поверхность админки. Заголовок, описание и действия отделены от тела; вложенные карточки не используются без необходимости.',
            'level' => 2,
        )) ?>
        <span class="md-ui-section__kicker">MDJUI::card() · MDJUI::kpi()</span>
    </div>

    <?php md_ui_demo(array(
        'title' => 'Стандартная карточка',
        'desc' => 'Минимальная карточка с одним смысловым блоком. Радиус 14 px, тонкая граница, тень только для отрыва от фона.',
        'api' => 'MDJUI::card()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::card([], '<p>Карточка без заголовка подходит для виджетов и вложенных блоков.</p>') ?>
CODE,
    ), function () { ?>
        <div class="md-ui-card-grid">
            <?= MDJUI::card(array(), '<p class="md-ui-muted">Карточка без заголовка подходит для виджетов и вложенных блоков.</p>') ?>
            <?= MDJUI::card(array('title' => 'Кухня', 'subtitle' => '4 устройства · 3 онлайн'), '<div class="md-ui-card-metric"><strong>23,1 °C</strong><span>Температура</span></div>') ?>
            <?= MDJUI::card(array('title' => 'Правила', 'subtitle' => 'Что важно помнить'), '<ul class="md-ui-list"><li>' . MDJUI::icon('check2-circle') . '<span>Один primary-action.</span></li><li>' . MDJUI::icon('check2-circle') . '<span>Действия — справа в заголовке.</span></li></ul>') ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Card с заголовком, описанием и действиями',
        'desc' => 'Заголовок отвечает на «что это», описание — на «что внутри», действия не дублируют содержимое. Footer — для метаданных и вторичных ссылок.',
        'api' => 'title · subtitle · actions · footer',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::card([
  'title' => 'Сценарий «Доброе утро»',
  'subtitle' => 'Пн–Пт · 07:00 · 18 запусков',
  'actions' => MDJUI::button('Открыть', ['variant' => 'secondary', 'size' => 'sm']),
  'footer' => 'Последний запуск — сегодня в 07:00',
], '<p>...</p>') ?>
CODE,
    ), function () { ?>
        <?= MDJUI::card(array(
            'title' => 'Сценарий «Доброе утро»',
            'subtitle' => 'Пн–Пт · 07:00 · 18 запусков',
            'icon' => 'sunrise',
            'actions' => MDJUI::button('Открыть', array('variant' => 'secondary', 'size' => 'sm')),
            'footer' => MDJUI::lastSeen(array('label' => 'Последний запуск', 'value' => 'сегодня, 07:00')),
        ), '<div class="md-ui-card-row"><span>Свет 40%, шторы открыты, кофеварка.</span>' . MDJUI::status('online', 'Активна') . '</div>') ?>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'KPI / Stats Card',
        'desc' => 'KPI отвечает на один вопрос. Значение крупнее подписи, изменение — семантический badge, контекст — в мета-строке.',
        'api' => 'MDJUI::kpi()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::kpi([
  'label' => 'Устройства онлайн',
  'value' => '17',
  'unit' => 'из 19',
  'icon' => 'broadcast',
  'tone' => 'success',
  'delta' => '+2',
  'delta_tone' => 'success',
  'meta' => 'За последние 24 часа',
]) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-kpi-grid">
            <?= MDJUI::kpi(array('label' => 'Устройства онлайн', 'value' => '17', 'unit' => 'из 19', 'icon' => 'broadcast', 'tone' => 'success', 'delta' => '+2', 'delta_tone' => 'success', 'meta' => 'За последние 24 часа')) ?>
            <?= MDJUI::kpi(array('label' => 'Энергия сегодня', 'value' => '8,4', 'unit' => 'кВт·ч', 'icon' => 'lightning-charge', 'tone' => 'warning', 'delta' => '−7,7%', 'delta_tone' => 'success', 'meta' => 'Вчера — 9,1 кВт·ч')) ?>
            <?= MDJUI::kpi(array('label' => 'Активные сценарии', 'value' => '6', 'unit' => 'из 8', 'icon' => 'magic', 'tone' => 'primary', 'meta' => '2 на паузе')) ?>
            <?= MDJUI::kpi(array('label' => 'Ошибки за сутки', 'value' => '1', 'icon' => 'exclamation-triangle', 'tone' => 'danger', 'delta' => '−3', 'delta_tone' => 'success', 'meta' => 'Кабинет · CO₂')) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Widget Card',
        'desc' => 'Виджет показывает одно состояние и быстрый переход к деталям. Внутри — те же токены и компоненты, без отдельной визуальной системы.',
        'api' => 'MDJUI::card() + MDJUI::sensor()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::card([
  'title' => 'Климат в гостиной',
  'subtitle' => 'Датчик · обновлено 4 секунды назад',
  'actions' => MDJUI::badge('Комфорт', 'success'),
], $sensorHtml) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-widget-grid">
            <?= MDJUI::card(array(
                'title' => 'Климат в гостиной',
                'subtitle' => 'Датчик · обновлено 4 секунды назад',
                'icon' => 'thermometer-half',
                'actions' => MDJUI::badge('Комфорт', 'success', array('dot' => true)),
                'footer' => MDJUI::lastSeen(array('label' => 'Обновлено', 'value' => '4 секунды назад')),
            ), '<div class="md-ui-widget-metrics"><div><strong>22,5 °C</strong><span>Температура</span></div><div><strong>48%</strong><span>Влажность</span></div><div><strong>320 лк</strong><span>Освещённость</span></div></div>') ?>
            <?= MDJUI::card(array(
                'title' => 'Энергия за сутки',
                'subtitle' => 'Все устройства',
                'icon' => 'lightning-charge',
                'actions' => MDJUI::badge('−7,7%', 'success'),
            ), MDJUI::sparkline(array('series' => $energy['series'], 'label' => '24 часа', 'value' => 'пик 19:00–21:00', 'height' => 54))) ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Chart Card и Meter',
        'desc' => 'Chart Card — единый каркас для графика: заголовок, действия, полотно и футер. Само полотно заполняет утверждённая библиотека (ApexCharts) через data-атрибут. Meter — компактная строка ресурса с подписью, значением и прогрессом.',
        'api' => 'MDJUI::chartCard() · MDJUI::meter()',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::chartCard([
  'title' => 'Активность за 24 часа',
  'subtitle' => 'Свойства и скрипты',
  'icon' => 'chart-area-line',
  'chart_key' => 'activity',
  'actions' => MDJUI::badge('24 часа', 'neutral'),
], MDJUI::sparkline([
  'series' => [4, 7, 3, 9, 6, 12, 8],
  'label' => 'Демо-данные',
  'value' => 'пик 12',
])) ?>

<?= MDJUI::meter([
  'label' => 'CPU',
  'value' => '18%',
  'percent' => 18,
  'icon' => 'cpu',
  'hint' => '20 ядер · load 1,2',
]) ?>
CODE,
    ), function () use ($energy) { ?>
        <div class="md-ui-widget-grid">
            <?= MDJUI::chartCard(array(
                'title' => 'Активность за 24 часа',
                'subtitle' => 'Каркас для ApexCharts; в демо — sparkline',
                'icon' => 'chart-area-line',
                'chart_key' => 'ui-kit-activity',
                'height' => '0px',
                'actions' => MDJUI::badge('24 часа', 'neutral'),
            ), MDJUI::sparkline(array(
                'series' => $energy['series'],
                'label' => 'Демо-данные',
                'value' => 'пик 19:00–21:00',
                'tone' => 'primary',
            ))) ?>
            <?= MDJUI::card(array(
                'title' => 'Ресурсы сервера',
                'subtitle' => 'CPU, память и диск',
                'icon' => 'server',
                'actions' => MDJUI::badge('Внимание', 'warning', array('dot' => true)),
            ), '<div class="md-ui-meter-stack">' .
                MDJUI::meter(array('label' => 'CPU', 'value' => '18%', 'percent' => 18, 'icon' => 'cpu', 'hint' => '20 ядер · load 1,2')) .
                MDJUI::meter(array('label' => 'Память', 'value' => '54%', 'percent' => 54, 'tone' => 'info', 'icon' => 'device-desktop-analytics', 'hint' => '17,1 ГБ из 31,8 ГБ')) .
                MDJUI::meter(array('label' => 'Диск', 'value' => '88%', 'percent' => 88, 'tone' => 'warning', 'icon' => 'database', 'hint' => 'Свободно 57,6 ГБ из 476,7 ГБ')) .
                '</div>') ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Collapsible card и accordion',
        'desc' => 'Collapsible card сворачивает одно тело. Accordion — несколько независимых секций, где открыта одна или несколько.',
        'api' => 'Bootstrap collapse · accordion',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<section class="md-card">
  <header class="md-card__head">
    <button class="md-card__collapse" data-bs-toggle="collapse" data-bs-target="#advanced">...</button>
  </header>
  <div class="collapse show" id="advanced">...</div>
</section>
CODE,
    ), function () { ?>
        <div class="md-ui-collapse-grid">
            <section class="md-card">
                <header class="md-card__head">
                    <div class="md-card__titles">
                        <h2 class="md-card__title"><?= MDJUI::icon('sliders', array('class' => 'md-card__title-icon')) ?>Дополнительные параметры</h2>
                        <p class="md-card__subtitle">Свёрнуто по умолчанию</p>
                    </div>
                    <button type="button" class="md-icon-btn" data-bs-toggle="collapse" data-bs-target="#uiKitCollapseBody" aria-expanded="true" aria-controls="uiKitCollapseBody" aria-label="Свернуть параметры">
                        <?= MDJUI::icon('chevron-up') ?>
                    </button>
                </header>
                <div class="collapse show" id="uiKitCollapseBody">
                    <div class="md-card__body">
                        <?= MDJUI::switchControl(array('name' => 'advanced_log', 'label' => 'Подробный лог', 'description' => 'Писать события устройства в actions_log.', 'checked' => true)) ?>
                    </div>
                </div>
            </section>
            <div class="accordion md-accordion" id="uiKitAccordion">
                <div class="accordion-item">
                    <h3 class="accordion-header" id="uiKitAccordionHeadingOne">
                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#uiKitAccordionOne" aria-expanded="true" aria-controls="uiKitAccordionOne">
                            Подключение
                        </button>
                    </h3>
                    <div id="uiKitAccordionOne" class="accordion-collapse collapse show" aria-labelledby="uiKitAccordionHeadingOne" data-bs-parent="#uiKitAccordion">
                        <div class="accordion-body">MQTT, Zigbee и Z-Wave. Статус связи — в карточке устройства.</div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h3 class="accordion-header" id="uiKitAccordionHeadingTwo">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#uiKitAccordionTwo" aria-expanded="false" aria-controls="uiKitAccordionTwo">
                            Расписание
                        </button>
                    </h3>
                    <div id="uiKitAccordionTwo" class="accordion-collapse collapse" aria-labelledby="uiKitAccordionHeadingTwo" data-bs-parent="#uiKitAccordion">
                        <div class="accordion-body">Пн–Пт, 07:00. Праздники и исключения настраиваются в сценарии.</div>
                    </div>
                </div>
                <div class="accordion-item">
                    <h3 class="accordion-header" id="uiKitAccordionHeadingThree">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#uiKitAccordionThree" aria-expanded="false" aria-controls="uiKitAccordionThree">
                            Уведомления
                        </button>
                    </h3>
                    <div id="uiKitAccordionThree" class="accordion-collapse collapse" aria-labelledby="uiKitAccordionHeadingThree" data-bs-parent="#uiKitAccordion">
                        <div class="accordion-body">Push, e-mail и SMS. По умолчанию — только критические события.</div>
                    </div>
                </div>
            </div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Empty state, skeleton и разделители',
        'desc' => 'Пустое состояние объясняет, что произошло и что сделать. Skeleton повторяет форму будущего контента и не прыгает по высоте.',
        'api' => 'MDJUI::emptyState() · MDJUI::skeleton() · md-section-head',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::emptyState([
  'icon' => 'broadcast',
  'title' => 'Устройства не найдены',
  'text' => 'Измените фильтр или добавьте первое устройство.',
  'action' => MDJUI::button('Добавить устройство', ['icon' => 'plus-lg']),
]) ?>
CODE,
    ), function () { ?>
        <div class="md-ui-empty-grid">
            <div class="md-card">
                <?= MDJUI::emptyState(array(
                    'icon' => 'broadcast',
                    'title' => 'Устройства не найдены',
                    'text' => 'Измените фильтр или добавьте первое устройство в помещение.',
                    'action' => MDJUI::button('Добавить устройство', array('icon' => 'plus-lg', 'size' => 'sm')),
                )) ?>
            </div>
            <div class="md-card">
                <div class="md-card__body">
                    <div class="md-ui-skeleton-row">
                        <?= MDJUI::skeleton(array('variant' => 'circle', 'width' => '32px', 'height' => '32px')) ?>
                        <div class="md-ui-skeleton-lines">
                            <?= MDJUI::skeleton(array('width' => '46%')) ?>
                            <?= MDJUI::skeleton(array('width' => '72%')) ?>
                        </div>
                    </div>
                    <?= MDJUI::skeleton(array('variant' => 'block', 'height' => '64px')) ?>
                    <div class="md-ui-skeleton-row">
                        <?= MDJUI::skeleton(array('width' => '38%')) ?>
                        <?= MDJUI::skeleton(array('width' => '22%')) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="md-ui-divider-demo">
            <?= MDJUI::sectionHeading(array('eyebrow' => 'Секция', 'title' => 'Заголовок секции', 'description' => 'Отделяет смысловой блок без тяжёлой рамки.', 'actions' => MDJUI::button('Действие', array('variant' => 'secondary', 'size' => 'sm')))) ?>
            <hr class="md-ui-divider">
            <div class="md-ui-divider-row"><span>Разделитель с подписью</span></div>
        </div>
    <?php }); ?>
</section>


