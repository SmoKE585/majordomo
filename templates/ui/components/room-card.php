<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Room / device group card.
 *
 * Props: name, icon, devices, online, temperature, humidity, active, state,
 * href, class, attrs.
 */
$props += array(
    'name' => '',
    'icon' => 'home',
    'devices' => 0,
    'online' => 0,
    'temperature' => '',
    'humidity' => '',
    'active' => '',
    'state' => 'online',
    'href' => '',
    'class' => '',
    'attrs' => array(),
);

$devices = max(0, (int)$props['devices']);
$online = max(0, min($devices, (int)$props['online']));
$percent = $devices > 0 ? round($online / $devices * 100) : 0;

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['class'] = MDJUI::cx(array('md-room', 'md-room--' . $props['state'], $props['class']));
?>
<article<?= MDJUI::attrs($attrs) ?>>
    <header class="md-room__head">
        <span class="md-room__icon"><?= MDJUI::icon($props['icon']) ?></span>
        <div class="md-room__identity">
            <h3 class="md-room__name">
                <?php if ($props['href'] !== ''): ?><a href="<?= MDJUI::e($props['href']) ?>"><?= MDJUI::e($props['name']) ?></a><?php else: ?><?= MDJUI::e($props['name']) ?><?php endif; ?>
            </h3>
            <div class="md-room__meta"><?= (int)$online ?> из <?= (int)$devices ?> онлайн</div>
        </div>
        <?= MDJUI::status($props['state'], '') ?>
    </header>
    <div class="md-room__stats">
        <?php if ($props['temperature'] !== ''): ?><span><?= MDJUI::icon('thermometer-half') ?><?= MDJUI::e($props['temperature']) ?></span><?php endif; ?>
        <?php if ($props['humidity'] !== ''): ?><span><?= MDJUI::icon('droplet-half') ?><?= MDJUI::e($props['humidity']) ?></span><?php endif; ?>
    </div>
    <?= MDJUI::progress($percent, array('tone' => $online === $devices ? 'success' : ($online === 0 ? 'danger' : 'warning'), 'size' => 'sm')) ?>
    <?php if ($props['active'] !== ''): ?><div class="md-room__active"><?= MDJUI::e($props['active']) ?></div><?php endif; ?>
</article>


