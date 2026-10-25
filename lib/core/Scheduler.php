<?php

namespace MajorDoMo\Core;

use \Throwable;
use \RuntimeException;

/**
 * Планировщик с долговечными заданиями, отдельным исполнителем и цепочкой аудита.
 * 2026-10-25 — сроки в Redis, связь с создателем задания и ограниченные повторы после сбоев.
 */
final class Scheduler
{
    private int $cleanedAt = 0;

    public function __construct(private Database $db, private ProcessRunner $processes, private ?PropertyAudit $audit = null, private ?SchedulerQueue $queue = null) {}

    public static function timeout(): int
    {
        return defined('JOBS_EXECUTION_TIMEOUT') ? max(1, (int)JOBS_EXECUTION_TIMEOUT) : 300;
    }

    public static function maxAttempts(): int
    {
        return defined('JOBS_MAX_ATTEMPTS') ? max(1, (int)JOBS_MAX_ATTEMPTS) : 3;
    }

    public function schedule(string $title, string $commands, int $datetime, int $expire = 1800): int
    {
        if (trim($title) === '' || mb_strlen($title) > 255 || trim($commands) === '') throw new \InvalidArgumentException('Укажите название и PHP-команду задания.');
        $owned = $this->audit ? $this->audit->beginIfMissing('job', $title) : false;
        $unit = $this->db->beginUnit();
        try {
            $this->cancelTitle($title);
            $row = array('TITLE' => $title, 'COMMANDS' => $commands, 'RUNTIME' => date('Y-m-d H:i:s', $datetime),
                'TRACE_ID' => PropertyAudit::currentTraceId());
            if ($expire > 0) $row['EXPIRE'] = date('Y-m-d H:i:s', $datetime + $expire);
            $id = $this->db->insert('jobs', $row);
            if ($id < 1) throw new RuntimeException('Не удалось сохранить задание.');
            if ($this->audit) {
                $step = $this->audit->stepStart('job', $title, array('subtitle' => 'Задание поставлено',
                    'params' => array('job_id' => $id, 'runtime' => $row['RUNTIME'], 'commands' => $commands)));
                $this->audit->stepFinish($step, array('scheduled' => true));
                $this->db->requireExec('UPDATE jobs SET PARENT_EVENT_ID=' . (int)($step['id'] ?? 0) . ' WHERE ID=' . $id);
            }
            $this->queue?->changed($id);
            $this->db->commitUnit($unit);
            return $id;
        } catch (Throwable $error) {
            if ($this->db->hasUnit($unit)) $this->db->rollbackUnit($unit);
            throw $error;
        } finally {
            if ($owned) { $this->audit->finishRun(); PropertyAudit::leave(); }
        }
    }

    public function get(int $id): ?array
    {
        return $this->db->one('SELECT * FROM jobs WHERE ID=' . max(0, $id));
    }

    public function exists(string $title): int
    {
        $row = $this->db->one("SELECT ID FROM jobs WHERE PROCESSED=0 AND EXPIRED=0 AND TITLE='" . $this->db->escape($title) . "' LIMIT 1");
        return (int)($row['ID'] ?? 0);
    }

    public function cancelTitle(string $title): bool
    {
        return $this->cancelWhere("TITLE='" . $this->db->escape($title) . "'");
    }

    public function cancel(int $id): bool
    {
        return $this->cancelWhere('ID=' . max(0, $id));
    }

    private function cancelWhere(string $where): bool
    {
        $rows = $this->db->select('SELECT ID,TITLE,TRACE_ID FROM jobs WHERE ' . $where);
        $this->db->requireExec('DELETE FROM jobs WHERE ' . $where);
        foreach ($rows as $row) {
            $this->queue?->changed((int)$row['ID']);
            if ($this->audit) {
                $owned = !PropertyAudit::hasTrace();
                if ($owned) $this->audit->enter((string)$row['TRACE_ID']);
                $this->audit->note('job', (string)$row['TITLE'], array('status' => 'done',
                    'subtitle' => 'Задание отменено или заменено', 'params' => array('job_id' => (int)$row['ID'])));
                if ($owned) { $this->audit->finishRun(); PropertyAudit::leave(); }
            }
        }
        return true;
    }

    /** Пагинация и фильтры общие для панели и диагностических команд. */
    public function listing(string $status = '', string $search = '', int $page = 1): array
    {
        $now = date('Y-m-d H:i:s');
        $state = "CASE WHEN PROCESSED=1 THEN 'done' WHEN EXPIRED=1 OR (EXPIRE IS NOT NULL AND EXPIRE<='$now') THEN 'failed'"
            . " WHEN CLAIM_TOKEN<>'' THEN 'running' WHEN ATTEMPTS>0 THEN 'retry' ELSE 'waiting' END";
        $where = array('1=1');
        if (in_array($status, array('done', 'failed', 'running', 'retry', 'waiting'), true)) $where[] = "($state)='" . $status . "'";
        if ($search !== '') $where[] = "TITLE LIKE '%" . $this->db->escapeLike(mb_substr($search, 0, 255)) . "%'";
        $where = implode(' AND ', $where);
        $total = (int)($this->db->one('SELECT COUNT(*) AS C FROM jobs WHERE ' . $where)['C'] ?? 0);
        $pages = max(1, (int)ceil($total / 50));
        $page = max(1, min($pages, $page));
        $rows = $this->db->select("SELECT *,($state) AS STATE FROM jobs WHERE $where ORDER BY RUNTIME DESC,ID DESC LIMIT 50 OFFSET " . (($page - 1) * 50));
        $counts = array_fill_keys(array('waiting', 'running', 'retry', 'failed', 'done'), 0);
        foreach ($this->db->select("SELECT ($state) AS STATE,COUNT(*) AS C FROM jobs GROUP BY STATE") as $row) $counts[$row['STATE']] = (int)$row['C'];
        return array('rows' => $rows, 'counts' => $counts, 'total' => $total, 'page' => $page, 'pages' => $pages);
    }

    public function execute($id, $token)
    {
        $job = $this->db->one('SELECT * FROM jobs WHERE ID=' . (int)$id . ' AND PROCESSED=0 AND EXPIRED=0');
        if (!$job || $token === '' || !hash_equals($job['CLAIM_TOKEN'], (string)$token)) return array('success' => false, 'error' => 'Job claim is no longer valid');
        $auditStep = null;
        $auditError = null;
        try {
            if (!defined('CALL_SOURCE')) define('CALL_SOURCE', 'Job: ' . $job['TITLE']);
            if ($this->audit) {
                $trace = PropertyAudit::normalizeTraceId((string)($job['TRACE_ID'] ?? ''));
                if ($trace !== '') $this->audit->enter($trace);
                $this->audit->begin('job', (string)$job['TITLE'], array('commands' => (string)$job['COMMANDS']), array(
                    'source' => 'Job: ' . $job['TITLE'],
                ));
                $auditStep = $this->audit->stepStart('job', (string)$job['TITLE'], array(
                    'subtitle' => 'Выполнение · попытка ' . (int)$job['ATTEMPTS'] . ' из ' . self::maxAttempts(),
                    'source' => 'Job: ' . $job['TITLE'],
                    'parent_event_id' => (int)($job['PARENT_EVENT_ID'] ?? 0),
                    'execution' => array('code' => (string)$job['COMMANDS'], 'language' => 'php'),
                    'params' => array('job_id' => (int)$job['ID'], 'attempt' => (int)$job['ATTEMPTS'], 'commands' => (string)$job['COMMANDS']),
                ));
                if ($trace === '') $this->db->requireExec("UPDATE jobs SET TRACE_ID='" . PropertyAudit::currentTraceId() . "' WHERE ID=" . (int)$id . " AND CLAIM_TOKEN='" . $this->db->escape($token) . "'");
            }
            setEvalCode($job['COMMANDS']);
            $result = eval($job['COMMANDS']);
            if ($result === false) throw new RuntimeException('Job returned false');
            if (is_array($result) && array_key_exists('success', $result) && !$result['success']) throw new RuntimeException((string)($result['error'] ?? 'Job reported failure'));
            return array('success' => true);
        } catch (Throwable $e) {
            $auditError = $e;
            return array('success' => false, 'error' => get_class($e) . ': ' . $e->getMessage());
        } finally {
            if ($this->audit) {
                $this->audit->stepFinish($auditStep, isset($result) ? $result : null, $auditError);
                $this->audit->finishRun($auditError ? 'error' : 'done', null, $auditError);
                $this->audit->leave();
            }
            setEvalCode();
            unset($result);
        }
    }

    public function run($execute = null, ?callable $progress = null)
    {
        $now = date('Y-m-d H:i:s');
        $timeout = self::timeout();
        $stale = date('Y-m-d H:i:s', time() - $timeout - 60);
        $limit = defined('JOBS_QUEUE_LIMIT') ? max(1, min(1000, (int)JOBS_QUEUE_LIMIT)) : 100;
        $max_attempts = self::maxAttempts();
        if (time() - $this->cleanedAt >= 60) {
            $before = date('Y-m-d H:i:s', time() - 7 * 86400);
            $this->db->requireExec("DELETE FROM jobs WHERE ID IN (SELECT ID FROM (SELECT ID FROM jobs WHERE (PROCESSED=1 OR EXPIRED=1) AND EXPIRE<='$before' LIMIT 1000) old_jobs)");
            $this->cleanedAt = time();
        }
        $ids = $this->queue?->due($limit, $timeout + 60);
        $jobs = $ids !== null ? array_map(static fn($id) => array('ID' => (int)$id), $ids)
            : $this->db->select("SELECT ID FROM jobs WHERE PROCESSED=0 AND EXPIRED=0 AND RUNTIME<='$now' AND (STARTED IS NULL OR STARTED<='$stale') ORDER BY RUNTIME,ID LIMIT $limit");
        foreach ($jobs as $row) {
            if ($progress !== null) $progress();
            $id = (int)$row['ID'];
            $job = $this->get($id);
            if (!$job || (int)$job['PROCESSED'] || (int)$job['EXPIRED']) { $this->queue?->changed($id); continue; }
            if ((!empty($job['EXPIRE']) && $job['EXPIRE'] <= $now) || (int)$job['ATTEMPTS'] >= $max_attempts) {
                $error = (int)$job['ATTEMPTS'] >= $max_attempts ? 'Исполнитель аварийно завершился; попытки исчерпаны' : 'Истёк срок ожидания задания';
                $this->db->requireExec("UPDATE jobs SET EXPIRED=1,CLAIM_TOKEN='',LAST_ERROR='" . $this->db->escape($error) . "' WHERE ID=$id AND PROCESSED=0 AND EXPIRED=0 AND (STARTED IS NULL OR STARTED<='$stale')");
                if ($this->db->affectedRows() > 0) $this->failure($job, $error, true);
                $this->queue?->changed($id);
                continue;
            }
            $token = bin2hex(random_bytes(16));
            $this->db->requireExec("UPDATE jobs SET STARTED='$now',CLAIM_TOKEN='$token',ATTEMPTS=ATTEMPTS+1,DISPATCH_GENERATION='' WHERE ID=$id AND PROCESSED=0 AND EXPIRED=0 AND ATTEMPTS<$max_attempts AND RUNTIME<='$now' AND (STARTED IS NULL OR STARTED<='$stale')");
            if ($this->db->affectedRows() < 1) continue;
            $job = $this->db->one("SELECT * FROM jobs WHERE ID=$id AND CLAIM_TOKEN='$token'");
            try {
                $result = $execute ? $execute($job, $token) : $this->processes->run('job', $id, $token, $timeout);
            } catch (Throwable $e) {
                $result = array('success' => false, 'error' => $e->getMessage());
            }
            $job = $this->get($id) ?? $job;
            if (!empty($result['success'])) {
                $this->db->requireExec("UPDATE jobs SET PROCESSED=1,LAST_ERROR=NULL,CLAIM_TOKEN='' WHERE ID=$id AND CLAIM_TOKEN='$token' AND PROCESSED=0");
            } else {
                $expired = (int)$job['ATTEMPTS'] >= $max_attempts ? 1 : 0;
                $error = substr((string)($result['error'] ?? 'Unknown worker error'), 0, 4096);
                $runtime = date('Y-m-d H:i:s', time() + min(300, 5 * (2 ** min(6, (int)$job['ATTEMPTS'] - 1))));
                $this->db->requireExec("UPDATE jobs SET STARTED=NULL,CLAIM_TOKEN='',RUNTIME='$runtime',EXPIRED=$expired,LAST_ERROR='" . $this->db->escape($error) . "' WHERE ID=$id AND CLAIM_TOKEN='$token' AND PROCESSED=0");
                if ($this->db->affectedRows() > 0) $this->failure($job, $error, (bool)$expired, $runtime);
                DebMes('Scheduled job ' . $id . ($expired ? ' failed permanently: ' : ' will retry: ') . $error, 'scheduled_jobs', $expired ? 'error' : 'warning');
            }
            $this->queue?->changed($id);
        }
        if ($progress !== null) $progress();
    }

    private function failure(array $job, string $error, bool $terminal, string $next = ''): void
    {
        if (!$this->audit) return;
        $owned = !PropertyAudit::hasTrace();
        if ($owned) $this->audit->enter((string)($job['TRACE_ID'] ?? ''));
        $this->audit->beginIfMissing('job', (string)$job['TITLE']);
        $this->audit->note('job', (string)$job['TITLE'], array('status' => $terminal ? 'error' : 'warning',
            'subtitle' => $terminal ? 'Попытки завершены' : 'Назначена повторная попытка', 'error' => $error,
            'parent_event_id' => (int)($job['PARENT_EVENT_ID'] ?? 0),
            'params' => array('job_id' => (int)$job['ID'], 'attempt' => (int)$job['ATTEMPTS'], 'next_run' => $next)));
        if ($owned) { $this->audit->finishRun($terminal ? 'error' : 'done'); PropertyAudit::leave(); }
    }
}
