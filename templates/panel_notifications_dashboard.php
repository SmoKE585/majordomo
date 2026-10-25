<?php
// 2026-10-25 — важные непрочитанные уведомления в обзоре системы.
require_once ROOT . 'lib/ui/MDJUI.php';
echo MDJUI::card(array(
    'title' => 'Уведомления модулей', 'icon' => 'bell', 'heading_level' => 2,
    'subtitle' => 'Важные сообщения — первыми. Прочтение отмечается вручную.',
    'actions' => MDJUI::button('Все уведомления', array('variant' => 'secondary', 'size' => 'sm',
        'attrs' => array('data-md-notifications-open' => ''))),
    'attrs' => array('data-md-notifications-dashboard' => ''),
), '<div class="md-notifications-list" data-md-notifications-preview aria-live="polite"><p class="text-body-secondary mb-0">Загружаем уведомления…</p></div>');
