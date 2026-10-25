<?php
// 2026-10-25 — содержимое центра уведомлений для общего drawer shell.
require_once ROOT . 'lib/ui/MDJUI.php';
?>
<div id="mdNotificationsContent" hidden>
    <div class="md-notifications-toolbar">
        <p class="text-body-secondary mb-0" data-md-notifications-status role="status">Загружаем уведомления…</p>
        <?= MDJUI::button('Обновить', array('variant' => 'secondary', 'size' => 'sm', 'icon' => 'refresh', 'attrs' => array('data-md-notifications-refresh' => ''))) ?>
    </div>
    <div class="md-notifications-list" data-md-notifications-list></div>
    <div class="md-notifications-toolbar" data-md-notifications-pages hidden>
        <?= MDJUI::button('Назад', array('variant' => 'secondary', 'size' => 'sm', 'attrs' => array('data-md-notifications-page' => '-1'))) ?>
        <span class="text-body-secondary small" data-md-notifications-range></span>
        <?= MDJUI::button('Далее', array('variant' => 'secondary', 'size' => 'sm', 'attrs' => array('data-md-notifications-page' => '1'))) ?>
    </div>
</div>
