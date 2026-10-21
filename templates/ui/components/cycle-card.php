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
    'unknown' => array('Неизвестно', 'neutral'),
);

$status = isset($statusMap[$props['status']]) ? $props['status'] : 'unknown';
$statusText = $props['status_text'] !== '' ? $props['status_text'] : $statusMap[$status][0];
$statusTone = $statusMap[$status][1];
$title = $props['title'] !== '' ? $props['title'] : $props['name'];
$isStopped = $status === 'stopped' || $status === 'unknown';
$actionsDisabled = !empty($props['disabled']);

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

$renderActions = function ($withLabels) use ($props, $isStopped, $actionsDisabled) {
    ob_start();
    if ($props['log_url'] !== '') {
        if ($withLabels) {
            echo '<a class="md-btn md-btn--ghost md-btn--sm" href="' . MDJUI::e($props['log_url']) . '">' . MDJUI::icon('file-text') . '<span class="md-btn__label">Лог</span></a>';
        } else {
            echo '<a class="md-icon-btn md-icon-btn--sm" href="' . MDJUI::e($props['log_url']) . '" title="Лог" aria-label="Лог">' . MDJUI::icon('file-text') . '</a>';
        }
    }

    if ($withLabels) {
        echo '<button type="button" class="md-btn md-btn--primary md-btn--sm" data-md-cycle-command="start"' . (($isStopped && !$actionsDisabled) ? '' : ' hidden') . '>' . MDJUI::icon('player-play') . '<span class="md-btn__label">Запуск</span></button>';
        echo '<button type="button" class="md-btn md-btn--secondary md-btn--sm" data-md-cycle-command="restart"' . ((!$isStopped && !$actionsDisabled) ? '' : ' hidden') . '>' . MDJUI::icon('refresh') . '<span class="md-btn__label">Рестарт</span></button>';
        echo '<button type="button" class="md-btn md-btn--danger md-btn--sm" data-md-cycle-command="stop"' . ((!$isStopped && !$actionsDisabled) ? '' : ' hidden') . '>' . MDJUI::icon('player-stop') . '<span class="md-btn__label">Остановить</span></button>';
    } else {
        echo '<button type="button" class="md-icon-btn md-icon-btn--sm md-icon-btn--primary" data-md-cycle-command="start" title="Запуск" aria-label="Запуск"' . (($isStopped && !$actionsDisabled) ? '' : ' hidden') . '>' . MDJUI::icon('player-play') . '</button>';
        echo '<button type="button" class="md-icon-btn md-icon-btn--sm md-icon-btn--secondary" data-md-cycle-command="restart" title="Рестарт" aria-label="Рестарт"' . ((!$isStopped && !$actionsDisabled) ? '' : ' hidden') . '>' . MDJUI::icon('refresh') . '</button>';
        echo '<button type="button" class="md-icon-btn md-icon-btn--sm md-icon-btn--danger" data-md-cycle-command="stop" title="Остановить" aria-label="Остановить"' . ((!$isStopped && !$actionsDisabled) ? '' : ' hidden') . '>' . MDJUI::icon('player-stop') . '</button>';
    }
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
