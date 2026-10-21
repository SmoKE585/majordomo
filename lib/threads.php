<?php

/** Owns only its child processes; polling and output reads never wait for child output. */
class Threads
{
    public $phpPath = 'php';
    public $handles = array();
    public $commandLines = array();
    private $lastId = 0;
    private $pipes = array();
    private $tails = array();
    private $logFiles = array();
    private $temporaryFiles = array();
    private $readOffsets = array();
    private $closedDetails = array();
    private $pendingClosed = '';

    public function newThread($filename, $params = array())
    {
        return $this->openProcess($filename, $params);
    }

    public function newXThread($filename, $display = '101', $params = array())
    {
        if (IsWindowsOS()) throw new ThreadsException('FOR_LINUX_ONLY');
        return $this->openProcess($filename, $params, (string)$display);
    }

    private function openProcess($filename, $params, $display = null)
    {
        if (!is_file($filename)) {
            DebMes("Cannot start thread '$filename' -- FILE NOT FOUND", 'threads');
            return false;
        }
        $arguments = array($this->phpPath, '-q', (string)$filename, '--params', serialize($params));
        $command = implode(' ', array_map('escapeshellarg', $arguments));
        $descriptors = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('redirect', 1));
        $file = null;
        $temporary = null;
        if (defined('LOG_CYCLES') && LOG_CYCLES == '1') {
            $path = defined('SETTINGS_SYSTEM_DEBMES_PATH') && SETTINGS_SYSTEM_DEBMES_PATH !== '' ? SETTINGS_SYSTEM_DEBMES_PATH
                : (defined('LOG_DIRECTORY') && LOG_DIRECTORY !== '' ? LOG_DIRECTORY : ROOT . 'cms/debmes');
            $file = rtrim($path, '/\\') . '/log_' . date('Y-m-d') . '-' . basename($filename) . '.txt';
            $descriptors[1] = array('file', $file, 'a');
        } elseif (IsWindowsOS()) {
            // PHP cannot reliably make anonymous Windows pipes non-blocking.
            // An owned, bounded spool keeps silent children from blocking the supervisor.
            $temporary = tempnam(sys_get_temp_dir(), 'mjd-cycle-');
            if ($temporary === false) return false;
            $descriptors[1] = array('file', $temporary, 'a');
        }
        // DISPLAY is the child-process environment, not application configuration.
        $environment = null;
        if ($display !== null) {
            $environment = getenv();
            $environment['DISPLAY'] = $display;
        }
        try {
            $handle = proc_open($arguments, $descriptors, $pipes, null, $environment, array('bypass_shell' => true, 'blocking_pipes' => false));
        } catch (Throwable $error) {
            if ($temporary !== null) unlink($temporary);
            DebMes('Cannot start thread: ' . $error->getMessage(), 'threads');
            return false;
        }
        if (!is_resource($handle)) {
            if ($temporary !== null) unlink($temporary);
            DebMes('Cannot start thread: proc_open failed for ' . $filename, 'threads');
            return false;
        }
        $id = ++$this->lastId;
        try {
            if ($temporary !== null) {
                $pipes[1] = fopen($temporary, 'rb');
                if ($pipes[1] === false) throw new RuntimeException('Cannot read cycle output spool');
            }
            foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        } catch (Throwable $error) {
            proc_terminate($handle, 9);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($handle);
            if ($temporary !== null) unlink($temporary);
            DebMes('Cannot initialise cycle pipes: ' . $error->getMessage(), 'threads');
            return false;
        }
        if ($temporary !== null) {
            $this->temporaryFiles[$id] = $temporary;
            $this->readOffsets[$id] = 0;
        }
        $this->handles[$id] = $handle;
        $this->pipes[$id] = $pipes;
        $this->commandLines[$id] = $command;
        $this->tails[$id] = '';
        if ($file !== null) $this->logFiles[$id] = $file;
        echo date('H:i:s') . ' Starting thread: ' . $command . PHP_EOL;
        return $id;
    }

    public function getPipes()
    {
        return $this->pipes;
    }

    public function getThreadInfo($id): array
    {
        if (!isset($this->handles[$id])) return array();
        $status = proc_get_status($this->handles[$id]);
        $status['output'] = $this->lastOutput($id);
        return $status;
    }

    public function takeClosedDetails($id): array
    {
        $details = $this->closedDetails[$id] ?? array();
        unset($this->closedDetails[$id]);
        return $details;
    }

    private function lastOutput($id): string
    {
        if (isset($this->logFiles[$id]) && is_readable($this->logFiles[$id])) {
            $stream = fopen($this->logFiles[$id], 'rb');
            if ($stream) {
                $size = fstat($stream)['size'];
                fseek($stream, max(0, $size - 4096));
                $tail = stream_get_contents($stream, 4096);
                fclose($stream);
                if ($tail !== false) return $tail;
            }
        }
        return $this->tails[$id] ?? '';
    }

    private function readOutput($id): string
    {
        $contents = '';
        foreach ($this->pipes[$id] ?? array() as $descriptor => $pipe) {
            if ($descriptor !== 1) continue;
            if (isset($this->temporaryFiles[$id])) {
                $size = fstat($pipe)['size'];
                $offset = $this->readOffsets[$id];
                if ($size > 1048576) $offset = max(0, $size - 65536);
                fseek($pipe, $offset);
                $chunk = stream_get_contents($pipe, min(65536, max(0, $size - $offset)));
                $this->readOffsets[$id] = $offset + strlen($chunk === false ? '' : $chunk);
                if ($size > 1048576) {
                    $spool = fopen($this->temporaryFiles[$id], 'c');
                    ftruncate($spool, 0);
                    fclose($spool);
                    $this->readOffsets[$id] = 0;
                }
            } else {
                $chunk = stream_get_contents($pipe, 65536);
            }
            if ($chunk !== false) $contents .= $chunk;
        }
        if ($contents === '') return '';
        $this->tails[$id] = substr(($this->tails[$id] ?? '') . $contents, -4096);
        return "THREAD OUTPUT: [" . $this->commandLines[$id] . "]\n" . $contents . "\nTHREAD OUTPUT END\n";
    }

    private function reap($id, array $status): string
    {
        $result = $this->readOutput($id);
        $this->closedDetails[$id] = array('pid' => (int)$status['pid'], 'output' => $this->lastOutput($id));
        if (count($this->closedDetails) > 128) unset($this->closedDetails[array_key_first($this->closedDetails)]);
        $result .= 'THREAD CLOSED: [' . $this->commandLines[$id] . '] EXIT_CODE=' . (int)($status['exitcode'] ?? -1)
            . ' TERM_SIG=' . (int)($status['termsig'] ?? 0) . ' STOP_SIG=' . (int)($status['stopsig'] ?? 0) . ' THREAD_ID=' . $id . "\n";
        foreach ($this->pipes[$id] ?? array() as $pipe) fclose($pipe);
        proc_close($this->handles[$id]);
        if (isset($this->temporaryFiles[$id])) unlink($this->temporaryFiles[$id]);
        unset($this->temporaryFiles[$id], $this->readOffsets[$id]);
        unset($this->handles[$id], $this->pipes[$id], $this->commandLines[$id], $this->tails[$id], $this->logFiles[$id]);
        return $result;
    }

    public function closeThread($id)
    {
        if (!isset($this->handles[$id])) return true;
        $status = proc_get_status($this->handles[$id]);
        if ($status['running']) {
            DebMes('Stopping cycle process pid=' . $status['pid'], 'threads');
            proc_terminate($this->handles[$id]);
            $deadline = microtime(true) + 3;
            do {
                // Retain only the diagnostic tail while waiting for termination.
                $this->readOutput($id);
                $status = proc_get_status($this->handles[$id]);
                if (!$status['running']) break;
                usleep(50000);
            } while (microtime(true) < $deadline);
            if ($status['running']) {
                DebMes('Cycle ignored termination; force killing pid=' . $status['pid'], 'threads');
                proc_terminate($this->handles[$id], 9);
                $deadline = microtime(true) + 2;
                do {
                    $status = proc_get_status($this->handles[$id]);
                    if (!$status['running']) break;
                    usleep(50000);
                } while (microtime(true) < $deadline);
            }
        }
        if ($status['running']) {
            DebMes('CRITICAL: cycle process could not be killed, pid=' . $status['pid'], 'threads');
            return false;
        }
        $this->pendingClosed .= $this->reap($id, $status);
        return true;
    }

    public function iteration()
    {
        $result = $this->pendingClosed;
        $this->pendingClosed = '';
        if (!$this->handles) return $result !== '' ? $result : false;
        foreach (array_keys($this->handles) as $id) {
            $result .= $this->readOutput($id);
            $status = proc_get_status($this->handles[$id]);
            if (!$status['running']) $result .= $this->reap($id, $status);
        }
        usleep(100000);
        return $result;
    }

    public static function getParams()
    {
        foreach ($_SERVER['argv'] as $key => $argv) {
            if ($argv === '--params' && isset($_SERVER['argv'][$key + 1])) return unserialize($_SERVER['argv'][$key + 1]);
        }
        return false;
    }
}

class ThreadsException extends Exception {}
