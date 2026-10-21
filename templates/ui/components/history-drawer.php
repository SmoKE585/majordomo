<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * History drawer.
 *
 * The component renders a hidden template. A trigger with
 * `data-md-history-open` mounts it into the shared admin drawer host and the
 * `40_history.js` module loads and renders the data.
 *
 * Props: id, eyebrow, title, subtitle, default_range, ranges, class, attrs,
 * demo_data (inline JSON payload for UI Kit / static previews).
 */
$props += array(
    'id' => 'md-history-drawer',
    'eyebrow' => 'История',
    'title' => 'История изменений',
    'subtitle' => '',
    'default_range' => '24h',
    'ranges' => array(
        '3h' => '3 ч',
        '12h' => '12 ч',
        '24h' => '24 ч',
        '7d' => '7 дн.',
        '30d' => '30 дн.',
        '90d' => '90 дн.',
    ),
    'class' => '',
    'attrs' => array(),
    'demo_data' => array(),
);

$ranges = is_array($props['ranges']) && $props['ranges'] ? $props['ranges'] : array('24h' => '24 ч');
$defaultRange = (string)$props['default_range'];
if (!isset($ranges[$defaultRange])) {
    $defaultRange = (string)key($ranges);
}

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['id'] = (string)$props['id'];
$attrs['class'] = MDJUI::cx(array('md-history', $props['class']));
$attrs['data-md-history'] = true;
$attrs['data-md-history-default-range'] = $defaultRange;
$attrs['aria-label'] = (string)$props['title'];
if (!empty($props['demo_data']) && is_array($props['demo_data'])) {
    $demoJson = json_encode($props['demo_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($demoJson !== false) {
        $attrs['data-md-history-demo'] = $demoJson;
    }
}
?>
<div<?= MDJUI::attrs($attrs) ?> hidden>
    <div class="md-history__toolbar">
        <div class="md-tabs md-tabs--compact md-history__ranges" role="group" aria-label="Период истории">
            <?php foreach ($ranges as $rangeKey => $rangeLabel): ?>
            <button type="button"
                    class="nav-link<?= (string)$rangeKey === $defaultRange ? ' active' : '' ?>"
                    data-md-history-range="<?= MDJUI::e($rangeKey) ?>"
                    aria-pressed="<?= (string)$rangeKey === $defaultRange ? 'true' : 'false' ?>"><?= MDJUI::e($rangeLabel) ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="md-history__state" data-md-history-state aria-live="polite" hidden></div>

    <div class="md-history__content" data-md-history-content hidden>
        <section class="md-card md-card--compact md-history__summary">
            <div class="md-card__body md-history__summary-body">
                <div class="md-history__current">
                    <span class="md-history__current-label">Текущее значение</span>
                    <strong class="md-history__current-value" data-md-history-current>—</strong>
                    <div class="md-history__current-meta" data-md-history-current-meta></div>
                </div>
                <div class="md-history__badges" data-md-history-badges></div>
            </div>
        </section>

        <section class="md-history__stats" data-md-history-stats aria-label="Статистика за период"></section>

        <section class="md-card md-card--compact md-history__chart-card">
            <header class="md-card__head">
                <div class="md-card__titles">
                    <div class="md-card__eyebrow">Динамика</div>
                    <h2 class="md-card__title">График</h2>
                </div>
                <div class="md-card__actions">
                    <span class="md-badge md-badge--outline" data-md-history-range-badge></span>
                </div>
            </header>
            <div class="md-card__body">
                <div class="md-history__chart" data-md-history-chart role="img" aria-label="График изменения значения"></div>
            </div>
        </section>

        <section class="md-card md-card--compact md-history__events-card">
            <header class="md-card__head">
                <div class="md-card__titles">
                    <div class="md-card__eyebrow">События</div>
                    <h2 class="md-card__title">Изменения <span class="md-badge" data-md-history-count>0</span></h2>
                </div>
            </header>
            <div class="md-card__body md-history__events-body">
                <div class="md-table-wrap md-history__table">
                    <div class="md-table__scroll">
                        <table class="md-table">
                            <thead>
                            <tr>
                                <th scope="col">Когда</th>
                                <th scope="col">Значение</th>
                                <th scope="col">Источник</th>
                            </tr>
                            </thead>
                            <tbody data-md-history-rows></tbody>
                        </table>
                    </div>
                </div>
                <div class="md-history__table-footer">
                    <span class="md-history__count-label" data-md-history-count-label></span>
                    <nav aria-label="Страницы истории">
                        <ul class="pagination md-pagination" data-md-history-pagination hidden></ul>
                    </nav>
                </div>
            </div>
        </section>
    </div>

    <div class="md-history__actions" data-md-history-actions hidden>
        <button type="button" class="md-btn md-btn--secondary md-btn--sm" data-md-history-refresh title="Обновить данные">
            <?= MDJUI::icon('refresh') ?><span class="md-btn__label">Обновить</span>
        </button>
        <button type="button" class="md-btn md-btn--primary md-btn--sm" data-md-history-download title="Скачать текущую страницу в CSV">
            <?= MDJUI::icon('download') ?><span class="md-btn__label">CSV</span>
        </button>
    </div>
</div>
