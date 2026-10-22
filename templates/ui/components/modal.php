<?php
if (!class_exists('MDJUI', false)) { http_response_code(404); return; }
$props += array('id' => '', 'title' => '', 'footer' => '', 'size' => '', 'attrs' => array());
if ($props['id'] === '') return;
$attrs = $props['attrs'];
$attrs += array('class' => 'modal fade md-modal', 'id' => $props['id'], 'tabindex' => '-1', 'aria-labelledby' => $props['id'] . '-title', 'aria-hidden' => 'true');
$size = in_array($props['size'], array('sm', 'lg', 'xl'), true) ? ' modal-' . $props['size'] : '';
?>
<div<?= MDJUI::attrs($attrs) ?>>
    <div class="modal-dialog modal-dialog-centered<?= $size ?>"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5" id="<?= MDJUI::e($props['id']) ?>-title"><?= MDJUI::e($props['title']) ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button></div>
        <div class="modal-body"><?= $slot ?></div>
        <?php if ($props['footer'] !== ''): ?><div class="modal-footer"><?= $props['footer'] ?></div><?php endif; ?>
    </div></div>
</div>
