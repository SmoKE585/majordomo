<?php

namespace MajorDoMo\Core;

use \Throwable;

/**
 * Always-on property change journal.
 *
 * One "run" is a causal story: a timer job, a web action, a module callback or
 * a manually invoked method starts it; methods, scripts, module handlers and
 * property writes append steps to it. The trace id is carried through the
 * operations queue in `_trace`, so a chain survives separate PHP processes.
 *
 * Auditing must never break a property write: every database failure is
 * swallowed and reported through ErrorReporter at most once per window. There
 * is intentionally no on/off switch; only retention is configurable.
 *
 * Изменения:
 * 2026-10-23 — снимки выполняемого кода, родительские шаги и корректный JSON при обрезке.
 * 2026-10-23 — поиск ошибки запуска идёт по TRACE_ID (покрыт индексом), а не по RUN_ID:
 *              запрос по RUN_ID не мог использовать индекс и давал полный скан журнала.
 */
final class PropertyAudit
{
    public const MAX_TEXT = 65535;
    public const MAX_EVENTS_PER_RUN = 200;

    private static ?string $traceId = null;
    private static ?int $runId = null;
    private static bool $schemaReady = false;
    private static bool $shutdownRegistered = false;
    private static array $steps = array();

    public function __construct(private Database $db, private ?ErrorReporter $errors = null) {}

    public static function currentTraceId(): string
    {
        return self::$traceId ?? '';
    }

    public static function hasTrace(): bool
    {
        return self::$traceId !== null && self::$traceId !== '';
    }

    public static function leave(): void
    {
        self::$traceId = null;
        self::$runId = null;
        self::$steps = array();
    }

    public static function newTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function normalizeTraceId(string $traceId): string
    {
        $traceId = strtolower(trim($traceId));
        return preg_match('/^[a-f0-9]{32}$/D', $traceId) ? $traceId : '';
    }

    /**
     * Continues a trace created by another process. The run header may not be
     * present after retention cleanup; logEvent() will recreate it lazily.
     */
    public function enter(string $traceId, string $type = '', string $title = '', array $params = array()): bool
    {
        $traceId = self::normalizeTraceId($traceId);
        if ($traceId === '') return false;
        self::$traceId = $traceId;
        self::$runId = null;
        self::$steps = array();
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(function (): void {
                if (self::hasTrace()) {
                    $this->finishRun('done');
                    self::leave();
                }
            });
        }
        if ($type !== '') {
            try {
                $this->ensureRun($traceId, $type, $title, $params);
            } catch (Throwable $e) {
                $this->reportFailure($e, 'enter');
            }
        }
        return true;
    }

    public function adoptRequest(array $params = array()): bool
    {
        if (self::hasTrace()) return false;
        return $this->enter((string)($params['_trace'] ?? $_GET['_trace'] ?? ''));
    }

    /** Starts a run if the current process has no trace. Returns true when it owns the run. */
    public function beginIfMissing(string $type, string $title, array $params = array(), array $context = array()): bool
    {
        if (self::hasTrace()) return false;
        $this->begin($type, $title, $params, $context);
        return true;
    }

    public function begin(string $type, string $title, array $params = array(), array $context = array()): string
    {
        $traceId = self::normalizeTraceId(self::currentTraceId());
        if ($traceId === '') {
            $traceId = self::newTraceId();
            self::$traceId = $traceId;
            self::$runId = null;
        }
        try {
            $created = $this->ensureRun($traceId, $type, $title, $params, $context);
            // Property runs are numerous; the run header already carries the
            // trigger. A separate trigger row is useful for methods/jobs/scripts.
            if ($created && $type !== 'property') {
                $this->logEvent('trigger', $title, array(
                    'status' => 'done',
                    'source' => $this->source(),
                    'params' => $params,
                    'result' => array('started' => true),
                ));
            }
        } catch (Throwable $e) {
            $this->reportFailure($e, 'begin');
        }
        return $traceId;
    }

    /** Adds the current trace id to a queued operation or method call. */
    public static function attach(array &$params): void
    {
        if (self::hasTrace()) $params['_trace'] = self::currentTraceId();
    }

    public function stepStart(string $type, string $title, array $data = array()): ?array
    {
        try {
            $id = $this->logEvent($type, $title, $data, 'running');
            if ($id > 0) self::$steps[$id] = $id;
            return $id > 0 ? array('id' => $id, 'started' => microtime(true)) : null;
        } catch (Throwable $e) {
            $this->reportFailure($e, 'step_start');
            return null;
        }
    }

    public function stepFinish(?array $step, mixed $result = null, ?Throwable $error = null, array $outParams = array()): void
    {
        if (!is_array($step)) return;
        $id = (int)($step['id'] ?? 0);
        if ($id < 1) return;
        try {
            $failed = $error !== null || $result === false || (is_array($result) && array_key_exists('success', $result) && !$result['success']);
            $update = array(
                'ID' => $id,
                'FINISHED' => date('Y-m-d H:i:s'),
                'DURATION_MS' => max(0, (int)round((microtime(true) - (float)($step['started'] ?? microtime(true))) * 1000)),
                'STATUS' => $failed ? 'error' : 'done',
                'RESULT' => $this->json($result),
                'OUT_PARAMS' => $this->json($outParams),
            );
            if ($error) {
                $update['ERROR'] = $this->errorText($error);
            } elseif ($failed) {
                $update['ERROR'] = is_array($result) ? (string)($result['error'] ?? 'Обработчик сообщил об ошибке.') : 'Выполнение вернуло false.';
            }
            $this->db->update('property_audit_events', $update);
        } catch (Throwable $e) {
            $this->reportFailure($e, 'step_finish');
        } finally {
            unset(self::$steps[$id]);
        }
    }

    /**
     * Appends a property change. Caller supplies the property's full name,
     * object/property ids, old/new value, source and the property chain.
     */
    public function propertyChanged(array $data): void
    {
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') return;
        if (!self::hasTrace()) {
            $this->begin('property', $title, array(), array('source' => (string)($data['source'] ?? '')));
        }
        try {
            $this->logEvent('property', $title, array(
                'status' => 'done',
                'object_id' => (int)($data['object_id'] ?? 0),
                'property_id' => (int)($data['property_id'] ?? 0),
                'property_name' => (string)($data['property_name'] ?? $title),
                'old_value' => $data['old_value'] ?? null,
                'new_value' => $data['new_value'] ?? null,
                'source' => (string)($data['source'] ?? ''),
                'chain' => $data['chain'] ?? array(),
                'result' => array('saved' => true),
            ), 'done', date('Y-m-d H:i:s'), 0);
        } catch (Throwable $e) {
            $this->reportFailure($e, 'property');
        }
    }

    /** Adds an explanatory chain event (loop stop, delivery failure, etc.). */
    public function note(string $type, string $title, array $data = array()): void
    {
        if (!self::hasTrace()) return;
        try {
            $this->logEvent($type, $title, array_merge(array('status' => 'warning'), $data), 'warning');
        } catch (Throwable $e) {
            $this->reportFailure($e, 'note');
        }
    }

    public function finishRun(string $status = 'done', mixed $result = null, ?Throwable $error = null): void
    {
        if (!self::hasTrace()) return;
        try {
            $runId = $this->runId();
            if ($runId < 1) return;
            // Успех внешней обёртки не должен скрывать отказ вложенного метода
            // или ошибку предыдущего процесса, продолжавшего эту же цепочку.
            // Ищем по TRACE_ID, а не по RUN_ID: RUN_ID не проиндексирован, и такой
            // запрос MySQL выполнял полным сканом журнала (1–2 с на каждый запуск,
            // что при нескольких воркерах давало 300% CPU). TRACE_ID уникален для
            // запуска и покрыт idx_property_audit_events_trace (TRACE_ID,ID), поэтому
            // здесь читаются только события этого запуска — единицы строк.
            $failure = $this->db->one(
                "SELECT ERROR FROM property_audit_events WHERE TRACE_ID='"
                . $this->db->escape(self::currentTraceId())
                . "' AND STATUS='error' ORDER BY ID LIMIT 1"
            );
            $failed = $error !== null || $failure !== null || $result === false
                || (is_array($result) && array_key_exists('success', $result) && !$result['success']);
            $update = array(
                'ID' => $runId,
                'STATUS' => $failed ? 'error' : $status,
                'FINISHED' => date('Y-m-d H:i:s'),
                'LAST_SEEN' => date('Y-m-d H:i:s'),
            );
            if ($result !== null) $update['RESULT'] = $this->json($result);
            $update['ERROR'] = $error ? $this->errorText($error) : ($failure['ERROR'] ?? ($failed ? 'Обработчик сообщил об ошибке.' : null));
            $this->db->update('property_audit_runs', $update);
        } catch (Throwable $e) {
            $this->reportFailure($e, 'finish');
        }
    }

    /**
     * Returns chronological runs that changed one property.
     *
     * @return array{runs: array<int,array>, pagination: array<string,int|bool>}
     */
    public function tracesForProperty(int $objectId, int $propertyId, int $from, int $to, int $page = 1, int $perPage = 10): array
    {
        $empty = array(
            'runs' => array(),
            'pagination' => array(
                'page' => 1, 'per_page' => $perPage, 'total' => 0, 'pages' => 0,
                'from' => 0, 'to' => 0, 'has_prev' => false, 'has_next' => false,
            ),
        );
        if ($objectId < 1 || $propertyId < 1 || $to < $from) return $empty;
        try {
            $this->ensureSchema();
            $where = "e.OBJECT_ID=" . $objectId . " AND e.PROPERTY_ID=" . $propertyId
                . " AND e.TYPE='property' AND e.ADDED>='" . $this->db->escape(date('Y-m-d H:i:s', $from))
                . "' AND e.ADDED<='" . $this->db->escape(date('Y-m-d H:i:s', $to)) . "'";
            $count = $this->db->one('SELECT COUNT(DISTINCT e.TRACE_ID) AS C FROM property_audit_events e WHERE ' . $where);
            $total = (int)($count['C'] ?? 0);
            $perPage = max(1, min(50, $perPage));
            $page = max(1, $page);
            $pages = $total > 0 ? (int)ceil($total / $perPage) : 0;
            if ($pages > 0 && $page > $pages) $page = $pages;
            $offset = ($page - 1) * $perPage;
            if ($total < 1) {
                $empty['pagination'] = array('page' => 1, 'per_page' => $perPage, 'total' => 0,
                    'pages' => 0, 'from' => 0, 'to' => 0, 'has_prev' => false, 'has_next' => false);
                return $empty;
            }
            $runs = $this->db->select(
                'SELECT r.* FROM property_audit_runs r JOIN ('
                . ' SELECT DISTINCT e.TRACE_ID FROM property_audit_events e WHERE ' . $where
                . ') t ON t.TRACE_ID=r.TRACE_ID ORDER BY r.STARTED DESC,r.ID DESC LIMIT ' . $offset . ',' . $perPage
            );
            if (!$runs) return $empty;
            $traceIds = array_map(static fn($run) => (string)$run['TRACE_ID'], $runs);
            $in = "'" . implode("','", array_map(array($this->db, 'escape'), $traceIds)) . "'";
            $events = $this->db->select('SELECT * FROM property_audit_events WHERE TRACE_ID IN (' . $in . ') ORDER BY ID');
            $grouped = array();
            foreach ($events as $event) {
                $grouped[(string)$event['TRACE_ID']][] = $this->presentEvent($event, $propertyId);
            }
            $result = array();
            foreach ($runs as $run) {
                $result[] = $this->presentRun($run, $grouped[(string)$run['TRACE_ID']] ?? array(), $propertyId);
            }
            return array(
                'runs' => $result,
                'pagination' => array(
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'pages' => $pages,
                    'from' => $offset + 1,
                    'to' => min($total, $offset + $perPage),
                    'has_prev' => $page > 1,
                    'has_next' => $page < $pages,
                ),
            );
        } catch (Throwable $e) {
            $this->reportFailure($e, 'query_property');
            return $empty;
        }
    }

    public function prune(int $days, int $limit = 5000): int
    {
        $days = max(0, $days);
        $limit = max(100, min(50000, $limit));
        if ($days < 1) return 0;
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
        $total = 0;
        $this->ensureSchema();
        for ($batch = 0; $batch < 40; $batch++) {
            $this->db->exec("DELETE FROM property_audit_events WHERE ADDED<'" . $this->db->escape($cutoff) . "' LIMIT " . $limit);
            $deleted = $this->db->affectedRows();
            $total += max(0, $deleted);
            if ($deleted < $limit) break;
        }
        for ($batch = 0; $batch < 20; $batch++) {
            $this->db->exec("DELETE FROM property_audit_runs WHERE LAST_SEEN<'" . $this->db->escape($cutoff) . "' LIMIT " . $limit);
            $deleted = $this->db->affectedRows();
            $total += max(0, $deleted);
            if ($deleted < $limit) break;
        }
        return $total;
    }

    public function ensureSchema(): void
    {
        if (self::$schemaReady) return;
        try {
            if (!$this->db->tableExists('property_audit_runs') || !$this->db->tableExists('property_audit_events')) {
                if (class_exists(Core::class)) Core::migrations()->ensurePropertyAuditSchema();
            }
            self::$schemaReady = true;
        } catch (Throwable $e) {
            // Do not retry DDL on every property write. The next request gets a
            // fresh process anyway; the failure is reported once per window.
            self::$schemaReady = true;
            $this->reportFailure($e, 'schema');
        }
    }

    private function ensureRun(string $traceId, string $type, string $title, array $params = array(), array $context = array()): bool
    {
        $this->ensureSchema();
        $row = $this->db->one("SELECT ID FROM property_audit_runs WHERE TRACE_ID='" . $this->db->escape($traceId) . "' LIMIT 1");
        if ($row) {
            self::$runId = (int)$row['ID'];
            $this->db->exec("UPDATE property_audit_runs SET LAST_SEEN='" . date('Y-m-d H:i:s')
                . "',STATUS='running',FINISHED=NULL WHERE ID=" . self::$runId);
            return false;
        }
        $defaults = array(
            'source' => $this->source(),
            'actor' => $this->actor(),
            'ip' => $this->ip(),
            'request_uri' => $this->requestUri(),
        );
        $context = array_merge($defaults, array_filter($context, static fn($value) => $value !== '' && $value !== null));
        $record = array(
            'TRACE_ID' => $traceId,
            'STARTED' => date('Y-m-d H:i:s'),
            'LAST_SEEN' => date('Y-m-d H:i:s'),
            'STATUS' => 'running',
            'TRIGGER_TYPE' => mb_substr($type, 0, 32),
            'TRIGGER_TITLE' => mb_substr($title, 0, 255),
            'SOURCE' => mb_substr((string)$context['source'], 0, 255),
            'ACTOR' => mb_substr((string)$context['actor'], 0, 255),
            'IP' => mb_substr((string)$context['ip'], 0, 45),
            'REQUEST_URI' => mb_substr((string)$context['request_uri'], 0, 255),
            'PARAMS' => $params ? $this->json($params) : null,
            'EVENTS_COUNT' => 0,
        );
        try {
            $id = $this->db->insert('property_audit_runs', $record);
        } catch (Throwable $e) {
            $row = $this->db->one("SELECT ID FROM property_audit_runs WHERE TRACE_ID='" . $this->db->escape($traceId) . "' LIMIT 1");
            if ($row) {
                self::$runId = (int)$row['ID'];
                return false;
            }
            throw $e;
        }
        if ($id < 1) throw new \RuntimeException('Property audit run was not saved');
        self::$runId = $id;
        return true;
    }

    private function logEvent(string $type, string $title, array $data = array(), string $status = 'running', ?string $finished = null, int $duration = 0): int
    {
        $traceId = self::currentTraceId();
        if ($traceId === '') {
            $traceId = $this->begin($type, $title, $data['params'] ?? array());
        }
        if ($this->runId() < 1) {
            $this->ensureRun($traceId, $type, $title, $data['params'] ?? array());
        }
        $runId = $this->runId();
        $record = array(
            'RUN_ID' => $runId,
            'TRACE_ID' => $traceId,
            'ADDED' => date('Y-m-d H:i:s'),
            'DURATION_MS' => max(0, $duration),
            'TYPE' => mb_substr($type, 0, 32),
            'STATUS' => mb_substr($status, 0, 16),
            'TITLE' => mb_substr($title, 0, 255),
            'SUBTITLE' => mb_substr((string)($data['subtitle'] ?? ''), 0, 255),
            'OBJECT_ID' => (int)($data['object_id'] ?? 0),
            'PROPERTY_ID' => (int)($data['property_id'] ?? 0),
            'PROPERTY_NAME' => mb_substr((string)($data['property_name'] ?? ''), 0, 255),
            'METHOD_ID' => (int)($data['method_id'] ?? 0),
            'CLASS_ID' => (int)($data['class_id'] ?? 0),
            'SCRIPT_ID' => (int)($data['script_id'] ?? 0),
            'MODULE' => mb_substr((string)($data['module'] ?? ''), 0, 100),
            'OLD_VALUE' => $this->text($data['old_value'] ?? null),
            'NEW_VALUE' => $this->text($data['new_value'] ?? null),
            'SOURCE' => mb_substr((string)($data['source'] ?? $this->source()), 0, 255),
            'PARAMS' => $this->eventParams($data),
            'OUT_PARAMS' => isset($data['out_params']) ? $this->json($data['out_params']) : null,
            'RESULT' => isset($data['result']) ? $this->json($data['result']) : null,
            'ERROR' => isset($data['error']) ? $this->text($data['error']) : null,
            'CHAIN' => isset($data['chain']) ? $this->json($data['chain']) : null,
        );
        if ($finished !== null) $record['FINISHED'] = $finished;
        $id = $this->db->insert('property_audit_events', $record);
        if ($id < 1) throw new \RuntimeException('Property audit event was not saved');
        return $id;
    }

    private function runId(): int
    {
        if (self::$runId !== null && self::$runId > 0) return self::$runId;
        $traceId = self::currentTraceId();
        if ($traceId === '') return 0;
        $row = $this->db->one("SELECT ID FROM property_audit_runs WHERE TRACE_ID='" . $this->db->escape($traceId) . "' LIMIT 1");
        self::$runId = (int)($row['ID'] ?? 0);
        return self::$runId;
    }

    /** Служебная оболочка хранится в существующем поле, параметры API остаются прежними. */
    private function eventParams(array $data): ?string
    {
        $parent = self::$steps ? (int)end(self::$steps) : 0;
        $execution = $data['execution'] ?? null;
        if (!$parent && $execution === null) return isset($data['params']) ? $this->json($data['params']) : null;
        if (is_array($execution) && isset($execution['code'])) {
            $code = (string)$execution['code'];
            $execution['code_hash'] = hash('sha256', $code);
            $execution['code_truncated'] = strlen($code) > 24576;
            $execution['code'] = mb_strcut($code, 0, 24576, 'UTF-8');
        }
        $metadata = array(
            'params' => $data['params'] ?? null,
            'parent_event_id' => $parent,
            'execution' => $execution,
        );
        $encoded = $this->json(array('__mdj_audit_step_v1' => $metadata));
        $decoded = $this->decode($encoded);
        // При больших параметрах сохраняем связь шагов и снимок кода,
        // сокращая только диагностическое содержимое, с явной отметкой.
        if (is_array($decoded) && isset($decoded['_truncated'])) {
            $metadata['params'] = array('_truncated' => true, 'preview' => mb_strcut((string)$this->json($metadata['params']), 0, 2048, 'UTF-8'));
            if (isset($metadata['execution']['code']) && strlen($metadata['execution']['code']) > 8192) {
                $metadata['execution']['code'] = mb_strcut($metadata['execution']['code'], 0, 8192, 'UTF-8');
                $metadata['execution']['code_truncated'] = true;
            }
            $encoded = $this->json(array('__mdj_audit_step_v1' => $metadata));
        }
        return $encoded;
    }

    private function presentRun(array $run, array $events, int $targetPropertyId): array
    {
        $status = (string)($run['STATUS'] ?? 'done');
        $started = (string)($run['STARTED'] ?? '');
        $finished = (string)($run['FINISHED'] ?? '');
        $lastSeen = (string)($run['LAST_SEEN'] ?? $started);
        $duration = 0;
        if ($started !== '') {
            $end = $finished !== '' ? strtotime($finished) : strtotime($lastSeen);
            $duration = max(0, ($end ?: time()) - (strtotime($started) ?: time()));
        }
        return array(
            'id' => (int)($run['ID'] ?? 0),
            'trace_id' => (string)($run['TRACE_ID'] ?? ''),
            'started_at' => $this->iso($started),
            'started_label' => $this->dateLabel($started),
            'finished_at' => $this->iso($finished),
            'finished_label' => $this->dateLabel($finished),
            'last_seen_at' => $this->iso($lastSeen),
            'last_seen_label' => $this->dateLabel($lastSeen),
            'duration_seconds' => $duration,
            'status' => $status,
            'status_label' => $status === 'error' ? 'Ошибка' : ($finished !== '' ? 'Завершён' : 'В процессе'),
            'trigger_type' => (string)($run['TRIGGER_TYPE'] ?? ''),
            'trigger_label' => $this->triggerLabel((string)($run['TRIGGER_TYPE'] ?? '')),
            'trigger_title' => (string)($run['TRIGGER_TITLE'] ?? ''),
            'source' => (string)($run['SOURCE'] ?? ''),
            'actor' => (string)($run['ACTOR'] ?? ''),
            'ip' => (string)($run['IP'] ?? ''),
            'request_uri' => (string)($run['REQUEST_URI'] ?? ''),
            'params' => $this->decode($run['PARAMS'] ?? null),
            'result' => $this->decode($run['RESULT'] ?? null),
            'error' => (string)($run['ERROR'] ?? ''),
            'events_count' => count($events),
            'events' => $events,
            'target_property_id' => $targetPropertyId,
        );
    }

    private function presentEvent(array $event, int $targetPropertyId): array
    {
        $type = (string)($event['TYPE'] ?? '');
        $added = (string)($event['ADDED'] ?? '');
        $params = $this->decode($event['PARAMS'] ?? null);
        $metadata = is_array($params) && count($params) === 1 ? ($params['__mdj_audit_step_v1'] ?? null) : null;
        return array(
            'id' => (int)($event['ID'] ?? 0),
            'type' => $type,
            'type_label' => $this->typeLabel($type),
            'status' => (string)($event['STATUS'] ?? ''),
            'title' => (string)($event['TITLE'] ?? ''),
            'subtitle' => (string)($event['SUBTITLE'] ?? ''),
            'added_at' => $this->iso($added),
            'added_label' => $this->dateLabel($added),
            'time_label' => $added !== '' ? date('H:i:s', strtotime($added) ?: time()) : '',
            'finished_label' => $this->dateLabel((string)($event['FINISHED'] ?? '')),
            'duration_ms' => (int)($event['DURATION_MS'] ?? 0),
            'object_id' => (int)($event['OBJECT_ID'] ?? 0),
            'property_id' => (int)($event['PROPERTY_ID'] ?? 0),
            'property_name' => (string)($event['PROPERTY_NAME'] ?? ''),
            'method_id' => (int)($event['METHOD_ID'] ?? 0),
            'class_id' => (int)($event['CLASS_ID'] ?? 0),
            'script_id' => (int)($event['SCRIPT_ID'] ?? 0),
            'module' => (string)($event['MODULE'] ?? ''),
            'old_value' => (string)($event['OLD_VALUE'] ?? ''),
            'new_value' => (string)($event['NEW_VALUE'] ?? ''),
            'source' => (string)($event['SOURCE'] ?? ''),
            'params' => is_array($metadata) ? ($metadata['params'] ?? null) : $params,
            'parent_event_id' => is_array($metadata) ? (int)($metadata['parent_event_id'] ?? 0) : 0,
            'execution' => is_array($metadata) ? ($metadata['execution'] ?? null) : null,
            'out_params' => $this->decode($event['OUT_PARAMS'] ?? null),
            'result' => $this->decode($event['RESULT'] ?? null),
            'error' => (string)($event['ERROR'] ?? ''),
            'chain' => $this->decode($event['CHAIN'] ?? null),
            'is_target' => $type === 'property' && (int)($event['PROPERTY_ID'] ?? 0) === $targetPropertyId,
        );
    }

    private function triggerLabel(string $type): string
    {
        $labels = array(
            'web' => 'Веб-интерфейс',
            'property' => 'Изменение свойства',
            'method' => 'Метод',
            'script' => 'Сценарий',
            'job' => 'Задача',
            'timer' => 'Таймер',
            'module' => 'Модуль',
            'event' => 'Событие',
            'system' => 'Система',
        );
        return $labels[$type] ?? ($type !== '' ? $type : 'Запуск');
    }

    private function typeLabel(string $type): string
    {
        $labels = array(
            'trigger' => 'Запуск',
            'job' => 'Задача',
            'method' => 'Метод',
            'script' => 'Сценарий',
            'module' => 'Модуль',
            'property' => 'Свойство',
            'error' => 'Ошибка',
            'warning' => 'Предупреждение',
            'chain' => 'Цепочка',
        );
        return $labels[$type] ?? ($type !== '' ? $type : 'Шаг');
    }

    private function source(): string
    {
        $source = '';
        if (defined('CALL_SOURCE')) $source .= ' ' . CALL_SOURCE;
        if (isset($_SERVER['REQUEST_URI'])) $source .= ' ' . urldecode((string)$_SERVER['REQUEST_URI']);
        if ($source === '' && PHP_SAPI === 'cli') {
            $source = 'CLI';
            if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) $source .= ' ' . implode(' ', $_SERVER['argv']);
        }
        $source = trim(preg_replace('/\s+/', ' ', str_replace("\n", ' ', $source)));
        return mb_strlen($source) > 255 ? mb_substr($source, 0, 252) . '...' : $source;
    }

    private function actor(): string
    {
        foreach (array('REMOTE_USER', 'PHP_AUTH_USER', 'HTTP_X_REMOTE_USER', 'HTTP_X_AUTH_REQUEST_USER', 'HTTP_X_FORWARDED_USER') as $key) {
            if (!empty($_SERVER[$key])) return mb_substr((string)$_SERVER[$key], 0, 255);
        }
        if (PHP_SAPI === 'cli') {
            $user = '';
            if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
                $info = posix_getpwuid(posix_geteuid());
                if (is_array($info)) $user = (string)($info['name'] ?? '');
            }
            return $user !== '' ? 'CLI: ' . $user : 'CLI';
        }
        return 'Веб-интерфейс';
    }

    private function ip(): string
    {
        foreach (array('REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP') as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', (string)$_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '';
    }

    private function requestUri(): string
    {
        return isset($_SERVER['REQUEST_URI']) ? mb_substr(urldecode((string)$_SERVER['REQUEST_URI']), 0, 255) : '';
    }

    private function json(mixed $value): ?string
    {
        if ($value === null) return null;
        try {
            $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_string($json)) return null;
        if (strlen($json) <= self::MAX_TEXT) return $json;
        // Обрезка посреди JSON теряла структуру параметров без объяснения.
        // Превью ограничено с запасом на экранирование и помечено явно.
        return json_encode(array('_truncated' => true, 'preview' => mb_strcut($json, 0, 8192, 'UTF-8')),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) return null;
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_array($value) || is_object($value)) return $this->json($value);
        return mb_strcut((string)$value, 0, self::MAX_TEXT, 'UTF-8');
    }

    private function decode(?string $json): mixed
    {
        if ($json === null || $json === '') return null;
        $decoded = json_decode($json, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $json;
    }

    private function iso(string $date): string
    {
        if ($date === '') return '';
        $time = strtotime($date);
        return $time ? date('c', $time) : '';
    }

    private function dateLabel(string $date): string
    {
        if ($date === '') return '';
        $time = strtotime($date);
        return $time ? date('d.m.Y H:i:s', $time) : $date;
    }

    private function errorText(Throwable $error): string
    {
        return mb_strcut(get_class($error) . ': ' . $error->getMessage(), 0, self::MAX_TEXT, 'UTF-8');
    }

    private function reportFailure(Throwable $error, string $scope): void
    {
        $message = 'Не удалось записать журнал изменений свойств (' . $scope . '): ' . $error->getMessage();
        if ($this->errors) {
            try {
                $this->errors->report('property_audit', $message, 'property_audit:' . $scope, 300);
                return;
            } catch (Throwable $ignored) {
            }
        }
        if (function_exists('DebMes')) {
            try {
                DebMes($message, 'property_audit', 'warning');
            } catch (Throwable $ignored) {
            }
        }
    }
}
