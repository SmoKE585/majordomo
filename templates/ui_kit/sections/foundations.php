<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/** Foundations: tokens, typography, spacing, radii, elevation, icons. */
$colors = array(
    array('Фон', '--md-ui-bg', 'bg'),
    array('Поверхность', '--md-ui-surface', 'surface'),
    array('Поверхность 2', '--md-ui-surface-2', 'surface-2'),
    array('Поверхность 3', '--md-ui-surface-3', 'surface-3'),
    array('Граница', '--md-ui-border', 'border'),
    array('Текст', '--md-ui-text', 'text'),
    array('Текст soft', '--md-ui-text-soft', 'text-soft'),
    array('Muted', '--md-ui-muted', 'muted'),
    array('Primary', '--md-ui-primary', 'primary'),
    array('Success', '--md-ui-success', 'success'),
    array('Warning', '--md-ui-warning', 'warning'),
    array('Danger', '--md-ui-danger', 'danger'),
    array('Info', '--md-ui-info', 'info'),
);

$typography = array(
    array('Display', 'md-ui-type--display', 'Rubik 34 / 1.08 · 600', 'Заголовок страницы'),
    array('H1', 'md-ui-type--h1', 'Rubik 28 / 1.15 · 600', 'Раздел документации'),
    array('H2', 'md-ui-type--h2', 'Rubik 22 / 1.25 · 600', 'Заголовок карточки'),
    array('H3', 'md-ui-type--h3', 'Rubik 18 / 1.3 · 600', 'Подзаголовок'),
    array('Body', 'md-ui-type--body', 'Rubik 14 / 1.5 · 400', 'Основной текст интерфейса'),
    array('Small', 'md-ui-type--small', 'Rubik 13 / 1.45 · 400', 'Подписи и описания'),
    array('Caption', 'md-ui-type--caption', 'Rubik 11 / 1.4 · 500', 'Метки, статусы, таблицы'),
    array('Code', 'md-ui-type--mono', 'Mono 12 / 1.6 · 400', 'MDJUI::button()'),
);

$spacing = array(4, 8, 12, 16, 20, 24, 32, 40, 48);
$radii = array(
    array('XS', 'var(--md-ui-radius-xs)', '6 px'),
    array('SM', 'var(--md-ui-radius-sm)', '9 px'),
    array('MD', 'var(--md-ui-radius)', '12 px'),
    array('LG', 'var(--md-ui-radius-md)', '14 px'),
    array('XL', 'var(--md-ui-radius-lg)', '18 px'),
    array('Pill', 'var(--md-ui-radius-pill)', '999 px'),
);
$controlSizes = array(
    array('Small', '30 px', 'var(--md-ui-control-sm)'),
    array('Medium', '36 px', 'var(--md-ui-control-md)'),
    array('Large', '44 px', 'var(--md-ui-control-lg)'),
);
$icons = isset($icons) && is_array($icons) ? $icons : array();
$iconCatalog = MDJUI::iconCatalog();
$allIcons = array_values(array_unique(array_merge($icons, $iconCatalog)));
$iconCatalogJson = json_encode($allIcons, JSON_UNESCAPED_SLASHES);
?>
<section class="md-ui-section" id="foundations" data-md-doc-section>
    <div class="md-ui-section__intro">
        <?= MDJUI::sectionHeading(array(
            'eyebrow' => '01 · Foundations',
            'title' => 'Основы дизайн-системы',
            'description' => 'Токены, типографика и сетка, из которых собираются все компоненты админки. Значения заданы в css/admin/ui-system.css и используются через CSS-переменные.',
            'level' => 2,
        )) ?>
        <span class="md-ui-section__kicker">tokens.css</span>
    </div>

    <?php md_ui_demo(array(
        'title' => 'Цветовая палитра и семантические цвета',
        'desc' => 'Семантика важнее оттенка: primary — действия, success — норма, warning — деградация, danger — ошибка. В тёмной теме значения меняются автоматически.',
        'api' => 'var(--md-ui-*)',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
:root / body.md-admin-shell {
  --md-ui-primary: #2f7fbd;
  --md-ui-success: #12915c;
  --md-ui-warning: #b25e09;
  --md-ui-danger: #c4322b;
}

/* В компонентах используйте только токены: */
.my-panel {
  color: var(--md-ui-text);
  background: var(--md-ui-surface);
  border: 1px solid var(--md-ui-border);
}
CODE,
    ), function () use ($colors) { ?>
        <div class="md-ui-token-grid">
            <?php foreach ($colors as $color): ?>
                <div class="md-ui-swatch">
                    <span class="md-ui-swatch__color" style="--swatch: var(<?= MDJUI::e($color[1]) ?>)"></span>
                    <span class="md-ui-swatch__name"><?= MDJUI::e($color[0]) ?></span>
                    <span class="md-ui-swatch__value"><?= MDJUI::e($color[1]) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="md-ui-note md-ui-note--spaced">
            <?= MDJUI::icon('info-circle') ?>
            <span>Не задавайте hex-цвета в разметке. Для статусов используйте <code>md-badge--success</code>, <code>md-status--online</code> и семантические токены.</span>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Типографика',
        'desc' => 'Rubik — единственное семейство для интерфейса. Заголовки плотнее по трекингу, служебные метки — мельче и с большим межбуквенным интервалом.',
        'api' => 'md-ui-type--*',
        'canvas_class' => 'md-ui-demo__canvas--block',
    ), function () use ($typography) { ?>
        <?php foreach ($typography as $type): ?>
            <div class="md-ui-type-row">
                <span class="md-ui-type-row__sample <?= MDJUI::e($type[1]) ?>"><?= MDJUI::e($type[3]) ?></span>
                <span class="md-ui-type-row__meta"><?= MDJUI::e($type[2]) ?></span>
            </div>
        <?php endforeach; ?>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Шкала отступов',
        'desc' => 'Шаг 4 px. Внутренние отступы карточек — 16–18 px, между блоками — 12–16 px, между секциями — 24–32 px.',
        'api' => '--md-ui-space-*',
        'canvas_class' => 'md-ui-demo__canvas--block',
    ), function () use ($spacing) { ?>
        <div class="md-ui-scale">
            <?php foreach ($spacing as $space): ?>
                <div class="md-ui-scale__row">
                    <span class="md-ui-scale__value"><?= (int)$space ?> px</span>
                    <span class="md-ui-scale__bar" style="--md-scale: <?= (int)$space ?>px"></span>
                    <span>--md-ui-space-<?= (int)($space / 4) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Радиусы и контрольные размеры',
        'desc' => 'Радиусы 6–18 px. Высота контролов фиксирована: 30 / 36 / 44 px, чтобы кнопки, поля и селекты стояли в одной строке без оптических правок.',
        'api' => '--md-ui-radius-* · --md-ui-control-*',
        'canvas_class' => 'md-ui-demo__canvas--block',
    ), function () use ($radii, $controlSizes) { ?>
        <div class="md-ui-radius-grid">
            <?php foreach ($radii as $radius): ?>
                <div class="md-ui-radius">
                    <span class="md-ui-radius__box" style="--radius: <?= MDJUI::e($radius[1]) ?>"></span>
                    <span><?= MDJUI::e($radius[0]) ?> · <?= MDJUI::e($radius[2]) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="md-ui-control-sizes">
            <?php foreach ($controlSizes as $control): ?>
                <div class="md-ui-control-size">
                    <span class="md-ui-control-size__bar" style="--height: <?= MDJUI::e($control[2]) ?>"></span>
                    <span><strong><?= MDJUI::e($control[0]) ?></strong> · <?= MDJUI::e($control[1]) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Тени и границы',
        'desc' => 'Плоский интерфейс: тень только отделяет поверхность от фона, а не создаёт декоративный объём. Граница — основной разделитель.',
        'api' => '--md-ui-shadow-*',
        'canvas_class' => 'md-ui-demo__canvas--block',
    ), function () { ?>
        <div class="md-ui-elevation-grid">
            <div class="md-ui-elevation md-ui-elevation--xs"><strong>xs</strong><span>плоские карточки, таблицы</span></div>
            <div class="md-ui-elevation md-ui-elevation--sm"><strong>sm</strong><span>hover, popup-триггеры</span></div>
            <div class="md-ui-elevation md-ui-elevation--pop"><strong>pop</strong><span>dropdown, tooltip, toast</span></div>
        </div>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Система иконок · Tabler Icons',
        'desc' => 'Канонический набор — Tabler Icons 3.49: 5 247 outline-иконок, 24 px, единый stroke. Bootstrap Icons остаётся только для legacy-совместимости glyphicon. Нажмите на иконку, чтобы скопировать класс.',
        'api' => 'MDJUI::icon(\'bulb\')',
        'canvas_class' => 'md-ui-demo__canvas--block',
        'code' => <<<'CODE'
<?= MDJUI::icon('bulb') ?>

<!-- В обычной разметке -->
<i class="ti ti-bulb" aria-hidden="true"></i>

<!-- Иконка внутри кнопки -->
<?= MDJUI::button('Включить', ['icon' => 'power']) ?>

<!-- Поиск и каталог: /admin.php?action=ui_kit#foundations -->
CODE,
    ), function () use ($iconCatalogJson) { ?>
        <div class="md-ui-icon-browser" x-data="mdIconBrowser()">
            <div class="md-ui-icon-browser__toolbar">
                <div class="md-searchbar">
                    <?= MDJUI::icon('search', array('class' => 'md-searchbar__icon')) ?>
                    <input class="md-searchbar__input"
                           type="search"
                           x-model.debounce.120ms="query"
                           @input="limit = 120"
                           placeholder="Поиск: thermometer, lightbulb, wifi…"
                           aria-label="Поиск по иконкам">
                    <kbd class="md-searchbar__kbd">5 247</kbd>
                </div>
                <div class="md-ui-icon-browser__count" x-text="matchCount + ' из ' + total"></div>
            </div>
            <div class="md-ui-icon-grid md-ui-icon-grid--browser">
                <template x-for="icon in visible" :key="icon">
                    <button type="button" class="md-ui-icon" @click="pick(icon)" :title="'ti ti-' + icon">
                        <i class="ti" :class="'ti-' + icon" aria-hidden="true"></i>
                        <span x-text="icon"></span>
                    </button>
                </template>
            </div>
            <div class="md-ui-icon-browser__empty" x-show="matchCount === 0">Ничего не найдено. Попробуйте другое слово.</div>
            <div class="md-ui-icon-browser__footer">
                <span x-text="'Показано ' + visible.length + ' из ' + matchCount"></span>
                <button type="button" class="md-btn md-btn--secondary md-btn--sm" x-show="visible.length < matchCount" @click="more()">
                    Показать ещё
                </button>
            </div>
            <noscript><div class="md-ui-note">Для поиска по каталогу иконок включите JavaScript.</div></noscript>
        </div>
        <script type="application/json" id="mdIconCatalog"><?= $iconCatalogJson ?></script>
    <?php }); ?>

    <?php md_ui_demo(array(
        'title' => 'Правила светлой и тёмной темы',
        'desc' => 'Компоненты не знают, какая тема активна: они читают токены. Переключение темы меняет data-bs-theme на html и сохраняется в cookie.',
        'api' => '[data-bs-theme="dark"]',
        'canvas_class' => 'md-ui-demo__canvas--block',
    ), function () { ?>
        <div class="md-ui-theme-rules">
            <ul class="md-ui-list">
                <li><?= MDJUI::icon('check2-circle') ?><span>Используйте <code>var(--md-ui-surface)</code> и <code>var(--md-ui-text)</code> вместо белого фона и чёрного текста.</span></li>
                <li><?= MDJUI::icon('check2-circle') ?><span>Проверяйте контраст иконок и muted-текста на тёмной поверхности.</span></li>
                <li><?= MDJUI::icon('check2-circle') ?><span>Для статусов используйте семантические пары <code>--md-ui-success</code> / <code>--md-ui-success-soft</code>.</span></li>
            </ul>
            <ul class="md-ui-list md-ui-list--no">
                <li><?= MDJUI::icon('x-circle') ?><span><code>background: #fff</code> и <code>color: #000</code> в компоненте.</span></li>
                <li><?= MDJUI::icon('x-circle') ?><span>Отдельная «тёмная версия» компонента с другим HTML.</span></li>
                <li><?= MDJUI::icon('x-circle') ?><span>Инверсия через <code>filter</code> для целых блоков.</span></li>
            </ul>
        </div>
    <?php }); ?>
</section>


