<?php
chdir (dirname (__FILE__) . '/../../');

include_once ('./config.php');
include_once ('./lib/loader.php');

$dir = DOC_ROOT . '/cms/cached/codeeditor';

function ensureCodeEditorDir($dir) {
	if (!is_dir($dir)) {
		@mkdir($dir, 0777, true);
	}
	return is_dir($dir);
}

function cleanupCodeEditorFiles($dir, $ttlSeconds = 2592000) {
	if (!is_dir($dir)) {
		return;
	}
	$now = time();
	foreach (@scandir($dir) as $value) {
		if ($value == '.' || $value == '..') continue;
		$filePath = $dir . '/' . $value;
		if (!is_file($filePath)) continue;
		$fileTime = @filemtime($filePath);
		if (!$fileTime) {
			$fileTime = 0;
		}
		if (($fileTime + $ttlSeconds) < $now) {
			@unlink($filePath);
		}
	}
}

function parseCodeEditorFileTimestamp($value, $safeKey) {
	if (!preg_match('/^autosave_' . preg_quote($safeKey, '/') . '_(\d+)(?:_[a-z0-9]+)?\.cdm$/i', $value, $matches)) {
		return false;
	}
	return (int)$matches[1];
}

function getLatestCodeEditorFile($dir, $safeKey) {
	if (!is_dir($dir)) {
		return '';
	}
	$latestFile = '';
	$latestTimestamp = 0;
	foreach (@scandir($dir) as $value) {
		$timestamp = parseCodeEditorFileTimestamp($value, $safeKey);
		if ($timestamp === false) {
			continue;
		}
		if ($timestamp >= $latestTimestamp) {
			$latestTimestamp = $timestamp;
			$latestFile = $dir . '/' . $value;
		}
	}
	return $latestFile;
}

$action = $_POST['action'];
$id = $_POST['id'];
$md = $_POST['md'];
$key = isset($_POST['key']) ? trim((string)$_POST['key']) : '';
if ($key === '') {
	$key = trim((string)$md . '_' . (string)$id);
}
$safeKey = md5($key);
	
$code = $_POST['code'];
	
//$code = str_replace("!amp;", "&", $code);
//$code = str_replace("!lt;", "<", $code);
//$code = str_replace("!gt;", ">", $code);
//$code = str_replace("!quot;", '"', $code);
//$code = str_replace("!#039;", "'", $code);
	
if($action == 'save' && !empty($key)) {
	if(!ensureCodeEditorDir($dir)) {
		echo json_encode(array('status' => 'error', 'msg' => 'Не удалось создать каталог autosave!',));
		die();
	}

	cleanupCodeEditorFiles($dir);

	$latestFile = getLatestCodeEditorFile($dir, $safeKey);
	if ($latestFile != '' && is_file($latestFile)) {
		$latestCode = LoadFile($latestFile);
		if ((string)$latestCode === (string)$code) {
			echo json_encode(array('status' => 'skip', 'msg' => ''));
			die();
		}
	}

	$fileName = 'autosave_' . $safeKey . '_' . time() . '_' . substr(md5(uniqid('', true)), 0, 8) . '.cdm';
	$filePath = $dir . '/' . $fileName;
	SaveFile($filePath, $code);
	
	echo json_encode(array('status' => 'ok', 'msg' => date('d.m.Y H:i:s'),));
} else if($action == 'restore' && !empty($key)) {
	if(!ensureCodeEditorDir($dir)) {
		echo json_encode(array('status' => 'error', 'msg' => 'Не удалось создать каталог autosave!',));
		die();
	}
	cleanupCodeEditorFiles($dir);
	//Выгружаем файлы
	$files = @scandir($dir);
	if(!is_array($files)) {
		echo json_encode(array('status' => 'ok', 'msg' => array(),));
		die();
	}
	//Выкидываем, все что не относится к запросу
	foreach($files as $fileKey => $value) {
		if($value == '.' || $value == '..') {
			unset($files[$fileKey]);
			continue;
		}
		if(parseCodeEditorFileTimestamp($value, $safeKey) === false) {
			unset($files[$fileKey]);
		}
	}
	rsort($files);
	
	$restoreCode = [];
	
	if(count($files) == 0) {
		echo json_encode(array('status' => 'ok', 'msg' => array(),));
		die();
	}
	
	foreach($files as $fileKey => $value) {
		if($value == '.' || $value == '..') continue;
		$addtime = parseCodeEditorFileTimestamp($value, $safeKey);
		if (!$addtime) continue;
		
		$restoreCode[$fileKey]['name'] = 'Версия от ' . date('d.m.Y H:i:s', $addtime);
		$restoreCode[$fileKey]['create'] = date('d.m.Y H:i:s', $addtime);
		$restoreCode[$fileKey]['code'] = LoadFile($dir.'/'.$value);
	}
	
	echo json_encode(array('status' => 'ok', 'msg' => $restoreCode,));
} else if($action == 'checkcode' && !empty($code)) {
	$code = str_replace("!amp", "&", $code);
	$code = str_replace("!lt", "<", $code);
	$code = str_replace("!gt", ">", $code);
	$code = str_replace("!quot", '"', $code);
	$code = str_replace("!039", "'", $code);
	$code = str_replace("!lpar", "(", $code);
	$code = str_replace("!rpar", ")", $code);
	$code = str_replace("!bsol", "\\", $code);
	$code = str_replace("!num", "#", $code);
	$code = str_replace("!endline", ";", $code);
	$code = str_replace("!newline", "\n", $code);
	$code = str_replace("!plus", "+", $code);
	$code = str_replace("!minus", "-", $code);
	
	$errorDetails = code_syntax_error_details($code, $mode);
	
	echo json_encode(array(
		'status' => 'ok',
		'msg' => $errorDetails ? $errorDetails['full'] : '',
		'details' => $errorDetails,
	));
} else {
	echo 'error';
	http_response_code(404);
	die();
}





