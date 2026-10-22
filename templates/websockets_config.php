<?php
use MajorDoMo\Core\Config;
use MajorDoMo\Core\Http\Session;

$config = array('enabled' => !Config::bool('DISABLE_WEBSOCKETS'),
    'url' => Config::string('WEBSOCKETS_PUBLIC_URL') ?: Session::cookiePath() . 'ws',
    'ticketUrl' => Session::cookiePath() . 'admin.php?ajax_panel=1&op=websocket');
?>
<script>window.MDJWebSocketConfig = <?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;</script>
