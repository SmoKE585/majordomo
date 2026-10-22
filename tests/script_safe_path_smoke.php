<?php

/** 2026-10-22 — фоновые helpers используют очередь ядра без HTTP и API. */
namespace MajorDoMo\Core {
    final class Core
    {
        public static array $calls = array();
        public static function audit() { return null; }
        public static function queue(): object
        {
            return new class {
                public function enqueueRuntime($operation, $params, $target = null): bool
                {
                    Core::$calls[] = array($operation, $params, $target);
                    return true;
                }
            };
        }
    }
}

namespace {
    function startMeasure($name) {}
    function endMeasure($name, $flag = 0) {}
    function DebMes($message, $folder = '', $level = '') {}
    function callAPI(...$args) { throw new RuntimeException('Internal helper must not call API'); }
    function getURLBackground(...$args) { throw new RuntimeException('Internal helper must not call HTTP'); }
    function postURLBackground(...$args) { throw new RuntimeException('Internal helper must not call HTTP'); }
    require dirname(__DIR__) . '/lib/core/Helpers/Scripts.php';
    require dirname(__DIR__) . '/lib/core/Helpers/Messages.php';
    require dirname(__DIR__) . '/lib/core/Helpers/Subscriptions.php';

    unset($_SERVER['REQUEST_URI']);
    $_GET = array();
    $checks = 0;
    function check($condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
        $GLOBALS['checks']++;
    }
    foreach (array('Мой сценарий', 'Test script', 'A+B / тест', 7) as $name) {
        check(runScriptSafe($name, array('value' => false)) === true, 'Script accepted');
        $call = end(\MajorDoMo\Core\Core::$calls);
        check($call[0] === 'script' && $call[2] === (string)$name && $call[1]['value'] === false, 'Original script title and parameters retained');
    }
    check(saySafe('Фоновое сообщение', 2, 3, 'fixture') === true, 'Say accepted');
    check(sayReplySafe('Ответ', 1, 'вопрос') === true, 'Reply accepted');
    check(sayToSafe('Терминал', 0, 'Кухня') === true, 'Terminal message accepted');
    check(processSubscriptionsSafe('COMMAND', array('value' => false)) === 1, 'Subscriptions accepted');
    check(processSubscriptionsSafe('EMPTY', '') === 1, 'Empty subscription details normalized');
    $calls = \MajorDoMo\Core\Core::$calls;
    check(array_column(array_slice($calls, 4), 0) === array('say', 'sayreply', 'sayto', 'subscriptions', 'subscriptions'), 'Separate direct core operations');
    check($calls[4][1] === array('message' => 'Фоновое сообщение', 'level' => 2, 'member_id' => 3, 'source' => 'fixture'), 'Message parameters retained');
    check($calls[7][1]['details']['value'] === false && $calls[8][1]['details'] === array(), 'Subscription details retained');
    echo 'Background helpers smoke: ' . $checks . " passed\n";
}
