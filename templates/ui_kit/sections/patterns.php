<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/** Layout patterns and contribution rules. */
?>
<section class="md-ui-section" id="patterns" data-md-doc-section>
    <div class="md-ui-section__intro">
        <?= MDJUI::sectionHeading(array(
            'eyebrow' => '09 · Patterns',
            'title' => 'Паттерны страниц',
            'description' => 'Как собрать типовую страницу админки из готовых блоков: заголовок, KPI, таблица, карточка устройства и форма. Эти правила важнее вкусовых предпочтений.',
            'level' => 2,
        )) ?>
        <span class="md-ui-section__kicker">composition rules</span>
    </div>

    <?php md_ui_demo(array(
        'title' => 'Каркас страницы модуля',
        'desc' => 'Сверху — заголовок с одним primary-action. Ниже — KPI или фильтры, затем основной контент. Не оборачивайте страницу в декоративный контейнер.',
        'api' => 'md-ui-page · md-section-head · md-kpi · md-table-wrap',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<div class="md-ui-page">
  <?= MDJUI::sectionHeading([
    'eyebrow' => 'Устройства',
    'title' => 'Все устройства',
    'description' => '19 устройств · 17 онлайн',
    'actions' => MDJUI::button('Добавить', ['icon' => 'plus-lg']),
  ]) ?>

  <div class="md-ui-kpi-grid">...</div>

  <div class="md-table-wrap" data-md-table>...</div>
</div>
CODE,
    ), function () { ?>
        <div class="md-ui-page-skeleton">
            <?= MDJUI::sectionHeading(array(
                'eyebrow' => 'Устройства',
                'title' => 'Все устройства',
                'description' => '19 устройств · 17 онлайн · 2 требуют внимания',
                'actions' => MDJUI::button('Добавить устройство', array('icon' => 'plus-lg')),
            )) ?>
            <div class="md-ui-kpi-grid md-ui-kpi-grid--compact">
                <?= MDJUI::kpi(array('label' => 'Онлайн', 'value' => '17', 'unit' => 'из 19', 'icon' => 'broadcast', 'tone' => 'success')) ?>
                <?= MDJUI::kpi(array('label' => 'Требуют внимания', 'value' => '2', 'icon' => 'exclamation-triangle', 'tone' => 'warning')) ?>
                <?= MDJUI::kpi(array('label' => 'Сценарии', 'value' => '6', 'icon' => 'magic', 'tone' => 'primary')) ?>
            </div>
            <div class="md-card">
                <div class="md-card__body md-ui-page-skeleton__table">
                    <?= MDJUI::skeleton(array('width' => '100%')) ?>
                    <?= MDJUI::skeleton(array('width' => '94%')) ?>
                    <?= MDJUI::skeleton(array('width' => '88%')) ?>
                </div>
            </div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Правила компоновки',
        'desc' => 'Три типовых контекста: форма, таблица и карточка. В каждом есть только один главный акцент.',
        'api' => 'forms · tables · cards',
        'canvas_class' => 'md-ui-demo__canvas--block',
    ), function () { ?>
        <div class="md-ui-rules-grid">
            <?= MDJUI::card(array('title' => 'Формы', 'subtitle' => 'Один primary-action', 'icon' => 'input-cursor-text'), '<ul class="md-ui-list"><li>' . MDJUI::icon('check2-circle') . '<span>Label сверху, подсказка под полем.</span></li><li>' . MDJUI::icon('check2-circle') . '<span>Ошибки — рядом с полем.</span></li><li>' . MDJUI::icon('check2-circle') . '<span>Destructive — слева, отделён.</span></li></ul>') ?>
            <?= MDJUI::card(array('title' => 'Таблицы', 'subtitle' => 'Плотность и предсказуемость', 'icon' => 'table'), '<ul class="md-ui-list"><li>' . MDJUI::icon('check2-circle') . '<span>Поиск и фильтры в одной панели.</span></li><li>' . MDJUI::icon('check2-circle') . '<span>Действия — в конце строки.</span></li><li>' . MDJUI::icon('check2-circle') . '<span>Горизонтальный скролл на mobile.</span></li></ul>') ?>
            <?= MDJUI::card(array('title' => 'Карточки', 'subtitle' => 'Один смысл на поверхность', 'icon' => 'layout-text-window'), '<ul class="md-ui-list"><li>' . MDJUI::icon('check2-circle') . '<span>Заголовок + описание + действия.</span></li><li>' . MDJUI::icon('check2-circle') . '<span>Не вкладывайте карточки без причины.</span></li><li>' . MDJUI::icon('check2-circle') . '<span>Одинаковая высота в сетке.</span></li></ul>') ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Do / Don’t',
        'desc' => 'Слева — правила, которые сохраняют систему. Справа — типичные ошибки, из-за которых страницы снова начинают расходиться.',
        'api' => 'design review checklist',
        'canvas_class' => 'md-ui-demo__canvas--block',
    ), function () { ?>
        <div class="md-ui-do-dont">
            <div class="md-ui-do">
                <div class="md-ui-do__title"><?= MDJUI::icon('check2-circle') ?>Делайте так</div>
                <ul class="md-ui-list">
                    <li><?= MDJUI::icon('check2-circle') ?><span>Используйте <code>MDJUI::button()</code>, <code>card()</code>, <code>input()</code>.</span></li>
                    <li><?= MDJUI::icon('check2-circle') ?><span>Проверяйте 390 px и тёмную тему.</span></li>
                    <li><?= MDJUI::icon('check2-circle') ?><span>Показывайте loading и empty state.</span></li>
                    <li><?= MDJUI::icon('check2-circle') ?><span>Оставляйте один primary-action.</span></li>
                </ul>
            </div>
            <div class="md-ui-dont">
                <div class="md-ui-do__title"><?= MDJUI::icon('x-circle') ?>Не делайте так</div>
                <ul class="md-ui-list md-ui-list--no">
                    <li><?= MDJUI::icon('x-circle') ?><span>Новые <code>style=""</code>, свои hex-цвета и шрифты.</span></li>
                    <li><?= MDJUI::icon('x-circle') ?><span>Свои кнопки, селекты, модалки и drawers.</span></li>
                    <li><?= MDJUI::icon('x-circle') ?><span>Bootstrap 3-атрибуты <code>data-toggle</code>.</span></li>
                    <li><?= MDJUI::icon('x-circle') ?><span>Тяжёлые UI-фреймворки и вторую систему иконок.</span></li>
                </ul>
            </div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Чек-лист новой страницы',
        'desc' => 'Проверьте перед завершением. Если пункт не выполняется, страница ещё не готова к ревью.',
        'api' => 'ready for review',
        'canvas_class' => 'md-ui-demo__canvas--block',
    ), function () { ?>
        <div class="md-ui-checklist">
            <?php
            $checks = array(
                'Использованы существующие компоненты и токены, нет дублирующих стилей.',
                'Один primary-action, действия справа или в action-row.',
                'Формы имеют label, hint и inline-ошибки.',
                'Таблица обёрнута в md-table__scroll и работает на 390 px.',
                'Есть loading, empty и error состояния.',
                'Проверены светлая и тёмная темы, hover/focus/disabled.',
                'Интерактивные элементы доступны с клавиатуры и имеют aria-label.',
                'Нет нового jQuery-кода и inline-обработчиков.',
            );
            foreach ($checks as $index => $check): ?>
                <label class="md-check md-ui-checklist__item">
                    <input class="md-check__input" type="checkbox" data-md-raw-checkbox <?= $index < 5 ? 'checked' : '' ?>>
                    <span class="md-check__box" aria-hidden="true"><?= MDJUI::icon('check2') ?></span>
                    <span class="md-check__text"><span class="md-check__label"><?= MDJUI::e($check) ?></span></span>
                </label>
            <?php endforeach; ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Как добавить новый компонент',
        'desc' => 'Сначала ищем существующий. Если компонента действительно нет — добавляем в общую библиотеку, а не на страницу.',
        'api' => 'templates/ui/components + ui-system.css',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
1. Проверьте этого страницу и docs/agents/DESIGN_SYSTEM.md.
2. Создайте templates/ui/components/my-widget.php.
3. Добавьте метод в lib/ui/MDJUI.php.
4. Опишите состояния и токены в css/admin/ui-system.css.
5. Добавьте демо в templates/ui_kit/sections.
6. Обновите docs/agents/DESIGN_SYSTEM.md.

/* Компонент получает $props и $slot */
<article class="md-my-widget" <?= MDJUI::attrs($props['attrs']) ?>>
  <?= MDJUI::e($props['title']) ?>
</article>
CODE,
    ), function () { ?>
        <div class="md-ui-steps">
            <div class="md-ui-step"><span class="md-ui-step__num">1</span><div><strong>Проверьте</strong><p>UI Kit и docs/agents/DESIGN_SYSTEM.md — возможно, компонент уже есть.</p></div></div>
            <div class="md-ui-step"><span class="md-ui-step__num">2</span><div><strong>Создайте</strong><p><code>templates/ui/components/*.php</code> + метод в <code>MDJUI</code>.</p></div></div>
            <div class="md-ui-step"><span class="md-ui-step__num">3</span><div><strong>Опишите</strong><p>Токены и состояния в <code>ui-system.css</code>, без локальных hex.</p></div></div>
            <div class="md-ui-step"><span class="md-ui-step__num">4</span><div><strong>Покажите</strong><p>Демо в UI Kit и обновите документацию.</p></div></div>
        </div>
    <?php }); ?>

    <div class="md-ui-page-footer">
        <span>UI Kit — часть админки MajorDoMo. Изменения компонентов влияют на все страницы.</span>
        <a href="#foundations">Наверх <?= MDJUI::icon('arrow-up') ?></a>
    </div>
</section>



