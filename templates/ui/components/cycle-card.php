<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Cycle status / control card.
 *
 * Props:
 * - name, title, label, details
 * - status (running|starting|stopping|hang|stopped|unknown), status_text
 * - online (true|false|null), updated, updated_label
 * - auto_restart (true|false|null), disabled
 * - compact, poll, endpoint, log_url
 * - class, attrs
 */
$props += array(
    'name' => '',
    'title' => '',
    'label' => 'Цикл',
    'details' => '',
    'status' => 'unknown',
    'status_text' => '',
    'online' => null,
    'updated' => '',
    'updated_label' => 'Обновлено',
    'auto_restart' => null,
    'disabled' => false,
    'compact' => false,
    'poll' => false,
    'endpoint' => '',
    'log_url' => '',
    'class' => '',
    'attrs' => array(),
);

$statusMap = array(
    'running' => array('Работает', 'success'),
    'starting' => array('Запускается', 'info'),
    'stopping' => array('Выключается', 'warning'),
    'hang' => array('Завис', 'warning'),
    'stopped' => array('Остановлен', 'danger'),
    'disabled' => array('Отключён', 'neutral'),
    'unknown' => array('Неизвестно', 'neutral'),
);

$status = isset($statusMap[$props['status']]) ? $props['status'] : 'unknown';
$statusText = $props['status_text'] !== '' ? $props['status_text'] : $statusMap[$status][0];
$statusTone = $statusMap[$status][1];
$title = $props['title'] !== '' ? $props['title'] : $props['name'];
$isDisabled = $status === 'disabled' || !empty($props['disabled']);
$isStopped = $status === 'stopped' || $status === 'unknown';

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['data-md-cycle'] = true;
$attrs['data-md-cycle-name'] = $props['name'];
$attrs['data-md-cycle-state'] = $status;
if ($props['endpoint'] !== '') {
    $attrs['data-md-cycle-endpoint'] = $props['endpoint'];
}
$attrs['data-md-cycle-poll'] = $props['poll'] ? '1' : '0';
if ($props['log_url'] !== '') {
    $attrs['data-md-cycle-log-url'] = $props['log_url'];
}
$classes = array(
    'md-cycle-card',
    $props['compact'] ? 'md-cycle-card--mini' : 'md-cycle-card--full',
    'is-' . $status,
    $props['class'],
);
$attrs['class'] = MDJUI::cx($classes);

$updatedText = $props['updated'] !== '' ? $props['updated'] : '—';
$onlineText = $props['online'] === null ? '' : ($props['online'] ? 'ONLINE' : 'OFFLINE');
$onlineClass = $props['online'] === null ? '' : ($props['online'] ? 'is-online' : 'is-offline');
$autoRestartText = $props['auto_restart'] === null ? '' : ($props['auto_restart'] ? 'вкл.' : 'выкл.');

$renderActions = function ($withLabels) use ($props, $isStopped, $isDisabled) {
    ob_start();
    if ($props['log_url'] !== '') {
        if ($withLabels) {
            echo '<a class="md-btn md-btn--ghost md-btn--sm" href="' . MDJUI::e($props['log_url']) . '">' . MDJUI::icon('file-text') . '<span class="md-btn__label">Лог</span></a>';
        } else {
            echo '<a class="md-icon-btn md-icon-btn--sm" href="' . MDJUI::e($props['log_url']) . '" title="Лог" aria-label="Лог">' . MDJUI::icon('file-text') . '</a>';
        }
    }

    $button = function (string $command, string $label, string $icon, string $variant, bool $hidden) use ($withLabels): void {
        $hiddenAttr = $hidden ? ' hidden' : '';
        if ($withLabels) {
            echo '<button type="button" class="md-btn md-btn--' . $variant . ' md-btn--sm" data-md-cycle-command="' . $command . '"' . $hiddenAttr . '>'
                . MDJUI::icon($icon) . '<span class="md-btn__label">' . MDJUI::e($label) . '</span></button>';
            return;
        }
        echo '<button type="button" class="md-icon-btn md-icon-btn--sm md-icon-btn--' . $variant . '" data-md-cycle-command="' . $command . '"'
            . ' title="' . MDJUI::e($label) . '" aria-label="' . MDJUI::e($label) . '"' . $hiddenAttr . '>'
            . MDJUI::icon($icon) . '</button>';
    };

    $button('start', 'Запуск', 'player-play', 'primary', !$isStopped || $isDisabled);
    $button('restart', 'Рестарт', 'refresh', 'secondary', $isStopped || $isDisabled);
    $button('stop', 'Остановить', 'player-stop', 'danger', $isStopped || $isDisabled);
    $button('disable', 'Отключить', 'power', 'ghost', $isDisabled);
    $button('enable', 'Включить', 'power', 'primary', !$isDisabled);

    return ob_get_clean();
};
?>
<article<?= MDJUI::attrs($attrs) ?>>
    <?php if ($props['compact']): ?>
        <span class="md-cycle-card__signal" aria-hidden="true"></span>
        <div class="md-cycle-card__main">
            <div class="md-cycle-card__line">
                <span class="md-cycle-card__name"><?= MDJUI::e($title) ?></span>
                <?= MDJUI::badge($statusText, $statusTone, array('attrs' => array('data-md-cycle-status' => true))) ?>
                <span class="md-cycle-card__link <?= MDJUI::e($onlineClass) ?>" data-md-cycle-online><?= MDJUI::e($onlineText !== '' ? $onlineText : '—') ?></span>
            </div>
            <div class="md-cycle-card__hint">
                <span><?= MDJUI::e($props['updated_label']) ?> <strong data-md-cycle-updated><?= MDJUI::e($updatedText) ?></strong></span>
                <?php if ($autoRestartText !== ''): ?><span class="md-cycle-card__sep">·</span><span>Автоперезапуск <strong><?= MDJUI::e($autoRestartText) ?></strong></span><?php endif; ?>
            </div>
        </div>
        <div class="md-cycle-card__actions"><?= $renderActions(false) ?></div>
    <?php else: ?>
        <div class="md-cycle-card__header">
            <div class="md-cycle-card__identity">
                <span class="md-cycle-card__signal" aria-hidden="true"></span>
                <div class="md-cycle-card__titles">
                    <span class="md-cycle-card__eyebrow"><?= MDJUI::e($props['label']) ?></span>
                    <span class="md-cycle-card__name"><?= MDJUI::e($title) ?></span>
                    <?php if ($props['details'] !== ''): ?><span class="md-cycle-card__desc"><?= MDJUI::e($props['details']) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="md-cycle-card__badges">
                <?= MDJUI::badge($statusText, $statusTone, array('attrs' => array('data-md-cycle-status' => true))) ?>
                <span class="md-cycle-card__link <?= MDJUI::e($onlineClass) ?>" data-md-cycle-online><?= MDJUI::e($onlineText !== '' ? $onlineText : '—') ?></span>
            </div>
        </div>
        <div class="md-cycle-card__footer">
            <div class="md-cycle-card__meta">
                <span class="md-cycle-card__meta-item"><span><?= MDJUI::e($props['updated_label']) ?></span><strong data-md-cycle-updated><?= MDJUI::e($updatedText) ?></strong></span>
                <?php if ($autoRestartText !== ''): ?>
                    <span class="md-cycle-card__meta-item"><span>Автоперезапуск</span><strong><?= MDJUI::e($autoRestartText) ?></strong></span>
                <?php endif; ?>
            </div>
            <div class="md-cycle-card__actions"><?= $renderActions(true) ?></div>
        </div>
    <?php endif; ?>
</article>
