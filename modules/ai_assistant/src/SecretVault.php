<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use MajorDoMo\Core\Config;
use RuntimeException;

/**
 * Шифрует токены отдельно от БД; ключ не передаётся провайдеру или браузеру.
 *
 * Изменения:
 * 2026-10-23 — AES-256-GCM, ключ Config или приватный файл с блокировкой.
 */
final class SecretVault
{
    private ?string $resolvedKey = null;
    private const AAD = 'majordomo-ai-secrets:v1';

    public function __construct(private ?string $configuredKey = null, private ?string $directory = null) {}

    public function encrypt(string $secret): string
    {
        if ($secret === '') return '';
        $key = $this->key();
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD);
        if ($cipher === false) throw new RuntimeException('Не удалось зашифровать токен ИИ-помощника.');
        return 'v1:' . base64_encode($nonce . $tag . $cipher);
    }

    public function decrypt(string $cipher): string
    {
        if ($cipher === '') return '';
        $bytes = str_starts_with($cipher, 'v1:') ? base64_decode(substr($cipher, 3), true) : false;
        if ($bytes === false || strlen($bytes) < 29) throw new RuntimeException('Повреждён зашифрованный токен ИИ-помощника. Задайте токен повторно.');
        $key = $this->key();
        $secret = openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            substr($bytes, 0, 12), substr($bytes, 12, 16), self::AAD);
        if ($secret === false) throw new RuntimeException('Не удалось расшифровать токен ИИ-помощника. Восстановите ключ или задайте токен повторно.');
        return $secret;
    }

    private function key(): string
    {
        if ($this->resolvedKey !== null) return $this->resolvedKey;
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            throw new RuntimeException('Для токенов ИИ-помощника требуется расширение PHP OpenSSL.');
        }
        $encoded = $this->configuredKey ?? Config::string('AI_SECRET_KEY');
        if ($encoded === '') {
            if ($this->configuredKey !== null) throw new RuntimeException('Ключ ИИ-помощника не задан.');
            $encoded = $this->fileKey();
        }
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) throw new RuntimeException('Ключ ИИ-помощника должен содержать 32 случайных байта в base64.');
        return $this->resolvedKey = $key;
    }

    private function fileKey(): string
    {
        $directory = $this->directory ?? Config::root() . '/var/ai_assistant';
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RuntimeException('Не удалось создать приватный каталог ключа ИИ-помощника.');
        }
        $filename = $directory . '/secret.key';
        if (is_link($directory) || is_link($filename)) throw new RuntimeException('Каталог и файл ключа ИИ-помощника не должны быть символическими ссылками.');
        $handle = fopen($filename, 'c+b');
        if ($handle === false) throw new RuntimeException('Не удалось открыть ключ ИИ-помощника. Проверьте права на приватный каталог.');
        try {
            if (!chmod($filename, 0600) || !flock($handle, LOCK_EX)) throw new RuntimeException('Не удалось защитить ключ ИИ-помощника.');
            $size = fstat($handle)['size'];
            if ($size > 128) throw new RuntimeException('Файл ключа ИИ-помощника повреждён.');
            if ($size === 0) {
                $encoded = base64_encode(random_bytes(32));
                if (fwrite($handle, $encoded . "\n") !== strlen($encoded) + 1 || !fflush($handle)) {
                    throw new RuntimeException('Не удалось сохранить ключ ИИ-помощника.');
                }
                return $encoded;
            }
            $encoded = stream_get_contents($handle);
            if ($encoded === false) throw new RuntimeException('Не удалось прочитать ключ ИИ-помощника.');
            return trim($encoded);
        } finally {
            fclose($handle);
        }
    }
}
