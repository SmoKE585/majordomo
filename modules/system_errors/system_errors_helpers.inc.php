<?php
/**
 * Форматирование нового диагностического конверта и старой истории ошибок.
 * 2026-10-25 — понятная сводка сбоя, источник и три статуса журнала.
 *
 * The module stores raw PHP backtraces. These helpers keep absolute paths and
 * byte escapes out of the main UI while preserving the raw text in a details
 * block for diagnostics.
 */

if (!function_exists('md_system_errors_short_path')) {
    function md_system_errors_short_path($path)
    {
        $path = str_replace('\\', '/', (string)$path);
        if (defined('ROOT') && ROOT !== '') {
            $root = rtrim(str_replace('\\', '/', ROOT), '/') . '/';
            if (strpos($path, $root) === 0) {
                $path = substr($path, strlen($root));
            }
        }
        return $path;
    }
}

if (!function_exists('md_system_errors_clean_call')) {
    function md_system_errors_clean_call($call)
    {
        $call = preg_replace('/\\\\x[0-9A-Fa-f]{2}/', '…', (string)$call);
        $call = preg_replace('/(?:…){2,}/u', '…', $call);
        if (mb_strlen($call, 'UTF-8') > 200) {
            $call = mb_substr($call, 0, 200, 'UTF-8') . '…';
        }
        return $call;
    }
}

if (!function_exists('md_system_errors_date')) {
    function md_system_errors_date($datetime)
    {
        $datetime = trim((string)$datetime);
        if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return $datetime;
        }

        $today = strtotime('today');
        if ($timestamp >= $today) {
            return 'сегодня, ' . date('H:i', $timestamp);
        }
        if ($timestamp >= $today - 86400) {
            return 'вчера, ' . date('H:i', $timestamp);
        }
        return date('d.m.Y, H:i', $timestamp);
    }
}

if (!function_exists('md_system_errors_plural')) {
    function md_system_errors_plural($number, $one, $few, $many)
    {
        $number = abs((int)$number);
        $mod10 = $number % 10;
        $mod100 = $number % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return $few;
        }
        return $many;
    }
}

if (!function_exists('md_system_errors_split_comment')) {
    function md_system_errors_split_comment($comments)
    {
        $data = \MajorDoMo\Core\ErrorDetails::decode((string)$comments);
        if ($data !== null) {
            return array('MESSAGE' => (string)$data['message'], 'TRACE' => (string)$data['trace'],
                'CONTEXT' => 'URL: ' . $data['url'] . "\nMethod: " . $data['method'] . "\nIP: " . $data['ip']);
        }
        $comments = str_replace("\r\n", "\n", (string)$comments);
        $parts = preg_split('/\nBacktrace:\s*\n/i', $comments, 2);
        $message = trim($parts[0]);
        $trace = isset($parts[1]) ? $parts[1] : '';
        $context_parts = preg_split('/\nContext:\s*\n/i', $trace, 2);
        return array(
            'MESSAGE' => $message,
            'TRACE' => trim($context_parts[0]),
            'CONTEXT' => isset($context_parts[1]) ? trim($context_parts[1]) : '',
        );
    }
}

if (!function_exists('md_system_errors_module_from_path')) {
    function md_system_errors_module_from_path($file)
    {
        $file = str_replace('\\', '/', (string)$file);
        if (preg_match('#(?:^|/)modules/([^/]+)/#', $file, $matches)) {
            return $matches[1];
        }
        if (preg_match('#(?:^|/)lib/#', $file)) {
            return 'ядро';
        }
        if (preg_match('#(?:^|/)templates/#', $file)) {
            return 'шаблон';
        }
        if (preg_match('#(?:^|/)scripts/#', $file)) {
            return 'сценарий';
        }
        return 'система';
    }
}

if (!function_exists('md_system_errors_context')) {
    function md_system_errors_context($comments)
    {
        $parts = md_system_errors_split_comment($comments);
        $context = array('URL' => '', 'METHOD' => '', 'IP' => '', 'REFERER' => '');
        if ($parts['CONTEXT'] === '') {
            return $context;
        }
        foreach (preg_split('/\n/', $parts['CONTEXT']) as $line) {
            if (preg_match('/^([A-Za-z_]+):\s*(.*)$/', trim($line), $matches)) {
                $key = strtoupper($matches[1]);
                if ($key === 'REFERER') {
                    $key = 'REFERER';
                }
                if (array_key_exists($key, $context)) {
                    $context[$key] = $matches[2];
                }
            }
        }
        return $context;
    }
}

if (!function_exists('md_system_errors_message')) {
    function md_system_errors_message($comments)
    {
        $parts = md_system_errors_split_comment($comments);
        $message = $parts['MESSAGE'];
        return $message !== '' ? $message : 'Ошибка без описания';
    }
}

if (!function_exists('md_system_errors_frames')) {
    function md_system_errors_frames($comments)
    {
        $data = \MajorDoMo\Core\ErrorDetails::decode((string)$comments);
        if ($data !== null) {
            $comments = $data['message'] . "\nBacktrace:\n#0 " . $data['file'] . '(' . (int)$data['line'] . '): ' . ($data['entity'] ?: $data['source'])
                . "\n" . $data['trace'];
        }
        $parts = md_system_errors_split_comment($comments);
        if ($parts['TRACE'] === '') {
            return array();
        }

        $frames = array();
        foreach (preg_split('/\n/', $parts['TRACE']) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // eval()'d code frames: file(line) : eval()'d code(eval_line): call
            if (preg_match('/^#(\d+)\s+(.+?)\((\d+)\)\s*:\s*eval\(\)\'d code\((\d+)\):\s*(.*)$/', $line, $matches)) {
                $file = md_system_errors_short_path($matches[2]);
                $frames[] = array(
                    'INDEX' => '#' . $matches[1],
                    'FILE' => htmlspecialchars($file, ENT_QUOTES, 'UTF-8'),
                    'FILE_WBR' => str_replace('/', '/<wbr>', htmlspecialchars($file, ENT_QUOTES, 'UTF-8')),
                    'FILE_RAW' => $file,
                    'LINE' => (int)$matches[3],
                    'EVAL' => (int)$matches[4],
                    'MODULE' => htmlspecialchars(md_system_errors_module_from_path($file), ENT_QUOTES, 'UTF-8'),
                    'CALL' => htmlspecialchars(md_system_errors_clean_call($matches[5]), ENT_QUOTES, 'UTF-8'),
                );
                continue;
            }

            if (preg_match('/^#(\d+)\s+(.+?)\((\d+)\):\s*(.*)$/', $line, $matches)) {
                $file = md_system_errors_short_path($matches[2]);
                $frames[] = array(
                    'INDEX' => '#' . $matches[1],
                    'FILE' => htmlspecialchars($file, ENT_QUOTES, 'UTF-8'),
                    'FILE_WBR' => str_replace('/', '/<wbr>', htmlspecialchars($file, ENT_QUOTES, 'UTF-8')),
                    'FILE_RAW' => $file,
                    'LINE' => (int)$matches[3],
                    'EVAL' => 0,
                    'MODULE' => htmlspecialchars(md_system_errors_module_from_path($file), ENT_QUOTES, 'UTF-8'),
                    'CALL' => htmlspecialchars(md_system_errors_clean_call($matches[4]), ENT_QUOTES, 'UTF-8'),
                );
                continue;
            }

            if (preg_match('/^#(\d+)\s+(.+)$/', $line, $matches)) {
                $frames[] = array(
                    'INDEX' => '#' . $matches[1],
                    'FILE' => '',
                    'FILE_WBR' => '',
                    'FILE_RAW' => '',
                    'LINE' => 0,
                    'EVAL' => 0,
                    'MODULE' => '',
                    'CALL' => htmlspecialchars(md_system_errors_clean_call($matches[2]), ENT_QUOTES, 'UTF-8'),
                );
                continue;
            }

            $frames[] = array(
                'INDEX' => '',
                'FILE' => '',
                'FILE_RAW' => '',
                'LINE' => 0,
                'EVAL' => 0,
                'MODULE' => '',
                'CALL' => htmlspecialchars(md_system_errors_clean_call($line), ENT_QUOTES, 'UTF-8'),
            );
        }

        return $frames;
    }
}

if (!function_exists('md_system_errors_origin')) {
    function md_system_errors_origin($frames)
    {
        if (empty($frames)) {
            return array('HAS_ORIGIN' => 0, 'MODULE' => '', 'FILE' => '', 'LINE' => 0, 'CALL' => '');
        }
        $frame = $frames[0];
        foreach ($frames as $candidate) {
            if (in_array($candidate['FILE_RAW'], array('lib/core/Helpers/ActionLog.php', 'lib/core/ErrorReporter.php'), true)) continue;
            $frame = $candidate;
            break;
        }
        return array(
            'HAS_ORIGIN' => ($frame['FILE'] !== '' || $frame['CALL'] !== '') ? 1 : 0,
            'MODULE' => $frame['MODULE'],
            'FILE' => $frame['FILE'],
            'LINE' => $frame['LINE'],
            'CALL' => $frame['CALL'],
        );
    }
}

/** Сводка описывает событие, а каталог даёт последствия и следующий шаг. */
function md_system_errors_diagnostic($comments, $description = '')
{
    $data = \MajorDoMo\Core\ErrorDetails::decode((string)$comments);
    $blocks = array();
    foreach (array('Почему' => 'CAUSE', 'Чем грозит' => 'IMPACT', 'Что делать' => 'ACTION') as $label => $key) {
        if (preg_match('/(?:^|\n)' . preg_quote($label, '/') . ':\s*(.*?)(?=\n\s*\n|$)/su', (string)$description, $match)) $blocks[$key] = trim($match[1]);
    }
    $summary = md_system_errors_message($comments);
    if (preg_match('/^Message:\s*(.+)$/m', $summary, $match)) $summary = $match[1];
    return array(
        'SUMMARY' => $summary,
        'SOURCE' => $data['source'] ?? '', 'ENTITY' => $data['entity'] ?? '',
        'CAUSE' => $blocks['CAUSE'] ?? 'Точная причина не определена. Проверьте сообщение и технические детали события.',
        'IMPACT' => $blocks['IMPACT'] ?? 'Операция завершилась сбоем; её результат нужно проверить.',
        'ACTION' => ($data['action'] ?? '') ?: ($blocks['ACTION'] ?? 'Откройте источник ошибки и устраните указанную причину перед повторным запуском.'),
    );
}

if (!function_exists('md_system_errors_title')) {
    function md_system_errors_title($title, $code, $comments)
    {
        $title = trim((string)$title);
        if ($title !== '') {
            return $title;
        }
        $message = md_system_errors_message($comments);
        $message = trim((string)preg_replace('/\s+/u', ' ', explode("\n", $message, 2)[0]));
        return $message !== '' ? mb_substr($message, 0, 160, 'UTF-8') : (string)$code;
    }
}

