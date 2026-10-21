<?php

namespace MajorDoMo\Core;


final class ProcessRunner
{
    public function __construct(private ?string $root = null) {}

    public function run($kind, $id, $token, $timeout = 300)
    {
        $root = $this->root ?? ROOT;
        $command = array(defined('PATH_TO_PHP') ? PATH_TO_PHP : PHP_BINARY, $root . 'scripts/core_worker.php', $kind, (string)$id, (string)$token);
        $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $root);
        if (!is_resource($process)) return array('success' => false, 'error' => 'Cannot start worker process');
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + max(1, (int)$timeout);
        do {
            $output = substr($output . stream_get_contents($pipes[1]), -65536);
            // Drain stderr to prevent a full pipe from blocking the worker.
            $errors = stream_get_contents($pipes[2]);
            if ($errors !== '') error_log('MajorDoMo worker: ' . substr($errors, -4096));
            $status = proc_get_status($process);
            if (!$status['running']) break;
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                usleep(100000);
                if (proc_get_status($process)['running']) proc_terminate($process, 9);
                foreach (array(1, 2) as $pipe) fclose($pipes[$pipe]);
                proc_close($process);
                return array('success' => false, 'error' => 'Worker execution timeout');
            }
            usleep(10000);
        } while (true);
        $output .= stream_get_contents($pipes[1]);
        foreach (array(1, 2) as $pipe) fclose($pipes[$pipe]);
        proc_close($process);
        if (preg_match('/^MJD_WORKER_RESULT (\{[^\r\n]*\})\r?$/m', $output, $match)) {
            $result = json_decode($match[1], true);
            if (is_array($result) && array_key_exists('success', $result)) return $result;
        }
        return array('success' => false, 'error' => 'Worker exited without an execution result');
    }
}
