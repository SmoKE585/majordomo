<?php
// 2026-10-24 — доступность и нагрузка Redis в штатной карточке дизайн-системы.
require_once ROOT . 'lib/ui/MDJUI.php';
ob_start();
?>
<dl class="md-dash-metrics">
    <div><dt>Время ответа</dt><dd data-md-dash-field="redis-latency">—</dd></div>
    <div><dt>Операции</dt><dd data-md-dash-field="redis-ops">—</dd></div>
    <div><dt>Соединения</dt><dd data-md-dash-field="redis-clients">—</dd></div>
    <div><dt>Ключи в текущей БД</dt><dd data-md-dash-field="redis-keys">—</dd></div>
</dl>
<dl class="md-dash-metrics">
    <div><dt>Память</dt><dd data-md-dash-field="redis-memory">—</dd></div>
    <div><dt>Лимит памяти</dt><dd data-md-dash-field="redis-limit">—</dd></div>
    <div><dt>Время работы</dt><dd data-md-dash-field="redis-uptime">—</dd></div>
    <div><dt>Вытеснено с запуска</dt><dd data-md-dash-field="redis-evicted">—</dd></div>
</dl>
<p class="md-dash-note" data-md-dash-field="redis-note">Ожидание данных…</p>
<?php
echo MDJUI::card(array(
    'title' => 'Redis',
    'subtitle' => 'Доступность, память и нагрузка',
    'heading_level' => 2,
    'actions' => MDJUI::badge('Проверяем…', 'neutral', array('attrs' => array('data-md-dash-redis-status' => ''))),
), ob_get_clean());
