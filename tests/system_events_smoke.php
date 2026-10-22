<?php

/**
 * System event layer smoke test: no database, no application bootstrap.
 *
 * Run: php tests/system_events_smoke.php
 */

putenv('APP_ENV=testing');
require_once dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Database;
use MajorDoMo\Core\Events\EventBus;
use MajorDoMo\Core\Events\SystemEvents;

$failures = 0;
function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        echo 'FAIL: ' . $message . PHP_EOL;
        return;
    }
    echo 'ok: ' . $message . PHP_EOL;
}

/** Minimal legacy connection: only the queries EventBus issues. */
final class EventBusFixtureConnection
{
    public array $rows = array();
    public int $lastId = 0;
    public array $queries = array();

    public function Insert(string $table, array &$record): int
    {
        $record['ID'] = ++$this->lastId;
        $this->rows[$record['ID']] = $record;
        return $record['ID'];
    }

    public function Update(string $table, array $record, string $key = 'ID'): bool
    {
        $this->rows[(int)$record[$key]] = $record;
        return true;
    }

    public function Select(string $sql): array
    {
        $this->queries[] = $sql;
        $rows = array_values($this->rows);
        if (str_contains($sql, 'PROCESSED=0')) {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => (int)$row['PROCESSED'] === 0));
        }
        if (preg_match('/WHERE ID>(\d+)/', $sql, $match)) {
            $rows = array_values(array_filter($rows, static fn(array $row): bool => (int)$row['ID'] > (int)$match[1]));
        }
        usort($rows, static fn(array $a, array $b): int => (int)$a['ID'] <=> (int)$b['ID']);
        if (preg_match('/LIMIT (\d+)/', $sql, $match)) {
            $rows = array_slice($rows, 0, (int)$match[1]);
        }
        return $rows;
    }

    public function SelectOne(string $sql): ?array
    {
        $this->queries[] = $sql;
        if (str_contains($sql, 'MAX(ID)')) {
            $max = $this->lastId;
            if (preg_match('/WHERE NAME IN \((.+)\)/', $sql, $match)) {
                $names = array_map(static fn(string $name): string => trim($name, "' "), explode(',', $match[1]));
                $max = 0;
                foreach ($this->rows as $row) {
                    if (in_array($row['NAME'], $names, true)) {
                        $max = max($max, (int)$row['ID']);
                    }
                }
            }
            return array('ID' => $max);
        }
        if (str_contains($sql, 'COUNT(*)')) {
            return array('C' => count($this->rows));
        }
        return null;
    }

    public function Exec(string $sql, bool $ignoreErrors = false): mixed
    {
        $this->queries[] = $sql;
        if (preg_match('/UPDATE system_events SET PROCESSED=1.*WHERE ID=(\d+)/s', $sql, $match)) {
            $id = (int)$match[1];
            if (isset($this->rows[$id])) {
                $this->rows[$id]['PROCESSED'] = 1;
            }
        }
        if (str_contains($sql, 'DELETE FROM system_events')) {
            $this->rows = array();
        }
        return true;
    }

    public function DbSafe1($value): string
    {
        return addslashes((string)$value);
    }

    public function DbSafe($value): string
    {
        return addslashes((string)$value);
    }

    public function AffectedRows(): int
    {
        return 0;
    }
}

$connection = new EventBusFixtureConnection();
EventBus::reset();
EventBus::useDatabase(new Database($connection));
EventBus::disableMirroring();

$received = array();
EventBus::on(EventBus::ANY, static function (string $name, array $payload, string $source) use (&$received): void {
    $received[] = array($name, $payload, $source);
}, 0);
EventBus::on(SystemEvents::MODULE_INSTALLED, static function (string $name, array $payload) use (&$received): void {
    $received[] = array('priority:' . $name, $payload, 'high');
}, 10);

$id = SystemEvents::moduleInstalled('zigbeedev');
check($id === 1, 'EventBus stores an event and returns its id');
check($connection->rows[1]['NAME'] === SystemEvents::MODULE_INSTALLED, 'Event name is stored');
check($connection->rows[1]['SOURCE'] === 'market', 'Event source is stored');
check(json_decode($connection->rows[1]['PAYLOAD'], true)['module'] === 'zigbeedev', 'Payload is stored as JSON');
check($received[0][0] === 'priority:' . SystemEvents::MODULE_INSTALLED, 'Higher priority listener runs first');
check(count($received) === 2, 'Every listener (specific and wildcard) receives the event');

$received = array();
SystemEvents::moduleRemoved('zigbeedev', array('cycle_zigbeedev'));
SystemEvents::settingsChanged('general', array('THEME', 'THEME', 'TIMEZONE'));
SystemEvents::cycleScriptRemoved('cycle_zigbeedev');
check($connection->lastId === 4, 'Every helper records an event');
check(EventBus::lastId() === 4, 'lastId returns the highest event id');
check(EventBus::lastId(array(SystemEvents::MODULE_REMOVED)) === 2, 'lastId filters by event names');
check(count(EventBus::since(3)) === 1 && EventBus::since(3)[0]['name'] === SystemEvents::CYCLE_SCRIPT_REMOVED, 'since() returns newer events only');
check(EventBus::since(1)[1]['payload']['keys'] === array('THEME', 'TIMEZONE'), 'Payload keeps unique keys');

$drained = 0;
function processSubscriptions(string $name, array $details): void
{
    $GLOBALS['delivered'][] = array($name, $details);
}
$GLOBALS['delivered'] = array();
$drained = EventBus::drain(10);
check($drained === 4, 'drain() processes queued events');
check(count($GLOBALS['delivered']) === 4, 'Queued events reach module subscribers');
check((int)$connection->rows[4]['PROCESSED'] === 1, 'Processed events are acknowledged');
check(EventBus::drain(10) === 0, 'Second drain does not redeliver acknowledged events');

$throwing = 0;
EventBus::on(SystemEvents::SYSTEM_UPDATED, static function (): void {
    throw new RuntimeException('fixture listener failure');
});
$before = EventBus::lastId();
SystemEvents::systemUpdated('aaa', 'bbb', 'ALPHA');
check(EventBus::lastId() === $before + 1, 'A failing listener does not lose the event');

try {
    EventBus::emit('lowercase name', array());
    check(false, 'Invalid event names are rejected');
} catch (InvalidArgumentException $error) {
    check(true, 'Invalid event names are rejected');
}

EventBus::reset();
check(EventBus::lastId() === 0, 'Without a database the event log degrades quietly');

if ($failures > 0) {
    echo PHP_EOL . $failures . ' check(s) failed' . PHP_EOL;
    exit(1);
}
echo PHP_EOL . 'System events smoke tests passed' . PHP_EOL;
