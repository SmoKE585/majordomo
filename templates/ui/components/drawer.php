<?php
if (!class_exists('MDJUI')) {
    http_response_code(404);
    exit;
}

/**
 * Overlay drawer shell.
 *
 * Props: id, eyebrow, title, subtitle, footer, open, overlay, width, class,
 * attrs, backdrop_attrs, close_attrs, close_label, body_id, footer_id,
 * eyebrow_id, subtitle_id, title_id.
 *
 * The markup is the same shell used by the global admin drawer host:
 * inset panel, compact header with eyebrow/title/subtitle, scrollable body
 * and an optional footer.
 */
$props += array(
    'id' => '',
    'eyebrow' => '',
    'title' => '',
    'subtitle' => '',
    'footer' => '',
    'open' => false,
    'overlay' => true,
    'width' => '',
    'class' => '',
    'attrs' => array(),
    'backdrop_attrs' => array(),
    'close_attrs' => array(),
    'close_label' => 'Закрыть',
    'body_id' => '',
    'footer_id' => '',
    'eyebrow_id' => '',
    'subtitle_id' => '',
    'title_id' => '',
);

$id = (string)$props['id'];
if ($id === '') {
    $id = 'md-drawer-' . substr(md5($props['title'] . '|' . $props['subtitle']), 0, 8);
}
$titleId = (string)$props['title_id'];
if ($titleId === '') {
    $titleId = $id . '-title';
}
$bodyId = (string)$props['body_id'];
$footerId = (string)$props['footer_id'];
$eyebrowId = (string)$props['eyebrow_id'];
$subtitleId = (string)$props['subtitle_id'];

$attrs = is_array($props['attrs']) ? $props['attrs'] : array();
$attrs['id'] = $id;
$attrs['aria-labelledby'] = $titleId;
$attrs['aria-hidden'] = $props['open'] ? 'false' : 'true';
$attrs['tabindex'] = '-1';
$attrs['class'] = MDJUI::cx(array('md-admin-drawer', 'md-drawer', $props['class'], $props['open'] ? 'is-open' : ''));
if ($props['overlay']) {
    $attrs['role'] = 'dialog';
    $attrs['aria-modal'] = 'true';
}
if ((string)$props['width'] !== '') {
    $attrs['style'] = '--md-admin-drawer-width: ' . (string)$props['width'];
}

$backdropAttrs = is_array($props['backdrop_attrs']) ? $props['backdrop_attrs'] : array();
$backdropAttrs['class'] = MDJUI::cx(array('md-admin-drawer-backdrop', $props['open'] ? 'is-open' : ''));

$closeAttrs = is_array($props['close_attrs']) ? $props['close_attrs'] : array();
$closeAttrs['type'] = 'button';
$closeAttrs['class'] = 'md-icon-btn md-admin-drawer__close';
$closeAttrs['data-md-drawer-close'] = true;
$closeAttrs['aria-label'] = (string)$props['close_label'];
?>
<?php if ($props['overlay']): ?>
<div<?= MDJUI::attrs($backdropAttrs) ?> data-md-drawer-backdrop></div>
<?php endif; ?>
<aside<?= MDJUI::attrs($attrs) ?>>
    <div class="md-admin-drawer__header md-drawer__header">
        <div>
            <?php if ($props['eyebrow'] !== ''): ?>
            <div class="md-admin-drawer__eyebrow md-drawer__eyebrow"<?= $eyebrowId !== '' ? ' id="' . MDJUI::e($eyebrowId) . '"' : '' ?>><?= MDJUI::e($props['eyebrow']) ?></div>
            <?php endif; ?>
            <h2 class="md-drawer__title" id="<?= MDJUI::e($titleId) ?>"><?= MDJUI::e($props['title']) ?></h2>
            <?php if ($props['subtitle'] !== ''): ?>
            <div class="md-admin-drawer__subtitle md-drawer__subtitle"<?= $subtitleId !== '' ? ' id="' . MDJUI::e($subtitleId) . '"' : '' ?>><?= MDJUI::e($props['subtitle']) ?></div>
            <?php endif; ?>
        </div>
        <button<?= MDJUI::attrs($closeAttrs) ?>><?= MDJUI::icon('x') ?></button>
    </div>
    <div class="md-admin-drawer__body md-drawer__body"<?= $bodyId !== '' ? ' id="' . MDJUI::e($bodyId) . '"' : '' ?>><?= $slot ?></div>
    <?php if ((string)$props['footer'] !== ''): ?>
    <div class="md-admin-drawer__footer md-drawer__footer"<?= $footerId !== '' ? ' id="' . MDJUI::e($footerId) . '"' : '' ?>><?= $props['footer'] ?></div>
    <?php endif; ?>
</aside>
