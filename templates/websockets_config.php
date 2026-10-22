<?php
use MajorDoMo\Core\Http\Session;

// 2026-10-22 — билет WebSocket выдаёт служебный API панели, не admin.php.
$config = array('url' => Session::cookiePath() . 'ws',
    'ticketUrl' => Session::cookiePath() . 'api/v1/panel/websocket-ticket');
?>
<script>window.MDJWebSocketConfig = <?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;</script>
