<?php

$mode2 = gr('mode2');

$rec = SQLSelectOne("SELECT * FROM project_modules WHERE NAME='" . DBSafe($name) . "'");
$rec['NAME'] = $name;

if ($mode2 == 'download') {
    //dprint("download");
    if (is_dir(ROOT . 'cms/saverestore/temp')) {
        removeTree(ROOT . 'cms/saverestore/temp');
    }
    mkdir(ROOT . 'cms/saverestore/temp', 0777);
    mkdir(ROOT . 'cms/saverestore/temp/' . $name, 0777);

    mkdir(ROOT . 'cms/saverestore/temp/' . $name . "/modules", 0777);
    mkdir(ROOT . 'cms/saverestore/temp/' . $name . "/modules/" . $name, 0777);
    copyTree(ROOT . 'modules/' . $name, ROOT . 'cms/saverestore/temp/' . $name . "/modules/" . $name);

    mkdir(ROOT . 'cms/saverestore/temp/' . $name . "/templates", 0777);
    mkdir(ROOT . 'cms/saverestore/temp/' . $name . "/templates/" . $name, 0777);
    copyTree(ROOT . 'templates/' . $name, ROOT . 'cms/saverestore/temp/' . $name . "/templates/" . $name);

    if (file_exists(ROOT . 'scripts/cycle_' . $name . '.php')) {
        mkdir(ROOT . 'cms/saverestore/temp/' . $name . "/scripts", 0777);
        copy(ROOT . 'scripts/cycle_' . $name . '.php', ROOT . 'cms/saverestore/temp/' . $name . "/scripts/cycle_" . $name . ".php");
    }

    if (file_exists(ROOT . 'cms/modules_installed/' . $name . '.files')) {
        $data = Loadfile(ROOT . 'cms/modules_installed/' . $name . '.files');
        $tmp = explode("\n", $data);
        foreach ($tmp as $filename) {
            $filename = str_replace(ROOT, '', $filename);
            $target_path = ROOT . 'cms/saverestore/temp/' . $name . "/" . $filename;
            if (is_file(ROOT . $filename) && !is_file($target_path)) {
                copyFile(ROOT . $filename, $target_path);
            }
        }
    }


    $tar_name = $name . '_' . date('Y-m-d__H-i-s');
    $tar_name .= IsWindowsOS() ? '.tar' : '.tgz';

    if (IsWindowsOS()) {
        $result = exec('tar.exe --strip-components=2 -C ./cms/saverestore/temp/ -cvf ./cms/saverestore/' . $tar_name . ' ./');
        $new_name = str_replace('.tar', '.tar.gz', $tar_name);
        $result = exec('gzip.exe ./cms/saverestore/' . $tar_name);
        if (file_exists('./cms/saverestore/' . $new_name)) {
            $tar_name = $new_name;
        }
    } else {
        chdir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp');
        exec('tar cvzf ../' . $tar_name . ' .');
        chdir('../../../');
    }

    removeTree(ROOT . 'cms/saverestore/temp');
    $this->redirect(ROOTHTML . 'cms/saverestore/' . $tar_name);

} elseif ($mode2 == "update") {
    global $title;
    global $category;
    $rec['TITLE'] = $title;
    $rec['CATEGORY'] = $category;
    SQLUpdate("project_modules", $rec);
    $this->redirect("?name=$name&mode=edit");
} elseif ($mode2 == "show") {
    if ($rec['HIDDEN']) {
        $rec['HIDDEN'] = 0;
    } else {
        $rec['HIDDEN'] = 1;
    }
    SQLUpdate('project_modules', $rec);
    $this->redirect("?");

} elseif ($mode2 == "ignore") {
    SQLExec("DELETE FROM ignore_updates WHERE NAME LIKE '" . DBSafe($rec['NAME']) . "'");
    $tmp = array();
    $tmp['NAME'] = $rec['NAME'];
    SQLInsert('ignore_updates', $tmp);
    $this->redirect("?");
} elseif ($mode2 == "unignore") {
    SQLExec("DELETE FROM ignore_updates WHERE NAME LIKE '" . DBSafe($rec['NAME']) . "'");
    $this->redirect("?");
} elseif ($mode2 == "install") {
    $rec = SQLSelectOne("SELECT * FROM project_modules WHERE NAME='" . $name . "'");
    //SQLExec("DELETE FROM project_modules WHERE NAME='" . $name . "'");
    @unlink(ROOT . 'cms/modules_installed/' . $name . ".installed");
    include_once(DIR_MODULES . $name . "/" . $name . ".class.php");
    $code = '$module_instance = new ' . $name . ";\n";
    setEvalCode($code);
    @eval($code);
    setEvalCode();
    SQLExec("UPDATE project_modules SET HIDDEN='" . (int)$rec['HIDDEN'] . "' WHERE NAME='" . $name . "'");
    // redirect to edit
    $this->redirect("?name=$name&mode=edit");
} elseif ($mode2 == 'uninstall') {
    SQLExec("DELETE FROM project_modules WHERE NAME='" . $name . "'");
    @unlink(ROOT . 'cms/modules_installed/' . $name . ".installed");
    if (is_dir(DIR_MODULES . $name)) {
        include_once(DIR_MODULES . $name . '/' . $name . '.class.php');
        SQLExec("DELETE FROM project_modules WHERE NAME LIKE '" . DBSafe($name) . "'");
        $code = '$plugin = new ' . $name . '();$plugin->uninstall();';
        setEvalCode($code);
        eval($code);
        setEvalCode();
        removeTree(DIR_MODULES . $name);
        removeTree(DIR_TEMPLATES . $name);
        $cycle_name = ROOT . 'scripts/cycle_' . $name . '.php';
        if (file_exists($cycle_name)) {
            @unlink($cycle_name);
        }
        removeMissingSubscribers();
    }
    $this->redirect("?");
}

$title = isset($rec['TITLE']) && trim((string)$rec['TITLE']) !== ''
    ? $this->resolveLanguageString($rec['TITLE'])
    : $name;
$rec['TITLE'] = $title;

$category = isset($rec['CATEGORY']) && trim((string)$rec['CATEGORY']) !== ''
    ? $this->resolveLanguageString($rec['CATEGORY'])
    : 'Без категории';
$rec['CATEGORY'] = $category;

$added_ts = !empty($rec['ADDED']) ? strtotime((string)$rec['ADDED']) : false;
$rec['ADDED_FORMATTED'] = $added_ts ? date('d.m.Y H:i', $added_ts) : '';
$rec['ADDED_ISO'] = $added_ts ? date('c', $added_ts) : '';

outHash($rec, $out);
$out['NAME'] = $name;
$out['TITLE'] = $title;
$out['CATEGORY'] = $category;
$out['TITLE_HTML'] = htmlspecialchars($title, ENT_QUOTES);
$out['NAME_HTML'] = htmlspecialchars($name, ENT_QUOTES);
$out['CATEGORY_HTML'] = htmlspecialchars($category, ENT_QUOTES);
$out['IS_HIDDEN'] = !empty($rec['HIDDEN']) ? 1 : 0;
$out['HIDDEN_LABEL'] = $out['IS_HIDDEN'] ? 'Скрыт' : 'В меню';
$out['HIDDEN_STATE'] = $out['IS_HIDDEN'] ? 'hidden' : 'visible';

$icon_path = ROOT . 'public/assets/img/modules/' . $name . '.png';
$out['HAS_ICON'] = is_file($icon_path) ? 1 : 0;
$out['ICON_URL'] = $out['HAS_ICON'] ? ROOTHTML . 'assets/img/modules/' . rawurlencode($name) . '.png' : '';

// Получим конфиг модуля

include_once(DIR_MODULES . $name . '/' . $name . '.class.php');
$module = new $name();

$genConfig = array();
$iter = 0;

$config = $module->getConfig();
if (is_array($config)) {
    foreach ($config as $key => $value) {
        $value_display = $value;
        if (is_array($value) || is_object($value)) {
            $json_options = 0;
            if (defined('JSON_UNESCAPED_UNICODE')) {
                $json_options |= JSON_UNESCAPED_UNICODE;
            }
            if (defined('JSON_UNESCAPED_SLASHES')) {
                $json_options |= JSON_UNESCAPED_SLASHES;
            }
            $value_display = json_encode($value, $json_options);
        }
        $genConfig[$iter]['KEY'] = $key;
        $genConfig[$iter]['KEY_HTML'] = htmlspecialchars((string)$key, ENT_QUOTES);
        $genConfig[$iter]['VALUE'] = $value_display;
        $genConfig[$iter]['VALUE_HTML'] = htmlspecialchars((string)$value_display, ENT_QUOTES);
        $iter++;
    }
    $out['MODULE_CONFIG'] = $genConfig;
}
$out['MODULE_CONFIG_TOTAL'] = count($genConfig);
$out['HAS_MODULE_CONFIG'] = count($genConfig) > 0 ? 1 : 0;

$url = "https://connect.smartliving.ru/market/?op=list&search=" . urlencode($title);
$http_context = stream_context_create(array(
    'http' => array('timeout' => 4),
));
$market_response = @file_get_contents($url, false, $http_context);
$marketInfo = is_string($market_response) && $market_response !== ''
    ? json_decode($market_response, true)
    : null;
$market_plugin = is_array($marketInfo) && isset($marketInfo['PLUGINS'][0]) && is_array($marketInfo['PLUGINS'][0])
    ? $marketInfo['PLUGINS'][0]
    : null;
if ($market_plugin !== null) {
    $out['MARKET_ID'] = isset($market_plugin['ID']) ? $market_plugin['ID'] : '';
    $out['MARKET_REPOSITORY_URL'] = isset($market_plugin['REPOSITORY_URL']) ? $market_plugin['REPOSITORY_URL'] : '';
    $out['MARKET_AUTHOR'] = isset($market_plugin['AUTHOR']) ? $market_plugin['AUTHOR'] : '';
    $out['MARKET_SUPPORT_URL'] = isset($market_plugin['SUPPORT_URL']) ? $market_plugin['SUPPORT_URL'] : '';
    $out['MARKET_DESCRIPTION_RU'] = isset($market_plugin['DESCRIPTION_RU']) ? $market_plugin['DESCRIPTION_RU'] : '';
    $out['MARKET_LATEST_VERSION'] = isset($market_plugin['LATEST_VERSION']) ? $market_plugin['LATEST_VERSION'] : '';
    $out['MARKET_PRICE'] = isset($market_plugin['PRICE']) ? $market_plugin['PRICE'] : '';
    $out['MARKET_URL'] = isset($market_plugin['URL']) ? $market_plugin['URL'] : '';
    $out['MARKET_LATEST_VERSION_COMMENT'] = isset($market_plugin['LATEST_VERSION_COMMENT']) ? $market_plugin['LATEST_VERSION_COMMENT'] : '';
    $out['MARKET_LATEST_VERSION_URL'] = isset($market_plugin['LATEST_VERSION_URL']) ? $market_plugin['LATEST_VERSION_URL'] : '';
    $out['MARKET_HAS_INFO'] = 1;
} else {
    $out['MARKET_HAS_INFO'] = 0;
}