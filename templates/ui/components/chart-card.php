<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Chart Card.
 *
 * A standard container for a chart rendered by the approved chart library
 * (ApexCharts). The server renders the frame; page JavaScript fills the canvas.
 *
 * Props: title, subtitle, eyebrow, icon, actions, footer, height, chart_id,
 * chart_key, chart_data, canvas_attrs, class, attrs.
 */
$props += array(
    'title' => '',
    'subtitle' => '',
    'eyebrow' => '',
    'icon' => '',
    'actions' => '',
    'footer' => '',
    'height' => '260px',
    'chart_id' => '',
    'chart_key' => '',
    'chart_data' => array(),
    'canvas_attrs' => array(),
    'class' => '',
    'attrs' => array(),
);

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-chart-card', $props['class']));

$canvasAttrs = is_array($props['canvas_attrs']) ? $props['canvas_attrs'] : array();
$canvasAttrs['class'] = MDJUI::cx(array('md-chart-card__canvas', isset($canvasAttrs['class']) ? $canvasAttrs['class'] : ''));
$canvasAttrs['style'] = 'min-height:' . $props['height'] . ';' . (isset($canvasAttrs['style']) ? $canvasAttrs['style'] : '');
if ($props['chart_id'] !== '') {
    $canvasAttrs['id'] = $props['chart_id'];
}
if ($props['chart_key'] !== '') {
    $canvasAttrs['data-md-chart'] = $props['chart_key'];
}
if (!empty($props['chart_data'])) {
    $canvasAttrs['data-md-chart-data'] = json_encode($props['chart_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
}
$canvasAttrs['role'] = 'img';
$canvasAttrs['aria-label'] = $props['title'] !== '' ? $props['title'] : 'График';
?>
<section<?= MDJUI::attrs($attrs) ?>>
    <?php if ($props['title'] !== '' || $props['actions'] !== '' || $props['eyebrow'] !== ''): ?>
        <header class="md-chart-card__head">
            <div class="md-chart-card__titles">
                <?php if ($props['eyebrow'] !== ''): ?><div class="md-chart-card__eyebrow"><?= MDJUI::e($props['eyebrow']) ?></div><?php endif; ?>
                <?php if ($props['title'] !== ''): ?>
                    <h3 class="md-chart-card__title">
                        <?php if ($props['icon']): ?><?= MDJUI::icon($props['icon']) ?><?php endif; ?>
                        <?= MDJUI::e($props['title']) ?>
                    </h3>
                <?php endif; ?>
                <?php if ($props['subtitle'] !== ''): ?><p class="md-chart-card__subtitle"><?= MDJUI::e($props['subtitle']) ?></p><?php endif; ?>
            </div>
            <?php if ($props['actions'] !== ''): ?><div class="md-chart-card__actions"><?= $props['actions'] ?></div><?php endif; ?>
        </header>
    <?php endif; ?>
    <div class="md-chart-card__body">
        <div<?= MDJUI::attrs($canvasAttrs) ?>></div>
        <?= $slot ?>
    </div>
    <?php if ($props['footer'] !== ''): ?><footer class="md-chart-card__footer"><?= $props['footer'] ?></footer><?php endif; ?>
</section>
