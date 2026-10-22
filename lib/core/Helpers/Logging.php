<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Write a diagnostic message; problems share errors.log and retain their source.
 * @param string $errorMessage string Exception message
 * @param string $logLevel source channel, default=debug
 * @param string $severity optional severity (warning/error/critical/...)
 * @return void
 */
function DebMes($errorMessage, $logLevel = "debug", $severity = '')
{

    if (defined('SETTINGS_SYSTEM_DISABLE_DEBMES') && SETTINGS_SYSTEM_DISABLE_DEBMES == 1) return;

    $source = (string)$logLevel;
    $severity = strtolower((string)$severity);
    $problemSeverity = in_array($severity, array('warning', 'warn', 'error', 'danger', 'critical', 'alert', 'emergency', 'exception'), true);
    $problem = $problemSeverity || preg_match('~(?:^|[/_-])(?:errors?|warnings?|exceptions?)(?:$|[/_-])~i', $source);
    if ($problem) {
        if (!$problemSeverity) {
            $severity = preg_match('~(?:^|[/_-])warnings?(?:$|[/_-])~i', $source) ? 'warning' : 'error';
        }
        $logLevel = 'errors';
    }

    if (defined('SETTINGS_SYSTEM_DEBMES_PATH') && SETTINGS_SYSTEM_DEBMES_PATH != '') {
        $path = SETTINGS_SYSTEM_DEBMES_PATH;
    } elseif (defined('LOG_DIRECTORY') && LOG_DIRECTORY != '') {
        $path = LOG_DIRECTORY;
    } else {
        $path = ROOT . 'cms/debmes';
    }

    if (defined('LOG_MAX_SIZE') && LOG_MAX_SIZE > 0) {
        $max_log_size = LOG_MAX_SIZE * 1024 * 1024; // Mb
    } else {
        $max_log_size = 5 * 1024 * 1024; // 5 Mb, default
    }

    // DEBUG MESSAGE LOG
    if (!is_dir($path)) {
        umask(0);
        mkdir($path, 0777);
    }

    $today_path = $path . '/' . date('Y-m-d');
    if (!is_dir($today_path)) {
        umask(0);
        mkdir($today_path, 0777);
    }

    $tmp = explode('/', $logLevel);
    $total = count($tmp);
    for ($i = 0; $i < $total; $i++) {
        $today_path .= '/' . $tmp[$i];
        if (!is_dir($today_path) && ($i < $total - 1)) {
            umask(0);
            mkdir($today_path, 0777);
        }
    }
    $today_file = $today_path . '.log';

    if (is_array($errorMessage) || is_object($errorMessage)) {
        $errorMessage = json_encode($errorMessage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    if ($problem) {
        $errorMessage = '[' . strtoupper($severity) . '] [' . str_replace(array("\r", "\n", ']'), '', $source) . '] ' . $errorMessage;
    }


    //if ($logLevel != 'debug') {
    //    $today_file = $path . '/' . date('Y-m-d') . '_' . $logLevel . '.log';
    //} else {
    //    $today_file = $path . '/' . date('Y-m-d') . '.log';
    //}

    if (file_exists($today_file) && filesize($today_file) > $max_log_size) return;

    $tmp = explode(' ', microtime());
    // Several cycles and web requests now share errors.log. Append one complete
    // entry under a lock so their timestamps and multiline details cannot mix.
    $entry = date("H:i:s") . ' ' . $tmp[0] . " " . $errorMessage . "\n";
    if (file_put_contents($today_file, $entry, FILE_APPEND | LOCK_EX) !== false) {
        // Logs are written by different system users (root cycles and the web
        // server), so keep them group/world writable when possible. A denied
        // chmod() is not an application error: it must not raise a warning and
        // must not land in error_get_last(), where it used to be attached to a
        // completely different log entry.
        $log_perms = @fileperms($today_file);
        if ($log_perms !== false && ($log_perms & 0666) !== 0666 && is_writable($today_file)) {
            set_error_handler(static function () {
                return true;
            });
            try {
                chmod($today_file, 0666);
            } catch (Throwable $e) {
                // Another owner keeps writing the log; nothing to do here.
            }
            restore_error_handler();
        }
    }
}

function dprint($data = 0, $stop = 1, $show_history = 0)
{
    if (isset($_SERVER['REQUEST_METHOD'])) {
        echo "<pre>";
    } else {
        echo "\n" . date('Y-m-d H:i:s ');
    }
    if ($data !== 0) {
        if (is_array($data)) {
            print_r($data);
        } elseif (is_object($data)) {
            var_dump($data);
        } else {
            echo $data;
        }
    } else {
        echo date('Y-m-d H:i:s');
    }

    if ($show_history) {
        $e = new \Exception;
        echo ' (' . $e->getTraceAsString() . ')';
    }

    if (isset($_SERVER['REQUEST_METHOD'])) {
        echo "</pre><hr/>";
        echo str_repeat(' ', 4096);
        flush();
        flush();
        echo "<script type='text/javascript'>window.scrollTo(0,document.body.scrollHeight);</script>";
    } else {
        echo "\n---------------------------------\n";
    }

    if ($stop) {
        exit;
    }
}

function getEvalCode()
{
    global $evalCodeInProgress;
    if (isset($evalCodeInProgress) && $evalCodeInProgress != '') {
        $tmp = explode("\n", $evalCodeInProgress);
        $total_lines = count($tmp);
        for ($i = 0; $i < $total_lines; $i++) {
            $line = $i + 1;
            $tmp[$i] = "($line) " . $tmp[$i];
        }
        return implode("\n", $tmp);
    } else {
        return false;
    }
}

/**
 * Method returns logger with meaningful name. In this case much easy to enable\disable
 * logs depending on requirements
 *
 * If $context is empty or null, then return root logger
 * If $context is filename or filepath, then return logger with name 'page.filename'
 * If $context is string, then return logger with name $context
 * If $context is object, then depending from object class it returns:
 *  - 'class.object.objectname'
 *  - 'class.module.modulename'
 *  - 'class.objectclass'
 * Example of usage:
 *  - $log = getLogger();
 *  - $log = getLogger('MyLogger');
 *  - $log = getLogger(__FILE__);
 *  - $log = getLogger($this);
 * @param mixed $context Context (default null)
 * @return Logger
 */
function getLogger($context = null)
{
    /*
       if (empty($context))
          return Logger::getRootLogger();
       elseif (is_string($context))
       {
          if (is_file($context))
             return Logger::getLogger('page.' . basename($context, '.php'));
          else
             return Logger::getLogger($context);
       }
       elseif (is_a($context, 'objects'))
          return Logger::getLogger("class.object.$context->object_title");
       elseif (is_a($context, 'module'))
          return Logger::getLogger("class.module.$context->name");
       elseif (is_object($context))
          return Logger::getLogger('class.' . get_class($context));
       else
          return Logger::getRootLogger();
          */
    return false;
}

function setEvalCode($code = '')
{
    global $evalCodeInProgress;
    $evalCodeInProgress = $code;
}
