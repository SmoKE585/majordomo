<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/** Navigation. */
?>
<section class="md-ui-section" id="navigation" data-md-doc-section>
    <div class="md-ui-section__intro">
        <?= MDJUI::sectionHeading(array(
            'eyebrow' => '04 · Navigation',
            'title' => 'Навигация',
            'description' => 'Табы переключают представление внутри страницы, sidebar — разделы админки, breadcrumbs показывают путь. Все элементы управления доступны с клавиатуры.',
            'level' => 2,
        )) ?>
        <span class="md-ui-section__kicker">Bootstrap tabs · md-tabs · md-sidenav</span>
    </div>

    <?php md_ui_demo(array(
        'title' => 'Tabs: единый паттерн для view_mode',
        'desc' => 'Основной и предпочтительный паттерн — сегментированные md-tabs. Он используется для переключения view_mode в классах, сценариях, X-Ray, настройках и обновлении системы. Варианты --compact и --line — только служебные, для навигации по разделам не применяются.',
        'api' => 'md-tabs',
        'badge' => 'предпочтительно',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<ul class="nav nav-tabs md-tabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabOverview" type="button" role="tab">
      Обзор
    </button>
  </li>
  ...
</ul>
<div class="tab-content">
  <div class="tab-pane fade show active" id="tabOverview" role="tabpanel">...</div>
</div>
CODE,
    ), function () { ?>
        <div class="md-ui-tabs-demo">
            <ul class="nav nav-tabs md-tabs" role="tablist" aria-label="Разделы устройства">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="uiKitTabOverview" data-bs-toggle="tab" data-bs-target="#uiKitTabOverviewPane" type="button" role="tab" aria-controls="uiKitTabOverviewPane" aria-selected="true">
                        <?= MDJUI::icon('layout-text-window') ?>Обзор
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="uiKitTabProperties" data-bs-toggle="tab" data-bs-target="#uiKitTabPropertiesPane" type="button" role="tab" aria-controls="uiKitTabPropertiesPane" aria-selected="false">
                        <?= MDJUI::icon('list-check') ?>Свойства <span class="md-badge">12</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="uiKitTabMethods" data-bs-toggle="tab" data-bs-target="#uiKitTabMethodsPane" type="button" role="tab" aria-controls="uiKitTabMethodsPane" aria-selected="false">
                        <?= MDJUI::icon('braces') ?>Методы
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="uiKitTabHistory" data-bs-toggle="tab" data-bs-target="#uiKitTabHistoryPane" type="button" role="tab" aria-controls="uiKitTabHistoryPane" aria-selected="false">
                        <?= MDJUI::icon('clock-history') ?>История
                    </button>
                </li>
            </ul>
            <div class="tab-content md-ui-tab-content">
                <div class="tab-pane fade show active" id="uiKitTabOverviewPane" role="tabpanel" aria-labelledby="uiKitTabOverview" tabindex="0">
                    <div class="md-ui-tab-example">
                        <span class="md-ui-tab-example__label">Обзор</span>
                        <span>Состояние, связи и последние события устройства.</span>
                    </div>
                </div>
                <div class="tab-pane fade" id="uiKitTabPropertiesPane" role="tabpanel" aria-labelledby="uiKitTabProperties" tabindex="0">
                    <div class="md-ui-tab-example">
                        <span class="md-ui-tab-example__label">Свойства</span>
                        <span>12 свойств · 4 обновлены за последний час.</span>
                    </div>
                </div>
                <div class="tab-pane fade" id="uiKitTabMethodsPane" role="tabpanel" aria-labelledby="uiKitTabMethods" tabindex="0">
                    <div class="md-ui-tab-example">
                        <span class="md-ui-tab-example__label">Методы</span>
                        <span>toggle(), setBrightness(), status().</span>
                    </div>
                </div>
                <div class="tab-pane fade" id="uiKitTabHistoryPane" role="tabpanel" aria-labelledby="uiKitTabHistory" tabindex="0">
                    <div class="md-ui-tab-example">
                        <span class="md-ui-tab-example__label">История</span>
                        <span>Последнее изменение — 12 секунд назад.</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="md-ui-tabs-variants">
            <div class="md-ui-demo__label">Служебные варианты · не для переключения view_mode</div>
            <div class="md-ui-tabs-row">
                <ul class="nav nav-tabs md-tabs md-tabs--compact" role="tablist" aria-label="Период">
                    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#uiKitPeriodDay" type="button" role="tab">День</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#uiKitPeriodWeek" type="button" role="tab">Неделя</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#uiKitPeriodMonth" type="button" role="tab">Месяц</button></li>
                </ul>
                <div class="tab-content md-ui-tab-content md-ui-tab-content--inline">
                    <div class="tab-pane fade show active" id="uiKitPeriodDay" role="tabpanel"><span class="md-badge md-badge--primary">24 точки</span></div>
                    <div class="tab-pane fade" id="uiKitPeriodWeek" role="tabpanel"><span class="md-badge">7 дней</span></div>
                    <div class="tab-pane fade" id="uiKitPeriodMonth" role="tabpanel"><span class="md-badge">30 дней</span></div>
                </div>
            </div>

            <div class="md-ui-tabs-row md-ui-tabs-row--line">
                <ul class="nav nav-tabs md-tabs md-tabs--line" role="tablist" aria-label="Разделы страницы">
                    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#uiKitLineDevices" type="button" role="tab">Устройства</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#uiKitLineScenes" type="button" role="tab">Сценарии</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#uiKitLineEnergy" type="button" role="tab">Энергия</button></li>
                </ul>
                <div class="tab-content md-ui-tab-content md-ui-tab-content--inline">
                    <div class="tab-pane fade show active" id="uiKitLineDevices" role="tabpanel"><span class="md-ui-muted">19 устройств в 6 помещениях.</span></div>
                    <div class="tab-pane fade" id="uiKitLineScenes" role="tabpanel"><span class="md-ui-muted">8 активных сценариев.</span></div>
                    <div class="tab-pane fade" id="uiKitLineEnergy" role="tabpanel"><span class="md-ui-muted">8,4 кВт·ч за сегодня.</span></div>
                </div>
            </div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Breadcrumbs и pagination',
        'desc' => 'Breadcrumbs заканчиваются текущей страницей и не дублируют заголовок. Pagination показывает соседние страницы и всегда имеет prev/next.',
        'api' => 'md-breadcrumb · md-pagination',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<ol class="breadcrumb md-breadcrumb">
  <li class="breadcrumb-item"><a href="?">Панель управления</a></li>
  <li class="breadcrumb-item"><a href="?action=objects">Объекты</a></li>
  <li class="breadcrumb-item active" aria-current="page">Кухня · Основной свет</li>
</ol>

<ul class="pagination md-pagination">
  <li class="page-item disabled"><span class="page-link">‹</span></li>
  <li class="page-item active"><span class="page-link">1</span></li>
  ...
</ul>
CODE,
    ), function () { ?>
        <ol class="breadcrumb md-breadcrumb">
            <li class="breadcrumb-item"><a href="?">Панель управления</a></li>
            <li class="breadcrumb-item"><a href="?action=objects">Объекты</a></li>
            <li class="breadcrumb-item"><a href="?action=objects&amp;view_mode=edit">Кухня</a></li>
            <li class="breadcrumb-item active" aria-current="page">Основной свет</li>
        </ol>
        <ul class="pagination md-pagination" aria-label="Страницы">
            <li class="page-item disabled"><span class="page-link" aria-hidden="true">‹</span></li>
            <li class="page-item active"><span class="page-link">1</span></li>
            <li class="page-item"><a class="page-link" href="#navigation">2</a></li>
            <li class="page-item"><a class="page-link" href="#navigation">3</a></li>
            <li class="page-item"><span class="page-link">…</span></li>
            <li class="page-item"><a class="page-link" href="#navigation">8</a></li>
            <li class="page-item"><a class="page-link" href="#navigation" aria-label="Следующая страница">›</a></li>
        </ul>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Dropdown и context menu',
        'desc' => 'Dropdown — список действий по кнопке. Context menu открывается правым кликом и закрывается по Esc или клику вне меню.',
        'api' => 'Bootstrap dropdown · mdContextMenu',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<div class="dropdown">
  <button class="md-btn md-btn--secondary" data-bs-toggle="dropdown">Действия</button>
  <ul class="dropdown-menu md-menu">
    <li><button class="dropdown-item">Запустить</button></li>
    <li><button class="dropdown-item text-danger">Удалить</button></li>
  </ul>
</div>
CODE,
    ), function () { ?>
        <div class="md-ui-menu-row">
            <div class="dropdown">
                <button type="button" class="md-btn md-btn--secondary" data-bs-toggle="dropdown" aria-expanded="false">
                    <?= MDJUI::icon('three-dots') ?><span class="md-btn__label">Действия</span>
                </button>
                <ul class="dropdown-menu md-menu">
                    <li><button type="button" class="dropdown-item"><?= MDJUI::icon('play-fill') ?>Запустить</button></li>
                    <li><button type="button" class="dropdown-item"><?= MDJUI::icon('pencil') ?>Редактировать</button></li>
                    <li><button type="button" class="dropdown-item"><?= MDJUI::icon('copy') ?>Дублировать</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><button type="button" class="dropdown-item text-danger"><?= MDJUI::icon('trash3') ?>Удалить</button></li>
                </ul>
            </div>
            <div class="md-context-demo" x-data="mdContextMenu()" @contextmenu.prevent="show($event)" @click="hide()" @click.window="hide()" @keydown.enter.prevent="show($event)" @keydown.space.prevent="show($event)" @keydown.escape.window="hide()" tabindex="0" role="button" aria-label="Демо контекстного меню. Нажмите правую кнопку мыши.">
                <span class="md-context-demo__icon"><?= MDJUI::icon('cursor') ?></span>
                <span><strong>Правый клик</strong><small>по этой области</small></span>
                <div class="md-context-menu" x-show="open" x-cloak x-ref="menu" :style="'left:' + x + 'px; top:' + y + 'px'" @click.stop x-transition>
                    <button type="button" @click="choose('Открыть устройство')"><?= MDJUI::icon('box-arrow-up-right') ?>Открыть</button>
                    <button type="button" @click="choose('Скопировать ID')"><?= MDJUI::icon('clipboard') ?>Скопировать ID</button>
                    <button type="button" @click="choose('Запустить сцену')"><?= MDJUI::icon('play-fill') ?>Запустить сцену</button>
                    <button type="button" @click="choose('Удалить устройство')"><?= MDJUI::icon('trash3') ?>Удалить</button>
                </div>
            </div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Sidebar navigation и navigation group',
        'desc' => 'Группы разделяют контекст: устройства, объекты, настройки, система. Активный пункт один, счётчики и бейджи — справа.',
        'api' => 'md-sidenav · md-sidenav__group',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<nav class="md-sidenav" aria-label="Разделы">
  <div class="md-sidenav__group">
    <div class="md-sidenav__label">Устройства</div>
    <a class="md-sidenav__item is-active" href="?action=objects">...</a>
  </div>
</nav>
CODE,
    ), function () { ?>
        <nav class="md-sidenav" aria-label="Демонстрационное меню">
            <div class="md-sidenav__group">
                <div class="md-sidenav__label">Устройства</div>
                <a class="md-sidenav__item is-active" href="#navigation"><?= MDJUI::icon('grid-1x2') ?>Все устройства<span class="md-badge">19</span></a>
                <a class="md-sidenav__item" href="#navigation"><?= MDJUI::icon('lightbulb') ?>Свет<span class="md-badge md-badge--warning">4</span></a>
                <a class="md-sidenav__item" href="#navigation"><?= MDJUI::icon('thermometer-half') ?>Климат<span class="md-badge md-badge--primary">6</span></a>
                <a class="md-sidenav__item" href="#navigation"><?= MDJUI::icon('shield-lock') ?>Безопасность<span class="md-badge md-badge--danger">2</span></a>
            </div>
            <div class="md-sidenav__group">
                <div class="md-sidenav__label">Объекты</div>
                <a class="md-sidenav__item" href="#navigation"><?= MDJUI::icon('box-seam') ?>Объекты</a>
                <a class="md-sidenav__item" href="#navigation"><?= MDJUI::icon('diagram-3') ?>Классы</a>
                <a class="md-sidenav__item" href="#navigation"><?= MDJUI::icon('code-slash') ?>Сценарии</a>
            </div>
            <div class="md-sidenav__group">
                <div class="md-sidenav__label">Настройки</div>
                <a class="md-sidenav__item" href="#navigation"><?= MDJUI::icon('sliders') ?>Общие</a>
                <a class="md-sidenav__item" href="#navigation"><?= MDJUI::icon('people') ?>Пользователи</a>
            </div>
        </nav>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Search bar и command palette',
        'desc' => 'Search bar — локальный поиск по списку. Command palette объединяет переходы и действия, фильтруется по мере ввода и работает с клавиатуры.',
        'api' => 'md-searchbar · mdCommandPalette',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<div class="md-searchbar">
  <i class="ti ti-search md-searchbar__icon" aria-hidden="true"></i>
  <input class="md-searchbar__input" type="search" placeholder="Поиск по панели">
  <kbd class="md-searchbar__kbd">Ctrl K</kbd>
</div>

<button class="md-btn md-btn--primary" data-bs-toggle="modal" data-bs-target="#mdCommandPalette">
  Командная палитра
</button>
CODE,
    ), function () { ?>
        <div class="md-ui-search-demo">
            <div class="md-searchbar">
                <?= MDJUI::icon('search', array('class' => 'md-searchbar__icon')) ?>
                <input class="md-searchbar__input" type="search" placeholder="Поиск по устройствам и сценариям" aria-label="Поиск по устройствам и сценариям">
                <kbd class="md-searchbar__kbd">Ctrl K</kbd>
            </div>
            <button type="button" class="md-btn md-btn--primary" data-bs-toggle="modal" data-bs-target="#mdCommandPalette">
                <?= MDJUI::icon('command') ?><span class="md-btn__label">Открыть палитру</span>
            </button>
        </div>
        <div class="md-ui-note md-ui-note--spaced">
            <?= MDJUI::icon('keyboard') ?>
            <span>В палитре: <span class="md-ui-kbd">↑</span> <span class="md-ui-kbd">↓</span> — навигация, <span class="md-ui-kbd">Enter</span> — выбрать, <span class="md-ui-kbd">Esc</span> — закрыть.</span>
        </div>
    <?php }); ?>
</section>





