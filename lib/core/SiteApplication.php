<?php
namespace MajorDoMo\Core;

/**
 * Корневой редирект и совместимые AJAX-входы админки.
 * Изменения: 2026-10-22 — ID последнего сообщения для однократного показа в UI.
 */
final class SiteApplication extends Application
{
    public function run(): void
    {
        $this->bootConfiguration();
        Install\InstallGate::redirectIfRequired($this->root);
        Auth\AuthGate::enforce($this->root);
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if (empty($_GET) && empty($_POST) && in_array($path, array(Http\Session::cookiePath(), Http\Session::cookiePath() . 'index.php'), true)) {
            header('Location: ' . Http\Session::cookiePath() . 'panel/', true, 302);
            return;
        }
        $this->bootLibraries();
        $this->bootSettings();
        $action = gr('action');
        if (in_array($action, array('ajaxgetglobal', 'ajaxsetglobal', 'getlatestnote'), true)) {
            header('Content-Type: application/json; charset=utf-8');
            if ($action === 'ajaxgetglobal') $data = getGlobal(str_replace('%', '', (string)gr('var')));
            elseif ($action === 'ajaxsetglobal') { setGlobal(str_replace('%', '', (string)gr('var')), gr('value')); $data = 'OK'; }
            else { $note = SQLSelectOne('SELECT ID,MESSAGE FROM shouts WHERE MEMBER_ID=0 ORDER BY ID DESC LIMIT 1'); $data = $note['MESSAGE'] ?? ''; }
            $result = array('DATA' => $data);
            if ($action === 'getlatestnote') $result['ID'] = (int)($note['ID'] ?? 0);
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return;
        }
        if (gr('ajax') && is_string($action) && preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $action)) {
            $file = $this->root . '/modules/' . $action . '/' . $action . '.class.php';
            if (is_file($file)) {
                global $ajax;
                $ajax = 1;
                require_once $file;
                $module = new $action();
                $module->getParams();
                $module->action = 'admin';
                $module->ajax = 1;
                $module->run();
                return;
            }
        }
        require_once $this->root . '/lib/error_page.php';
        mdj_render_404(array('root' => Http\Session::cookiePath(), 'area' => 'site', 'path' => $path));
    }
}
