<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use InvalidArgumentException;
use MajorDoMo\Core\Database;
use RuntimeException;

/**
 * Настройки провайдеров и моделей; публичные представления не содержат токенов.
 *
 * Изменения:
 * 2026-10-23 — приоритет и выбор включённых моделей эмбеддингов без выдачи токенов.
 * 2026-10-23 — валидация возможностей, шифрование и защита используемых настроек.
 */
final class ModelCatalog
{
    private const SAFE_MODEL_FIELDS = 'ID,PROVIDER_ID,TITLE,MODEL,CAPABILITIES,PARAMETERS,CONTEXT_WINDOW,MAX_OUTPUT,ENABLED,EMBEDDING_PRIORITY';

    public function __construct(private Database $db, private SecretVault $vault) {}

    public function providers(): array
    {
        return $this->db->select('SELECT * FROM ai_assistant_providers ORDER BY TITLE,ID');
    }

    public function provider(int $id): array
    {
        return $this->db->one('SELECT * FROM ai_assistant_providers WHERE ID=' . $id)
            ?? throw new InvalidArgumentException('Провайдер не найден.');
    }

    public function saveProvider(int $id, array $input): int
    {
        if ($id < 0) throw new InvalidArgumentException('Некорректный ID провайдера.');
        if ($id > 0) $this->provider($id);
        $record = array(
            'TITLE' => self::text($input, 'TITLE', 190),
            'BASE_URL' => Endpoint::normalize(self::text($input, 'BASE_URL', 2048)),
            'PROTOCOL' => self::text($input, 'PROTOCOL', 32),
            'TIMEOUT' => self::integer($input, 'TIMEOUT', 1, 600),
            'ENABLED' => self::integer($input, 'ENABLED', 0, 1),
        );
        if ($record['PROTOCOL'] !== 'openai_chat') throw new InvalidArgumentException('Поддерживается протокол OpenAI Chat Completions.');
        return $this->store('ai_assistant_providers', $id, $record);
    }

    public function models(): array
    {
        return array_map(self::decodeModel(...), $this->db->select('SELECT ' . self::SAFE_MODEL_FIELDS
            . ",CASE WHEN TOKEN_CIPHER<>'' THEN 1 ELSE 0 END AS HAS_TOKEN FROM ai_assistant_models ORDER BY TITLE,ID"));
    }

    public function model(int $id): array
    {
        $record = $this->db->one('SELECT ' . self::SAFE_MODEL_FIELDS
            . ",CASE WHEN TOKEN_CIPHER<>'' THEN 1 ELSE 0 END AS HAS_TOKEN FROM ai_assistant_models WHERE ID=" . $id);
        if ($record === null) throw new InvalidArgumentException('Модель не найдена.');
        return self::decodeModel($record);
    }

    /** Меньший приоритет выбирается первым; при равенстве порядок задаёт ID. */
    public function embeddingModels(): array
    {
        $providers = array_column($this->providers(), null, 'ID');
        $models = array_values(array_filter($this->models(), static fn(array $model): bool =>
            (int)$model['ENABLED'] === 1 && (int)($providers[$model['PROVIDER_ID']]['ENABLED'] ?? 0) === 1
            && in_array('embeddings', $model['CAPABILITIES'], true)));
        usort($models, static fn(array $a, array $b): int =>
            ((int)$a['EMBEDDING_PRIORITY'] <=> (int)$b['EMBEDDING_PRIORITY']) ?: ((int)$a['ID'] <=> (int)$b['ID']));
        return $models;
    }

    /** Отсутствующий TOKEN сохраняет ключ; пустой TOKEN явно очищает его. */
    public function saveModel(int $id, array $input): int
    {
        if ($id < 0) throw new InvalidArgumentException('Некорректный ID модели.');
        $previous = $id > 0 ? $this->model($id) : null;
        $providerId = self::integer($input, 'PROVIDER_ID', 1, PHP_INT_MAX);
        $this->provider($providerId);
        $capabilities = $input['CAPABILITIES'] ?? null;
        if (!is_array($capabilities) || !array_is_list($capabilities) || $capabilities === array()) {
            throw new InvalidArgumentException('Выберите возможности модели: чат, Tools, эмбеддинги.');
        }
        foreach ($capabilities as $capability) {
            if (!is_string($capability) || !in_array($capability, array('chat', 'tools', 'embeddings'), true)) {
                throw new InvalidArgumentException('Неизвестная возможность модели.');
            }
        }
        if (count($capabilities) !== count(array_unique($capabilities))) throw new InvalidArgumentException('Возможности модели не должны повторяться.');
        if (in_array('tools', $capabilities, true) && !in_array('chat', $capabilities, true)) {
            throw new InvalidArgumentException('Для Tools модель должна поддерживать чат.');
        }
        $parameters = $input['PARAMETERS'] ?? array();
        if (!is_array($parameters) || array_diff(array_keys($parameters), array('temperature', 'top_p')) !== array()) {
            throw new InvalidArgumentException('Допустимы параметры temperature и top_p.');
        }
        foreach ($parameters as $name => $value) {
            $maximum = $name === 'temperature' ? 2 : 1;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0 || $value > $maximum) {
                throw new InvalidArgumentException('Некорректное значение параметра ' . $name . '.');
            }
        }
        $context = self::integer($input, 'CONTEXT_WINDOW', 256, 10000000);
        $output = self::integer($input, 'MAX_OUTPUT', 1, $context);
        $record = array(
            'PROVIDER_ID' => $providerId,
            'TITLE' => self::text($input, 'TITLE', 190),
            'MODEL' => self::text($input, 'MODEL', 190),
            'CAPABILITIES' => json_encode($capabilities, JSON_THROW_ON_ERROR),
            'PARAMETERS' => json_encode($parameters, JSON_THROW_ON_ERROR),
            'CONTEXT_WINDOW' => $context,
            'MAX_OUTPUT' => $output,
            'ENABLED' => self::integer($input, 'ENABLED', 0, 1),
            'EMBEDDING_PRIORITY' => self::integer(array('EMBEDDING_PRIORITY' => $input['EMBEDDING_PRIORITY'] ?? $previous['EMBEDDING_PRIORITY'] ?? 100), 'EMBEDDING_PRIORITY', 1, 10000),
        );
        if (array_key_exists('TOKEN', $input)) {
            if (!is_string($input['TOKEN']) || strlen($input['TOKEN']) > 8192 || preg_match('/[\x00-\x20\x7f]/', $input['TOKEN'])) {
                throw new InvalidArgumentException('Токен должен быть строкой без пробелов и управляющих символов.');
            }
            $record['TOKEN_CIPHER'] = $this->vault->encrypt($input['TOKEN']);
        } elseif ($previous === null) {
            $record['TOKEN_CIPHER'] = '';
        }
        return $this->store('ai_assistant_models', $id, $record);
    }

    /** Только для серверного выполнения; результат нельзя сериализовать в HTTP. */
    public function connection(int $id, string $capability): array
    {
        $model = $this->model($id);
        $provider = $this->provider((int)$model['PROVIDER_ID']);
        if (!(int)$provider['ENABLED']) throw new RuntimeException('Провайдер выключен. Включите его в настройках.');
        if (!(int)$model['ENABLED']) throw new RuntimeException('Модель выключена. Включите её в настройках.');
        if (!in_array($capability, $model['CAPABILITIES'], true)) throw new RuntimeException('У модели не включена возможность: ' . $capability . '.');
        $raw = $this->db->one('SELECT TOKEN_CIPHER FROM ai_assistant_models WHERE ID=' . $id);
        if ($raw === null) throw new RuntimeException('Модель была удалена. Выберите другую модель.');
        return array('model' => $model, 'provider' => $provider, 'token' => $this->vault->decrypt($raw['TOKEN_CIPHER']));
    }

    public function deleteProvider(int $id): void
    {
        $this->provider($id);
        if ($this->db->one('SELECT ID FROM ai_assistant_models WHERE PROVIDER_ID=' . $id . ' LIMIT 1') !== null) {
            throw new InvalidArgumentException('У провайдера есть модели. Сначала перенесите или удалите их.');
        }
        $this->db->requireExec('DELETE FROM ai_assistant_providers WHERE ID=' . $id);
    }

    public function deleteModel(int $id): void
    {
        $this->model($id);
        if ($this->db->one('SELECT OWNER_TYPE FROM ai_assistant_model_links WHERE MODEL_ID=' . $id . ' LIMIT 1') !== null) {
            throw new InvalidArgumentException('Модель используется агентом или базой знаний. Сначала измените их настройки.');
        }
        $this->db->requireExec('DELETE FROM ai_assistant_models WHERE ID=' . $id);
    }

    private function store(string $table, int $id, array $record): int
    {
        if ($id > 0) {
            $record['ID'] = $id;
            if (!$this->db->update($table, $record)) throw new RuntimeException('Не удалось сохранить настройки ИИ-помощника.');
            return $id;
        }
        $id = $this->db->insert($table, $record);
        if ($id < 1) throw new RuntimeException('Не удалось создать настройки ИИ-помощника.');
        return $id;
    }

    private static function decodeModel(array $record): array
    {
        $record['CAPABILITIES'] = json_decode($record['CAPABILITIES'], true, 16, JSON_THROW_ON_ERROR);
        $record['PARAMETERS'] = json_decode($record['PARAMETERS'], true, 16, JSON_THROW_ON_ERROR);
        $record['HAS_TOKEN'] = (bool)$record['HAS_TOKEN'];
        return $record;
    }

    public static function text(array $input, string $key, int $maximum): string
    {
        if (!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key]) === ''
            || !mb_check_encoding($input[$key], 'UTF-8') || mb_strlen($input[$key]) > $maximum
            || preg_match('/[\x00-\x1f\x7f]/', $input[$key])) {
            throw new InvalidArgumentException('Заполните корректно поле ' . $key . '.');
        }
        return trim($input[$key]);
    }

    public static function integer(array $input, string $key, int $minimum, int $maximum): int
    {
        $value = $input[$key] ?? null;
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int)$value < $minimum || (int)$value > $maximum) {
            throw new InvalidArgumentException('Некорректное значение поля ' . $key . '.');
        }
        return (int)$value;
    }
}
