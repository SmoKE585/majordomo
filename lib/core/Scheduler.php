<?php

namespace MajorDoMo\Core;

use \Throwable;
use \RuntimeException;

final class Scheduler
{
    public function __construct(private Database $db, private ProcessRunner $processes) {}

    public function execute($id, $token)
    {
        $job = $this->db->one('SELECT * FROM jobs WHERE ID=' . (int)$id . ' AND PROCESSED=0 AND EXPIRED=0');
        if (!$job || $token === '' || !hash_equals($job['CLAIM_TOKEN'], (string)$token)) return array('success' => false, 'error' => 'Job claim is no longer valid');
        try {
            if (!defined('CALL_SOURCE')) define('CALL_SOURCE', 'Job: ' . $job['TITLE']);
            setEvalCode($job['COMMANDS']);
            $result = eval($job['COMMANDS']);
            if ($result === false) throw new RuntimeException('Job returned false');
            return array('success' => true);
        } catch (Throwable $e) {
            return array('success' => false, 'error' => get_class($e) . ': ' . $e->getMessage());
        } finally {
            setEvalCode();
        }
    }

    public function run($execute = null)
    {
        $now = date('Y-m-d H:i:s');
        $timeout = defined('JOBS_EXECUTION_TIMEOUT') ? max(1, (int)JOBS_EXECUTION_TIMEOUT) : 300;
        $stale = date('Y-m-d H:i:s', time() - $timeout - 60);
        $limit = defined('JOBS_QUEUE_LIMIT') ? max(1, min(1000, (int)JOBS_QUEUE_LIMIT)) : 100;
        $max_attempts = defined('JOBS_MAX_ATTEMPTS') ? max(1, (int)JOBS_MAX_ATTEMPTS) : 3;
        $this->db->requireExec("DELETE FROM jobs WHERE EXPIRE<='$now' LIMIT 1000");
        $jobs = $this->db->select("SELECT ID FROM jobs WHERE PROCESSED=0 AND EXPIRED=0 AND RUNTIME<='$now' AND (STARTED IS NULL OR STARTED<='$stale') ORDER BY RUNTIME,ID LIMIT $limit");
        foreach ($jobs as $row) {
            $id = (int)$row['ID'];
            $token = bin2hex(random_bytes(16));
            $this->db->requireExec("UPDATE jobs SET STARTED='$now',CLAIM_TOKEN='$token',ATTEMPTS=ATTEMPTS+1 WHERE ID=$id AND PROCESSED=0 AND EXPIRED=0 AND (STARTED IS NULL OR STARTED<='$stale')");
            if ($this->db->affectedRows() < 1) continue;
            $job = $this->db->one("SELECT * FROM jobs WHERE ID=$id AND CLAIM_TOKEN='$token'");
            try {
                $result = $execute ? $execute($job, $token) : $this->processes->run('job', $id, $token, $timeout);
            } catch (Throwable $e) {
                $result = array('success' => false, 'error' => $e->getMessage());
            }
            if (!empty($result['success'])) {
                $this->db->requireExec("UPDATE jobs SET PROCESSED=1,LAST_ERROR=NULL,CLAIM_TOKEN='' WHERE ID=$id AND CLAIM_TOKEN='$token' AND PROCESSED=0");
            } else {
                $expired = (int)$job['ATTEMPTS'] >= $max_attempts ? 1 : 0;
                $error = substr((string)($result['error'] ?? 'Unknown worker error'), 0, 4096);
                $runtime = date('Y-m-d H:i:s', time() + min(300, 5 * (2 ** min(6, (int)$job['ATTEMPTS'] - 1))));
                $this->db->requireExec("UPDATE jobs SET STARTED=NULL,CLAIM_TOKEN='',RUNTIME='$runtime',EXPIRED=$expired,LAST_ERROR='" . $this->db->escape($error) . "' WHERE ID=$id AND CLAIM_TOKEN='$token' AND PROCESSED=0");
                DebMes('Scheduled job ' . $id . ($expired ? ' failed permanently: ' : ' will retry: ') . $error, 'scheduled_jobs', $expired ? 'error' : 'warning');
            }
        }
    }
}
