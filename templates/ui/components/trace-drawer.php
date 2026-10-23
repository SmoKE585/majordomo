<?php
// 2026-10-23 — копирование загруженных цепочек вместе с кодом и параметрами.
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Property trace / causal-chain drawer.
 *
 * The component is a hidden template: `41_trace.js` mounts it into the shared
 * drawer host, loads `op=property_trace` and renders the run timeline. Keeping
 * the shell server-rendered means the drawer inherits the same header, footer,
 * Esc handling and mobile behaviour as the history drawer.
 *
 * Props: id, eyebrow, title, subtitle, default_range, ranges, class, attrs,
 * demo_data.
 */
$props += array(
    'id' => 'md-trace-drawer',
    'eyebrow' => 'Цепочка изменений',
    'title' => 'Журнал изменений свойства',
    'subtitle' => '',
    'default_range' => '24h',
    'ranges' => array(
        '3h' => '3 ч',
        '12h' => '12 ч',
        '24h' => '24 ч',
        '7d' => '7 дн.',
        '30d' => '30 дн.',
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
$attrs['class'] = MDJUI::cx(array('md-trace', $props['class']));
$attrs['data-md-trace'] = true;
$attrs['data-md-trace-default-range'] = $defaultRange;
$attrs['aria-label'] = (string)$props['title'];
if (!empty($props['demo_data']) && is_array($props['demo_data'])) {
    $demoJson = json_encode($props['demo_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($demoJson !== false) {
        $attrs['data-md-trace-demo'] = $demoJson;
    }
}
?>
<div<?= MDJUI::attrs($attrs) ?> hidden>
    <div class="md-trace__toolbar">
        <div class="md-tabs md-tabs--compact md-trace__ranges" role="group" aria-label="Период журнала">
            <?php foreach ($ranges as $rangeKey => $rangeLabel): ?>
            <button type="button"
                    class="nav-link<?= (string)$rangeKey === $defaultRange ? ' active' : '' ?>"
                    data-md-trace-range="<?= MDJUI::e($rangeKey) ?>"
                    aria-pressed="<?= (string)$rangeKey === $defaultRange ? 'true' : 'false' ?>"><?= MDJUI::e($rangeLabel) ?></button>
            <?php endforeach; ?>
        </div>
        <span class="md-badge md-badge--outline" data-md-trace-count>0</span>
    </div>

    <div class="md-trace__intro">
        <i class="ti ti-route" aria-hidden="true"></i>
        <p>Каждый запуск показывает, что его стартовало, какие методы и сценарии выполнились и какие свойства изменились. Журнал ведётся всегда; ниже — только фактические изменения значений.</p>
    </div>

    <div class="md-trace__state" data-md-trace-state aria-live="polite" hidden></div>

    <div class="md-trace__content" data-md-trace-content hidden>
        <div class="md-trace__runs" data-md-trace-runs></div>
        <nav class="md-trace__pagination-wrap" aria-label="Страницы журнала">
            <ul class="pagination md-pagination" data-md-trace-pagination hidden></ul>
        </nav>
    </div>

    <div class="md-trace__actions" data-md-trace-actions hidden>
        <button type="button" class="md-btn md-btn--secondary md-btn--sm" data-md-trace-refresh title="Обновить журнал">
            <?= MDJUI::icon('refresh') ?><span class="md-btn__label">Обновить</span>
        </button>
        <button type="button" class="md-btn md-btn--secondary md-btn--sm" data-md-trace-copy-page>
            <?= MDJUI::icon('copy') ?><span class="md-btn__label">Копировать страницу</span>
        </button>
    </div>
</div>
