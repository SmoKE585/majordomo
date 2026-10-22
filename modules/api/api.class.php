<?php

use MajorDoMo\Core\Core;
use MajorDoMo\Core\Api\{Contract, Schema};

/** Admin transport only. API implementation and tokens belong to Core\Api. */
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
        global $session;
        header('Cache-Control: no-store');
        if (!$session->has('api_csrf')) $session->set('api_csrf', bin2hex(random_bytes(32)));
        $csrf = $session->get('api_csrf');
        $store = Core::apiTokens();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $status = 200;
            try {
                if (!hash_equals($csrf, self::posted('_token'))) { $status = 403; throw new InvalidArgumentException('Форма устарела. Обновите страницу.'); }
                $operation = self::posted('operation');
                $issued = null;
                if ($operation === 'issue') {
                    $expires = self::posted('expires'); $expiresAt = null;
                    if ($expires !== '') {
                        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $expires);
                        if (!$date || $date->format('Y-m-d\TH:i') !== $expires) throw new InvalidArgumentException('Некорректная дата окончания.');
                        $expiresAt = $date->getTimestamp();
                    }
                    $issued = $store->issue(self::posted('name'), $expiresAt);
                } elseif (in_array($operation, array('rotate', 'revoke'), true)) {
                    $id = self::posted('id');
                    if (!ctype_digit($id) || (int)$id < 1) throw new InvalidArgumentException('Некорректный ID токена.');
                    if ($operation === 'rotate') $issued = $store->rotate((int)$id);
                    else $store->revoke((int)$id);
                } else throw new InvalidArgumentException('Неизвестное действие.');
                $tokens = $store->all();
                $listHtml = self::tokenList($tokens, $csrf);
                $stats = ApiDocs::tokenStats($tokens);
                $secretHtml = '';
                if ($issued) {
                    $secretHtml = MDJUI::card(
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
                $payload = array('ok' => true, 'list_html' => $listHtml, 'secret_html' => $secretHtml, 'stats' => $stats, 'labels' => self::summaryLabels($stats));
            } catch (InvalidArgumentException $error) { $status = $status === 403 ? 403 : 422; $payload = array('ok' => false, 'message' => $error->getMessage()); }
            catch (Throwable $error) { $status = 500; $payload = array('ok' => false, 'message' => 'Не удалось выполнить операцию с токеном. Проверьте схему БД.'); }
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
        $tokens = $store->all(); $operations = Contract::operations(); $spec = Contract::openApi(ROOTHTML);
        ob_start(); require ROOT . 'templates/api/api.php'; $this->result = $this->parseLinks(ob_get_clean());
    }

    private static function posted(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) throw new InvalidArgumentException('Некорректное поле формы.');
        return $value;
    }

    private static function tokenList(array $tokens, string $csrf): string
    {
        ob_start(); require ROOT . 'templates/api/tokens.php'; return ob_get_clean();
    }

    /** Counts shown in the hero and token KPI cards, refreshed after every AJAX action. */
    private static function summaryLabels(array $stats): array
    {
        $operations = Contract::operations();
        $methods = count($operations);
        $groups = count(array_unique(array_column($operations, 'tag')));
        $hero = $methods . ' ' . MDJUI::plural($methods, 'метод', 'метода', 'методов')
            . ' · ' . $groups . ' ' . MDJUI::plural($groups, 'раздел', 'раздела', 'разделов');
        if ($stats['total'] > 0) {
            $hero .= ' · ' . $stats['total'] . ' ' . MDJUI::plural($stats['total'], 'токен', 'токена', 'токенов');
        }
        $unlimited = $stats['unlimited'] > 0
            ? 'Из них ' . $stats['unlimited'] . ' ' . MDJUI::plural($stats['unlimited'], 'бессрочный', 'бессрочных', 'бессрочных')
            : ($stats['active'] > 0 ? 'Действуют сейчас' : 'Нет активных токенов');
        return array('hero' => $hero, 'tab_total' => (string)$stats['total'], 'active_meta' => $unlimited);
    }
}
