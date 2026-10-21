<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Device Card — smart-home device summary.
 *
 * Props: name, room, type, icon, state, state_label, value, value_label, tone,
 * updated, connection, signal, actions (HTML), href, class, attrs.
 */
$props += array(
    'name' => '',
    'room' => '',
    'type' => '',
    'icon' => 'cpu',
    'state' => 'online',
    'state_label' => '',
    'value' => '',
    'value_label' => '',
    'tone' => 'primary',
    'updated' => '',
    'connection' => '',
    'signal' => null,
    'actions' => '',
    'href' => '',
    'class' => '',
    'attrs' => array(),
);

$tone = in_array($props['tone'], array('primary', 'success', 'warning', 'danger', 'info', 'neutral'), true)
    ? $props['tone']
    : 'primary';
$signal = $props['signal'];
if ($signal === null) {
    $signalIcon = 'antenna-bars-5';
} elseif ($signal >= 75) {
    $signalIcon = 'antenna-bars-5';
} elseif ($signal >= 50) {
    $signalIcon = 'antenna-bars-4';
} elseif ($signal >= 25) {
    $signalIcon = 'antenna-bars-3';
} elseif ($signal > 0) {
    $signalIcon = 'antenna-bars-2';
} else {
    $signalIcon = 'antenna-bars-1';
}

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-device', 'md-device--' . $props['state'], $props['class']));
?>
<article<?= MDJUI::attrs($attrs) ?>>
    <header class="md-device__head">
        <span class="md-device__icon md-device__icon--<?= MDJUI::e($tone) ?>"><?= MDJUI::icon($props['icon']) ?></span>
        <div class="md-device__identity">
            <h3 class="md-device__name">
                <?php if ($props['href'] !== ''): ?><a href="<?= MDJUI::e($props['href']) ?>"><?= MDJUI::e($props['name']) ?></a><?php else: ?><?= MDJUI::e($props['name']) ?><?php endif; ?>
            </h3>
            <div class="md-device__meta"><?= MDJUI::e(trim($props['room'] . ($props['room'] !== '' && $props['type'] !== '' ? ' · ' : '') . $props['type'])) ?></div>
        </div>
        <?= MDJUI::status($props['state'], $props['state_label']) ?>
    </header>
    <div class="md-device__body">
        <div class="md-device__value"><?= MDJUI::e($props['value']) ?><?php if ($props['value_label'] !== ''): ?><small><?= MDJUI::e($props['value_label']) ?></small><?php endif; ?></div>
        <?php if ($props['actions'] !== ''): ?><div class="md-device__actions"><?= $props['actions'] ?></div><?php endif; ?>
    </div>
    <footer class="md-device__footer">
        <span class="md-device__updated"><?= MDJUI::icon('clock') ?><?= MDJUI::e($props['updated']) ?></span>
        <span class="md-device__link">
            <?php if ($props['connection'] !== ''): ?><span><?= MDJUI::e($props['connection']) ?></span><?php endif; ?>
            <?= MDJUI::icon($signalIcon, array('class' => 'md-device__signal')) ?>
        </span>
    </footer>
</article>


