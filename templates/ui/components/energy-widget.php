<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Energy Usage Widget.
 *
 * Props: today, yesterday, delta, cost, peak, usage (label/value/tone), series,
 * class, attrs.
 */
$props += array(
    'today' => '',
    'yesterday' => '',
    'delta' => '',
    'cost' => '',
    'peak' => '',
    'usage' => array(),
    'series' => array(),
    'class' => '',
    'attrs' => array(),
);

$usage = is_array($props['usage']) ? $props['usage'] : array();
$total = 0;
foreach ($usage as $item) {
    $total += isset($item['value']) ? (float)$item['value'] : 0;
}
$total = $total > 0 ? $total : 1;

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-energy', $props['class']));
?>
<article<?= MDJUI::attrs($attrs) ?>>
    <header class="md-energy__head">
        <div>
            <h3 class="md-energy__title">Энергопотребление</h3>
            <p class="md-energy__subtitle">Сегодня · все устройства</p>
        </div>
        <?= MDJUI::badge('Онлайн-учёт', 'success', array('dot' => true)) ?>
    </header>
    <div class="md-energy__summary">
        <div class="md-energy__value"><?= MDJUI::e($props['today']) ?></div>
        <div class="md-energy__delta <?= strpos((string)$props['delta'], '−') === 0 ? 'is-down' : 'is-up' ?>">
            <?= MDJUI::icon(strpos((string)$props['delta'], '−') === 0 ? 'arrow-down-right' : 'arrow-up-right') ?>
            <?= MDJUI::e($props['delta']) ?> <span>к вчера</span>
        </div>
    </div>
    <div class="md-energy__stack" role="img" aria-label="Структура энергопотребления">
        <?php foreach ($usage as $item): ?>
            <span class="md-energy__segment md-energy__segment--<?= MDJUI::e(isset($item['tone']) ? $item['tone'] : 'neutral') ?>" style="width:<?= MDJUI::e(round((float)$item['value'] / $total * 100, 1)) ?>%" title="<?= MDJUI::e($item['label'] . ': ' . $item['value'] . '%') ?>"></span>
        <?php endforeach; ?>
    </div>
    <ul class="md-energy__legend">
        <?php foreach ($usage as $item): ?>
            <li><span class="md-energy__dot md-energy__dot--<?= MDJUI::e(isset($item['tone']) ? $item['tone'] : 'neutral') ?>"></span><?= MDJUI::e($item['label']) ?><strong><?= MDJUI::e($item['value']) ?>%</strong></li>
        <?php endforeach; ?>
    </ul>
    <?php if (!empty($props['series'])): ?>
        <div class="md-energy__chart">
            <?= MDJUI::sparkline(array(
                'series' => $props['series'],
                'tone' => 'primary',
                'height' => 54,
                'label' => 'За 24 часа',
                'value' => $props['peak'] !== '' ? 'пик ' . $props['peak'] : '',
            )) ?>
        </div>
    <?php endif; ?>
    <footer class="md-energy__footer">
        <span><?= MDJUI::icon('wallet2') ?>Расходы <strong><?= MDJUI::e($props['cost']) ?></strong></span>
        <span><?= MDJUI::icon('clock-history') ?>Вчера <strong><?= MDJUI::e($props['yesterday']) ?></strong></span>
    </footer>
</article>

