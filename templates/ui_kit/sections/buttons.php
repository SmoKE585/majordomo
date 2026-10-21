<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/** Buttons and actions. */
?>
<section class="md-ui-section" id="buttons" data-md-doc-section>
    <div class="md-ui-section__intro">
        <?= MDJUI::sectionHeading(array(
            'eyebrow' => '02 · Actions',
            'title' => 'Кнопки и действия',
            'description' => 'Один primary-action на экран. Остальные действия — secondary, outline или ghost. Опасные операции всегда danger и требуют подтверждения.',
            'level' => 2,
        )) ?>
        <span class="md-ui-section__kicker">MDJUI::button()</span>
    </div>

    <?php md_ui_demo(array(
        'title' => 'Варианты',
        'desc' => 'Primary — главное действие, secondary — нейтральное, outline — второстепенное, ghost — действие внутри плотной строки, danger — необратимая операция.',
        'api' => 'variant: primary · secondary · outline · ghost · danger',
        'code' => <<<'CODE'
<?= MDJUI::button('Сохранить', ['variant' => 'primary', 'icon' => 'check2']) ?>
<?= MDJUI::button('Отмена', ['variant' => 'secondary']) ?>
<?= MDJUI::button('Настроить', ['variant' => 'outline', 'icon' => 'sliders']) ?>
<?= MDJUI::button('Подробнее', ['variant' => 'ghost', 'icon_after' => 'arrow-right']) ?>
<?= MDJUI::button('Удалить', ['variant' => 'danger', 'icon' => 'trash3']) ?>
CODE,
    ), function () { ?>
        <?= MDJUI::button('Сохранить', array('variant' => 'primary', 'icon' => 'check2')) ?>
        <?= MDJUI::button('Отмена', array('variant' => 'secondary')) ?>
        <?= MDJUI::button('Настроить', array('variant' => 'outline', 'icon' => 'sliders')) ?>
        <?= MDJUI::button('Подробнее', array('variant' => 'ghost', 'icon_after' => 'arrow-right')) ?>
        <?= MDJUI::button('Удалить', array('variant' => 'danger', 'icon' => 'trash3')) ?>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Размеры',
        'desc' => 'Small — внутри таблиц и тулбаров, medium — по умолчанию, large — в пустых состояниях и на мобильных экранах.',
        'api' => 'size: sm · md · lg',
        'code' => <<<'CODE'
<?= MDJUI::button('Small', ['size' => 'sm']) ?>
<?= MDJUI::button('Medium') ?>
<?= MDJUI::button('Large', ['size' => 'lg', 'icon' => 'plus-lg']) ?>
CODE,
    ), function () { ?>
        <?= MDJUI::button('Small', array('size' => 'sm')) ?>
        <?= MDJUI::button('Medium') ?>
        <?= MDJUI::button('Large', array('size' => 'lg', 'icon' => 'plus-lg')) ?>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Иконки и icon button',
        'desc' => 'Иконка слева — для действия, справа — для перехода. Icon button обязателен с aria-label; подсказка появляется автоматически.',
        'api' => 'icon · icon_after · MDJUI::iconButton()',
        'code' => <<<'CODE'
<?= MDJUI::button('Добавить устройство', ['icon' => 'plus-lg']) ?>
<?= MDJUI::button('Открыть', ['icon_after' => 'arrow-up-right']) ?>

<?= MDJUI::iconButton('pencil', ['label' => 'Редактировать']) ?>
<?= MDJUI::iconButton('trash3', ['label' => 'Удалить', 'variant' => 'danger']) ?>
CODE,
    ), function () { ?>
        <?= MDJUI::button('Добавить устройство', array('icon' => 'plus-lg')) ?>
        <?= MDJUI::button('Открыть', array('variant' => 'secondary', 'icon_after' => 'arrow-up-right')) ?>
        <span class="md-ui-demo__divider" aria-hidden="true"></span>
        <?= MDJUI::iconButton('pencil', array('label' => 'Редактировать')) ?>
        <?= MDJUI::iconButton('copy', array('label' => 'Дублировать')) ?>
        <?= MDJUI::iconButton('trash3', array('label' => 'Удалить', 'variant' => 'danger')) ?>
        <?= MDJUI::iconButton('arrow-clockwise', array('label' => 'Обновить', 'variant' => 'secondary')) ?>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Состояния: hover, focus, active, disabled, loading',
        'desc' => 'Состояния не отключают семантику: disabled остаётся читаемым, loading блокирует повторный клик и сохраняет ширину кнопки.',
        'api' => 'loading · disabled · is-demo-*',
        'code' => <<<'CODE'
<?= MDJUI::button('Сохраняем…', ['loading' => true]) ?>
<?= MDJUI::button('Недоступно', ['disabled' => true]) ?>
<?= MDJUI::button('Удалить', ['variant' => 'danger', 'loading' => true]) ?>
CODE,
    ), function () { ?>
        <?= MDJUI::button('Hover', array('class' => 'is-demo-hover')) ?>
        <?= MDJUI::button('Focus', array('class' => 'is-demo-focus')) ?>
        <?= MDJUI::button('Active', array('class' => 'is-demo-active')) ?>
        <?= MDJUI::button('Сохраняем…', array('loading' => true)) ?>
        <?= MDJUI::button('Недоступно', array('disabled' => true)) ?>
        <?= MDJUI::button('Удаляем…', array('variant' => 'danger', 'loading' => true)) ?>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Button group и dropdown button',
        'desc' => 'Group объединяет взаимоисключающие действия. Dropdown — вторичные действия, которые не должны занимать место в тулбаре.',
        'api' => 'MDJUI::buttonGroup() · Bootstrap dropdown',
        'code' => <<<'CODE'
<?= MDJUI::buttonGroup(
  MDJUI::button('День', ['variant' => 'ghost', 'class' => 'is-active']) .
  MDJUI::button('Неделя', ['variant' => 'ghost']) .
  MDJUI::button('Месяц', ['variant' => 'ghost'])
) ?>

<div class="dropdown">
  <button class="md-btn md-btn--secondary" data-bs-toggle="dropdown">
    Действия <?= MDJUI::icon('chevron-down') ?>
  </button>
  <ul class="dropdown-menu md-menu">...</ul>
</div>
CODE,
    ), function () { ?>
        <?= MDJUI::buttonGroup(
            MDJUI::button('День', array('variant' => 'ghost', 'class' => 'is-active', 'size' => 'sm')) .
            MDJUI::button('Неделя', array('variant' => 'ghost', 'size' => 'sm')) .
            MDJUI::button('Месяц', array('variant' => 'ghost', 'size' => 'sm')),
            array('label' => 'Период')
        ) ?>
        <div class="dropdown">
            <button type="button" class="md-btn md-btn--secondary" data-bs-toggle="dropdown" aria-expanded="false">
                <?= MDJUI::icon('lightning-charge') ?>
                <span class="md-btn__label">Действия</span>
                <?= MDJUI::icon('chevron-down', array('class' => 'md-btn__icon md-btn__icon--after')) ?>
            </button>
            <ul class="dropdown-menu md-menu">
                <li><button type="button" class="dropdown-item"><?= MDJUI::icon('play-fill') ?>Запустить сцену</button></li>
                <li><button type="button" class="dropdown-item"><?= MDJUI::icon('copy') ?>Дублировать</button></li>
                <li><button type="button" class="dropdown-item"><?= MDJUI::icon('download') ?>Экспорт</button></li>
                <li><hr class="dropdown-divider"></li>
                <li><button type="button" class="dropdown-item text-danger"><?= MDJUI::icon('trash3') ?>Удалить</button></li>
            </ul>
        </div>
        <div class="dropdown">
            <button type="button" class="md-btn md-btn--primary" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="md-btn__label">Создать</span>
                <?= MDJUI::icon('chevron-down', array('class' => 'md-btn__icon md-btn__icon--after')) ?>
            </button>
            <ul class="dropdown-menu md-menu">
                <li><button type="button" class="dropdown-item"><?= MDJUI::icon('lightbulb') ?>Устройство</button></li>
                <li><button type="button" class="dropdown-item"><?= MDJUI::icon('magic') ?>Сценарий</button></li>
                <li><button type="button" class="dropdown-item"><?= MDJUI::icon('house') ?>Помещение</button></li>
            </ul>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Кнопки в контексте',
        'desc' => 'В карточке действия стоят справа в заголовке; в форме primary — справа снизу, destructive отделён разделителем.',
        'api' => 'card actions · form actions',
        'canvas_class' => 'md-ui-demo__canvas--block',
    ), function () { ?>
        <div class="md-ui-button-context">
            <?= MDJUI::card(array(
                'title' => 'Кухня · Основной свет',
                'subtitle' => 'Обновлено 12 секунд назад',
                'actions' => MDJUI::button('Открыть', array('variant' => 'secondary', 'size' => 'sm')),
            ), '<div class="md-ui-button-context__body">' . MDJUI::badge('Онлайн', 'success', array('dot' => true)) . '<span>Яркость 72% · Zigbee</span></div>') ?>
            <div class="md-ui-form-actions">
                <?= MDJUI::button('Удалить', array('variant' => 'ghost', 'icon' => 'trash3', 'class' => 'md-btn--danger-ghost')) ?>
                <span class="ms-auto"></span>
                <?= MDJUI::button('Отмена', array('variant' => 'secondary')) ?>
                <?= MDJUI::button('Сохранить', array('variant' => 'primary', 'icon' => 'check2')) ?>
            </div>
        </div>
    <?php }); ?>
</section>


