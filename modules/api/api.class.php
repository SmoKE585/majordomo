<?php

use MajorDoMo\Core\{Config, Core};
use MajorDoMo\Core\Api\{Contract, Schema};

/**
 * Admin transport only. API implementation, tokens and hooks belong to Core\Api.
 * Изменения: 2026-10-22 — вкладка входящих webhook и её AJAX-операции.
 */
class api extends module
{
    public function __construct()
    {
        $this->name = 'api'; $this->title = 'API'; $this->module_category = '<#LANG_SECTION_SYSTEM#>';
        $this->checkInstalled();
    }

    public function dbInstall($data) { Schema::install(Core::database()); }

    public function run()
    {
        require_once ROOT . 'lib/ui/MDJUI.php';
        require_once ROOT . 'modules/api/lib/ApiDocs.php';
        require_once ROOT . 'modules/api/lib/ApiHooks.php';
        global $session;
        header('Cache-Control: no-store');
        if (!$session->has('api_csrf')) $session->set('api_csrf', bin2hex(random_bytes(32)));
        $csrf = $session->get('api_csrf');
        $store = Core::apiTokens();
        $hookStore = Core::apiHooks();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $status = 200;
            try {
                if (!hash_equals($csrf, self::posted('_token'))) { $status = 403; throw new InvalidArgumentException('Форма устарела. Обновите страницу.'); }
                $operation = self::posted('operation');
                $issued = null;
                $hookIssued = null;
                if ($operation === 'issue') {
                    $expires = self::posted('expires'); $expiresAt = null;
                    if ($expires !== '') {
                        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $expires);
                        if (!$date || $date->format('Y-m-d\TH:i') !== $expires) throw new InvalidArgumentException('Некорректная дата окончания.');
                        $expiresAt = $date->getTimestamp();
                    }
                    $issued = $store->issue(self::posted('name'), $expiresAt);
                } elseif (in_array($operation, array('rotate', 'revoke'), true)) {
                    $id = self::postedId();
                    if ($operation === 'rotate') $issued = $store->rotate($id);
                    else $store->revoke($id);
                } elseif ($operation === 'hook_create') {
                    $hookIssued = $hookStore->create(
                        self::posted('name'),
                        self::posted('module'),
                        self::posted('handler'),
                        self::postedArray('methods'),
                        self::posted('header_name')
                    );
                } elseif ($operation === 'hook_update') {
                    $hookIssued = $hookStore->update(
                        self::postedId(),
                        self::posted('name'),
                        self::posted('module'),
                        self::posted('handler'),
                        self::postedArray('methods'),
                        self::posted('header_name')
                    );
                } elseif ($operation === 'hook_rotate') {
                    $hookIssued = $hookStore->rotate(self::postedId());
                } elseif ($operation === 'hook_rotate_header') {
                    $hookIssued = $hookStore->rotateHeader(self::postedId());
                } elseif ($operation === 'hook_toggle') {
                    $hookStore->setEnabled(self::postedId(), self::posted('enabled') === '1');
                } elseif ($operation === 'hook_delete') {
                    $hookStore->delete(self::postedId());
                } else throw new InvalidArgumentException('Неизвестное действие.');

                $tokens = $store->all();
                $hooks = ApiHooks::decorate($hookStore->all(), Config::root());
                $listHtml = self::tokenList($tokens, $csrf);
                $hooksHtml = self::hookList($hooks, $csrf);
                $stats = ApiDocs::tokenStats($tokens);
                $hookStats = ApiHooks::stats($hooks);
                $secretHtml = '';
                if ($issued) $secretHtml = self::tokenSecretHtml($issued, $operation);
                $hookSecretHtml = '';
                if ($hookIssued && (!empty($hookIssued['secret']) || !empty($hookIssued['header_secret']))) {
                    $hookSecretHtml = self::hookSecretHtml($hookIssued, $operation);
                }
                $payload = array(
                    'ok' => true,
                    'list_html' => $listHtml,
                    'hook_list_html' => $hooksHtml,
                    'secret_html' => $secretHtml,
                    'hook_secret_html' => $hookSecretHtml,
                    'stats' => $stats,
                    'hook_stats' => $hookStats,
                    'labels' => self::summaryLabels($stats, $hookStats),
                );
            } catch (InvalidArgumentException $error) { $status = $status === 403 ? 403 : 422; $payload = array('ok' => false, 'message' => $error->getMessage()); }
            catch (Throwable $error) {
                error_log('MajorDoMo API admin operation failed: ' . get_class($error));
                $status = 500;
                $payload = array('ok' => false, 'message' => 'Не удалось выполнить операцию. Проверьте схему БД и журнал системы.');
            }
            http_response_code($status); header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        if (isset($_GET['api_spec'])) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="majordomo-openapi.json"');
            echo json_encode(Contract::openApi(ROOTHTML), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $tokens = $store->all();
        $operations = Contract::operations();
        $spec = Contract::openApi(ROOTHTML);
        $hooks = ApiHooks::decorate($hookStore->all(), Config::root());
        $hookStats = ApiHooks::stats($hooks);
        $modules = ApiHooks::moduleOptions(Config::root());
        $handlerOptions = ApiHooks::handlerOptions(Config::root());
        ob_start(); require ROOT . 'templates/api/api.php'; $this->result = $this->parseLinks(ob_get_clean());
    }

    private static function posted(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) throw new InvalidArgumentException('Некорректное поле формы.');
        return $value;
    }

    private static function postedArray(string $key): array
    {
        $value = $_POST[$key] ?? array();
        if (!is_array($value)) throw new InvalidArgumentException('Некорректное поле формы.');
        return array_values(array_filter($value, 'is_string'));
    }

    private static function postedId(): int
    {
        $id = self::posted('id');
        if (!ctype_digit($id) || (int)$id < 1) throw new InvalidArgumentException('Некорректный ID.');
        return (int)$id;
    }

    private static function tokenList(array $tokens, string $csrf): string
    {
        ob_start(); require ROOT . 'templates/api/tokens.php'; return ob_get_clean();
    }

    private static function hookList(array $hooks, string $csrf): string
    {
        ob_start(); require ROOT . 'templates/api/hooks.php'; return ob_get_clean();
    }

    private static function tokenSecretHtml(array $issued, string $operation): string
    {
        return MDJUI::card(
            array(
                'title' => $operation === 'rotate' ? 'Токен перевыпущен' : 'Токен создан',
                'subtitle' => 'Полный токен доступен только сейчас — сохраните его.',
                'icon' => 'key',
            ),
            '<div class="md-ui-form-stack">'
            . MDJUI::hint(array(
                'tone' => 'warning',
                'title' => 'Сохраните токен сейчас',
                'text' => 'После закрытия страницы токен нельзя показать снова. Останется только перевыпустить его.',
            ))
            . MDJUI::codeSample(array('language' => 'text', 'title' => 'API-токен', 'code' => $issued['token'], 'line_numbers' => false, 'wrap' => true))
            . '</div>'
        );
    }

    private static function hookSecretHtml(array $issued, string $operation): string
    {
        $title = array(
            'hook_create' => 'Вход создан',
            'hook_rotate' => 'Ссылка перевыпущена',
            'hook_rotate_header' => 'Секрет заголовка перевыпущен',
            'hook_update' => 'Дополнительный секрет создан',
        )[$operation] ?? 'Секрет входа';
        $blocks = array();
        if (!empty($issued['secret'])) {
            $blocks[] = MDJUI::codeSample(array(
                'language' => 'text',
                'title' => 'Публичная ссылка',
                'code' => ApiHooks::link((string)$issued['secret']),
                'line_numbers' => false,
                'wrap' => true,
            ));
        }
        if (!empty($issued['header_secret'])) {
            $headerName = (string)($issued['header_name'] ?? '');
            $blocks[] = MDJUI::codeSample(array(
                'language' => 'text',
                'title' => $headerName !== '' ? 'Значение заголовка ' . $headerName : 'Дополнительный секрет',
                'code' => (string)$issued['header_secret'],
                'line_numbers' => false,
                'wrap' => true,
            ));
        }
        return MDJUI::card(
            array(
                'title' => $title,
                'subtitle' => 'Полный секрет доступен только сейчас — сохраните его.',
                'icon' => 'webhook',
            ),
            '<div class="md-ui-form-stack">'
            . MDJUI::hint(array(
                'tone' => 'warning',
                'title' => 'Сохраните секрет сейчас',
                'text' => 'После закрытия страницы ссылку и дополнительный секрет нельзя показать снова. Останется только перевыпустить их.',
            ))
            . implode('', $blocks)
            . '</div>'
        );
    }

    /** Counts shown in the hero, KPI cards and tabs, refreshed after every AJAX action. */
    private static function summaryLabels(array $stats, array $hookStats = array()): array
    {
        $operations = Contract::operations();
        $methods = count($operations);
        $groups = count(array_unique(array_column($operations, 'tag')));
        $hero = $methods . ' ' . MDJUI::plural($methods, 'метод', 'метода', 'методов')
            . ' · ' . $groups . ' ' . MDJUI::plural($groups, 'раздел', 'раздела', 'разделов');
        if ($stats['total'] > 0) {
            $hero .= ' · ' . $stats['total'] . ' ' . MDJUI::plural($stats['total'], 'токен', 'токена', 'токенов');
        }
        $hookTotal = (int)($hookStats['total'] ?? 0);
        if ($hookTotal > 0) {
            $hero .= ' · ' . $hookTotal . ' ' . MDJUI::plural($hookTotal, 'вход', 'входа', 'входов') . ' webhook';
        }
        $unlimited = $stats['unlimited'] > 0
            ? 'Из них ' . $stats['unlimited'] . ' ' . MDJUI::plural($stats['unlimited'], 'бессрочный', 'бессрочных', 'бессрочных')
            : ($stats['active'] > 0 ? 'Действуют сейчас' : 'Нет активных токенов');
        $hooksMeta = $hookTotal === 0
            ? 'Входов пока нет'
            : (($hookStats['attention'] ?? 0) > 0
                ? $hookStats['attention'] . ' требуют внимания'
                : 'Все входы в порядке');
        return array(
            'hero' => $hero,
            'tab_total' => (string)$stats['total'],
            'active_meta' => $unlimited,
            'hooks_tab_total' => (string)$hookTotal,
            'hooks_active_meta' => $hooksMeta,
        );
    }
}
