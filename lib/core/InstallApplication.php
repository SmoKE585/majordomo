<?php

namespace MajorDoMo\Core;

use MajorDoMo\Core\Install\InstallDatabase;
use MajorDoMo\Core\Install\InstallGate;
use MajorDoMo\Core\Install\InstallSchema;
use MajorDoMo\Core\Install\Installer;

/**
 * First-run setup page (public/install.php).
 *
 * This entry point deliberately boots only the configuration layer. The legacy
 * loader is not loaded: on a fresh installation the database may be missing or
 * credentials may be wrong, and the wizard must still render.
 */
final class InstallApplication extends Application
{
    private const SESSION_NAME = 'md_install';

    public function run(): void
    {
        if (!defined('ALLOW_RUNNING_WITH_ERRORS')) {
            define('ALLOW_RUNNING_WITH_ERRORS', 1);
        }

        $this->bootConfiguration();
        $this->sendSecurityHeaders();
        $this->startInstallSession();

        require_once $this->root . '/lib/ui/MDJUI.php';

        $installer = new Installer($this->root);
        $state = $installer->state();

        if (!empty($state['complete'])) {
            if (isset($_GET['done'])) {
                $this->renderSuccess($installer);
                return;
            }
            if (!headers_sent()) {
                header('Location: ' . $this->panelUrl(), true, 302);
            }
            exit;
        }

        if ($this->isPost()) {
            if (!$this->checkCsrf()) {
                if (($_POST['action'] ?? '') === 'test_db') {
                    $this->json(array(
                        'ok' => false,
                        'message' => 'Сессия формы истекла. Обновите страницу и попробуйте снова.',
                    ), 419);
                }
                http_response_code(422);
                $this->renderWizard($installer, array(
                    'errors' => array('_form' => 'Сессия формы истекла. Обновите страницу и попробуйте снова.'),
                    'start_step' => InstallSchema::STEP_CHECK,
                ));
                return;
            }

            if (($_POST['action'] ?? '') === 'test_db') {
                $status = $installer->testDatabase($_POST);
                $this->json(array(
                    'ok' => !empty($status['ok']) && empty($status['missing']),
                    'connected' => !empty($status['ok']),
                    'message' => (string)($status['message'] ?? ''),
                    'server' => (string)($status['server'] ?? ''),
                    'missing' => $status['missing'] ?? array(),
                    'recommended_missing' => $status['recommended_missing'] ?? array(),
                    'admin_exists' => $status['admin_exists'] ?? null,
                    'needs_admin' => !empty($status['needs_admin']),
                    'empty' => !empty($status['empty']),
                    'can_auto_import' => !empty($status['can_auto_import']),
                ), 200);
            }

            $result = $installer->apply($_POST);
            if (!empty($result['ok'])) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    @session_regenerate_id(true);
                    $_SESSION['md_install_summary'] = $result['summary'] ?? array();
                    $_SESSION['md_install_warnings'] = $result['warnings'] ?? array();
                }
                if (!headers_sent()) {
                    header('Location: ' . $this->installUrl() . '?done=1', true, 303);
                }
                exit;
            }

            http_response_code(422);
            $this->renderWizard($installer, $result);
            return;
        }

        $this->renderWizard($installer, array());
    }

    /**
     * @param array<string,mixed> $result
     */
    private function renderWizard(Installer $installer, array $result): void
    {
        $values = isset($result['values']) && is_array($result['values']) ? $result['values'] : $installer->formValues();
        $errors = isset($result['errors']) && is_array($result['errors']) ? $result['errors'] : array();
        $warnings = isset($result['warnings']) && is_array($result['warnings']) ? $result['warnings'] : array();
        $needsAdmin = isset($result['needs_admin']) ? (bool)$result['needs_admin'] : $installer->needsAdmin();
        $startStep = isset($result['start_step'])
            ? (int)$result['start_step']
            : (isset($result['step']) ? (int)$result['step'] : (isset($_GET['step']) ? (int)$_GET['step'] : 0));

        $environment = $installer->environment();
        $checks = $environment->checks();
        $checkSummary = $environment->summary();

        $this->render(array(
            'installer' => $installer,
            'values' => $values,
            'errors' => $errors,
            'warnings' => $warnings,
            'needsAdmin' => $needsAdmin,
            'startStep' => max(0, min(4, $startStep)),
            'checks' => $checks,
            'checkSummary' => $checkSummary,
            'isSuccess' => false,
            'summary' => array(),
            'successWarnings' => array(),
        ));
    }

    private function renderSuccess(Installer $installer): void
    {
        $summary = array();
        $warnings = array();
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (isset($_SESSION['md_install_summary']) && is_array($_SESSION['md_install_summary'])) {
                $summary = $_SESSION['md_install_summary'];
            }
            if (isset($_SESSION['md_install_warnings']) && is_array($_SESSION['md_install_warnings'])) {
                $warnings = $_SESSION['md_install_warnings'];
            }
        }
        if ($summary === array()) {
            $state = $installer->state();
            $dbStatus = $installer->databaseStatus();
            $settings = array();
            if (!empty($dbStatus['ok'])) {
                $database = new InstallDatabase($dbStatus['config']);
                if ($database->connect()) {
                    $settings = $database->fetchSettings(InstallSchema::dbSettingNames());
                    $database->close();
                }
            }
            $useRedis = Config::has('USE_REDIS') ? Config::get('USE_REDIS') : false;
            $summary = array(
                'project_title' => Config::string('PROJECT_TITLE', 'MajorDoMo'),
                'system_name' => (string)($settings['GENERAL_ALICE_NAME'] ?? Config::string('PROJECT_TITLE', 'MajorDoMo')),
                'base_url' => Config::string('BASE_URL'),
                'root_html' => Config::string('ROOTHTML', '/'),
                'db_name' => (string)($dbStatus['config']['name'] ?? ''),
                'db_server' => (string)($dbStatus['config']['host'] ?? ''),
                'theme' => (string)($settings['THEME'] ?? 'dark'),
                'language' => (string)($settings['SITE_LANGUAGE'] ?? 'ru'),
                'timezone' => (string)($settings['SITE_TIMEZONE'] ?? Config::string('TIMEZONE')),
                'completed_at' => (string)($state['completed_at'] ?? ''),
                'admin_created' => false,
                'redis' => is_string($useRedis) && $useRedis !== '',
                'websockets' => true,
                'websockets_port' => (string)WebSockets\Runtime::PORT,
            );
        }

        $this->render(array(
            'installer' => $installer,
            'values' => array(),
            'errors' => array(),
            'warnings' => array(),
            'needsAdmin' => false,
            'startStep' => 4,
            'checks' => array(),
            'checkSummary' => array('fail' => 0, 'warn' => 0, 'ok' => 0, 'total' => 0, 'blocking' => 0),
            'isSuccess' => true,
            'summary' => $summary,
            'successWarnings' => $warnings,
        ));
    }

    /**
     * @param array<string,mixed> $data
     */
    private function render(array $data): void
    {
        $data['csrfToken'] = $this->csrfToken();
        $data['installUrl'] = $this->installUrl();
        $data['panelUrl'] = $this->panelUrl();
        $data['rootHtml'] = defined('ROOTHTML') ? (string)ROOTHTML : '/';
        $data['assetRoot'] = rtrim($data['rootHtml'], '/') . '/assets/';
        $data['adminCss'] = $data['assetRoot'] . 'admin/admin.css?v=' . $this->assetVersion('assets/admin/admin.css');
        $data['installCss'] = $data['assetRoot'] . 'install/install.css?v=' . $this->assetVersion('assets/install/install.css');
        $data['installJs'] = $data['assetRoot'] . 'install/install.js?v=' . $this->assetVersion('assets/install/install.js');
        $data['projectTitle'] = Config::string('PROJECT_TITLE', 'MajorDoMo');
        $data['pageTitle'] = !empty($data['isSuccess'])
            ? 'Настройка завершена — ' . $data['projectTitle']
            : 'Первичная настройка — ' . $data['projectTitle'];

        $template = $this->root . '/templates/install/install.php';
        if (!is_file($template)) {
            http_response_code(500);
            echo '<h1>Install template is missing</h1><p>' . htmlspecialchars($template, ENT_QUOTES, 'UTF-8') . '</p>';
            return;
        }
        extract($data, EXTR_SKIP);
        require $template;
    }

    private function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Content-Type: text/html; charset=utf-8');
    }

    private function startInstallSession(): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name(self::SESSION_NAME);
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ));
        @session_start();
    }

    private function csrfToken(): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (empty($_SESSION['md_install_csrf']) || !is_string($_SESSION['md_install_csrf'])) {
                $_SESSION['md_install_csrf'] = bin2hex(random_bytes(32));
            }
            return (string)$_SESSION['md_install_csrf'];
        }

        $cookie = isset($_COOKIE['md_install_csrf']) ? (string)$_COOKIE['md_install_csrf'] : '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $cookie)) {
            $cookie = bin2hex(random_bytes(32));
            if (!headers_sent()) {
                $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                setcookie('md_install_csrf', $cookie, array(
                    'expires' => 0,
                    'path' => '/',
                    'secure' => $secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ));
            }
        }
        return $cookie;
    }

    private function checkCsrf(): bool
    {
        $posted = isset($_POST['_token']) ? (string)$_POST['_token'] : '';
        return $posted !== '' && hash_equals($this->csrfToken(), $posted);
    }

    private function isPost(): bool
    {
        return isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'POST';
    }

    private function installUrl(): string
    {
        return InstallGate::installUrl();
    }

    private function panelUrl(): string
    {
        $root = defined('ROOTHTML') ? trim((string)ROOTHTML, '/') : '';
        return ($root === '' ? '' : '/' . $root) . '/panel/';
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function json(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function assetVersion(string $relative): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $mtime = @filemtime($path);
        return $mtime !== false ? (string)$mtime : '1';
    }
}
