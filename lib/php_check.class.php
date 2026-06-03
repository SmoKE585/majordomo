<?php
/**
* PHP Syntax check
*
*
* @package framework
* @author Serge Dzheigalo <jey@activeunit.com>
* @copyright Serge J. 2012
* @version 1.1
*/

/**
 * Summary of php_syntax_error
 * @param mixed $code Code
 * @return bool|string
 */
function php_syntax_error($code)
{
	if (isItPythonCode($code)) {
		return python_syntax_error($code);
	} else {
		$code .= "\n echo 'welldone';";
		$code  = '<?php ' . $code . '?>';

		$fileName = md5(time() . rand(0, 10000)) . '.php';
		$filePath = DOC_ROOT . '/cms/cached/' . $fileName;
		SaveFile($filePath, $code);
		if (substr(php_uname(), 0, 7) == "Windows") {
			if (defined('PATH_TO_PHP')) {
				$cmd = PATH_TO_PHP . ' -l ' . $filePath;
			} else {
				$cmd = DOC_ROOT . '/../server/php/php -l ' . $filePath;
			}
		} else {
			$cmd = 'php -d display_errors=1 -l ' . $filePath.' 2>&1';
		}
		exec($cmd, $out);
		unlink($filePath);
		$res = implode("\n", $out);
		
		if(preg_match("/\.php on line \b/i", $res)) 
			return trim($res) . "\n";
		if(preg_match("/\Errors parsing\b/i", $res)) 
			return trim($res) . "\n";
		if (preg_match("/welldone\b/i", $res)) {
			return false;
		}
	}
}

function python_syntax_error_offset()
{
	static $offset = null;
	if ($offset !== null) {
		return $offset;
	}
	$marker = '__MJD_CODE_MARKER__';
	$wrapped = python_make_full_code($marker);
	$markerPos = strpos($wrapped, $marker);
	if ($markerPos === false) {
		$offset = 0;
		return $offset;
	}
	$prefix = substr($wrapped, 0, $markerPos);
	$offset = substr_count($prefix, "\n");
	return $offset;
}

function normalize_code_editor_mode($mode)
{
	$mode = strtolower(trim((string)$mode));
	if ($mode === 'text/x-php' || $mode === 'php' || $mode === 'application/x-httpd-php' || $mode === 'application/x-httpd-php-open') {
		return 'php';
	}
	if ($mode === 'text/x-python' || $mode === 'python') {
		return 'python';
	}
	if ($mode === 'html' || $mode === 'htmlmixed' || $mode === 'text/html') {
		return 'htmlmixed';
	}
	if ($mode === 'javascript' || $mode === 'text/javascript' || $mode === 'application/javascript') {
		return 'javascript';
	}
	if ($mode === 'css') {
		return 'css';
	}
	if ($mode === 'xml' || $mode === 'text/xml' || $mode === 'application/xml') {
		return 'xml';
	}
	return '';
}

function code_syntax_error_details($code, $mode = '')
{
	if (!trim((string)$code)) {
		return false;
	}

	$mode = normalize_code_editor_mode($mode);
	if ($mode === 'python') {
		$isPython = true;
	} elseif ($mode === 'php') {
		$isPython = false;
	} else {
		$isPython = isItPythonCode($code);
	}
	$errors = $isPython ? python_syntax_error($code) : php_syntax_error($code);
	if (!$errors) {
		return false;
	}

	$errors = trim((string)$errors);
	$details = array(
		'language' => $isPython ? 'python' : 'php',
		'raw' => $errors,
		'line' => 0,
		'message' => $errors,
		'full' => $errors,
	);

	if ($isPython) {
		if (preg_match('/line\s+(\d+)/i', $errors, $matches)) {
			$line = (int)$matches[1] - (int)python_syntax_error_offset();
			$details['line'] = $line > 0 ? $line : 1;
		}
		$lines = preg_split('/\r?\n/', $errors);
		$lines = array_values(array_filter(array_map('trim', $lines), 'strlen'));
		if (!empty($lines)) {
			$details['message'] = $lines[count($lines) - 1];
		}
		return $details;
	}

	if (preg_match('/on line\s+(\d+)/i', $errors, $matches)) {
		$line = (int)$matches[1] - 2;
		$details['line'] = $line > 0 ? $line : 1;
	}
	$message = preg_replace('/^.*?(Parse error:|Errors parsing)\s*/is', '', $errors);
	$message = preg_replace('/\s+in\s+.*?\s+on line\s+\d+.*$/is', '', $message);
	$message = trim($message);
	if ($message !== '') {
		$details['message'] = $message;
	}
	return $details;
}

