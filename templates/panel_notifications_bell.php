<?php
// 2026-10-25 — общий вход в уведомления возле профиля и в компактной шапке.
require_once ROOT . 'lib/ui/MDJUI.php';
echo MDJUI::render('button', array(
    'variant' => 'ghost', 'icon' => 'bell', 'class' => 'md-notifications-bell',
    'attrs' => array('data-md-notifications-open' => '', 'aria-label' => 'Уведомления модулей', 'title' => 'Уведомления модулей'),
), '<span class="md-badge md-badge--danger md-notifications-bell__count" data-md-notifications-count hidden aria-hidden="true">0</span>');
