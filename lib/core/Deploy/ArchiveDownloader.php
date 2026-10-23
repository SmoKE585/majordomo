<?php

namespace MajorDoMo\Core\Deploy;

/**
 * Скачивание архива обновления (GitHub, GitLab, Gitea).
 *
 * Раньше загрузка шла «голым» curl с единственной настройкой `TIMEOUT=600`:
 * если до `codeload.github.com` не отвечал IPv6, соединение висело до
 * системного таймаута, а окно обновления всё это время показывало
 * «Скачиваем архив» без единой новой строки — снаружи это выглядит как
 * зависание. Здесь есть предел на подключение, обрыв при простое, повтор
 * через IPv4, отдельный повтор без проверки сертификата (когда в системе нет
 * корневых сертификатов) и прогресс, по которому видно, что загрузка жива.
 *
 * Один класс на оба входа: веб-обновление (`saverestore`) и
 * `php majordomo deploy:update`.
 *
 * 2026-10-23 — добавлено вместо curl-блока в saverestore::getLatest().
 */
final class ArchiveDownloader
{
    /** Сколько ждём установки соединения. */
    private const CONNECT_TIMEOUT = 20;

    /** Предел на всю загрузку. */
    private const TOTAL_TIMEOUT = 900;

    /** Медленнее этого — считаем, что соединение умерло. */
    private const LOW_SPEED_LIMIT = 1024;

    /** Сколько секунд терпим скорость ниже порога. */
    private const LOW_SPEED_TIME = 60;

    /** Ошибки curl, которые лечатся отключением проверки сертификата. */
    private const TLS_ERRORS = array(51, 58, 59, 60, 77, 83, 90, 91);

    private string $root;
    private bool $allowInsecureFallback;

    public function __construct(string $root, bool $allowInsecureFallback = true)
    {
        $this->root = rtrim($root, '/\\');
        $this->allowInsecureFallback = $allowInsecureFallback;
    }

    /**
     * @param callable|null $progress function(int $downloaded, int $total, int $percent): void
     *        Вызывается не чаще раза в две секунды; $total = 0, если сервер не сообщил размер.
     * @return array{ok:bool,size:int,error:string,attempts:int,insecure:bool,code:int}
     */
    public function download(string $url, string $target, ?callable $progress = null): array
    {
        $url = trim($url);
        if ($url === '') {
            return $this->failure('не задан адрес архива');
        }
        $target = $this->absolute($target);
        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $this->failure('не удалось создать каталог ' . $directory);
        }

        if (!function_exists('curl_init')) {
            return $this->downloadStream($url, $target);
        }

        $partial = $target . '.part';
        $attempts = 0;
        $insecure = false;
        $result = $this->attempt($url, $partial, $progress, false, true);
        $attempts++;

        if (!$result['ok']) {
            $tlsFailure = in_array($result['code'], self::TLS_ERRORS, true);
            if (!$tlsFailure) {
                // Не отвечает IPv6 — повторяем с принудительным IPv4.
                $result = $this->attempt($url, $partial, $progress, true, true);
                $attempts++;
                $tlsFailure = in_array($result['code'], self::TLS_ERRORS, true);
            }
            if (!$result['ok'] && $tlsFailure && $this->allowInsecureFallback) {
                // Сертификат не проверяется только как последняя попытка и
                // только после TLS-ошибки: иначе это молчаливое ослабление.
                $result = $this->attempt($url, $partial, $progress, true, false);
                $attempts++;
                $insecure = $result['ok'];
            }
        }

        if (!$result['ok']) {
            @unlink($partial);
            return array(
                'ok' => false,
                'size' => 0,
                'error' => $result['error'],
                'attempts' => $attempts,
                'insecure' => false,
                'code' => $result['code'],
            );
        }

        if (!@rename($partial, $target)) {
            @unlink($partial);
            return array(
                'ok' => false,
                'size' => 0,
                'error' => 'не удалось переименовать ' . basename($partial) . ' в ' . basename($target),
                'attempts' => $attempts,
                'insecure' => $insecure,
                'code' => 0,
            );
        }

        clearstatcache(true, $target);
        $size = is_file($target) ? (int)filesize($target) : 0;
        if ($size <= 0) {
            @unlink($target);
            return array(
                'ok' => false,
                'size' => 0,
                'error' => 'сервер отдал пустой архив',
                'attempts' => $attempts,
                'insecure' => $insecure,
                'code' => 0,
            );
        }

        return array(
            'ok' => true,
            'size' => $size,
            'error' => '',
            'attempts' => $attempts,
            'insecure' => $insecure,
            'code' => 0,
        );
    }

    /**
     * Одна попытка загрузки.
     *
     * @param callable|null $progress
     * @return array{ok:bool,size:int,error:string,code:int}
     */
    private function attempt(string $url, string $partial, ?callable $progress, bool $forceIpv4, bool $verifyTls): array
    {
        @unlink($partial);
        $handle = @fopen($partial, 'wb');
        if ($handle === false) {
            return array('ok' => false, 'size' => 0, 'error' => 'нет прав на запись в ' . basename($partial), 'code' => 0);
        }
        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);
            return array('ok' => false, 'size' => 0, 'error' => 'не удалось инициализировать cURL', 'code' => 0);
        }

        $options = array(
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_LOW_SPEED_LIMIT => self::LOW_SPEED_LIMIT,
            CURLOPT_LOW_SPEED_TIME => self::LOW_SPEED_TIME,
            CURLOPT_USERAGENT => 'MajorDoMoDeploy/1.0',
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        );
        if ($forceIpv4 && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
            // Через IPv4 иногда попадается старый прокси без HTTP/2.
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }
        if ($progress !== null) {
            $options[CURLOPT_NOPROGRESS] = false;
            $options[CURLOPT_PROGRESSFUNCTION] = $this->progressCallback($progress);
        }
        curl_setopt_array($curl, $options);

        $executed = curl_exec($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = (int)curl_errno($curl);
        $message = (string)curl_error($curl);
        curl_close($curl);
        fclose($handle);

        clearstatcache(true, $partial);
        $size = is_file($partial) ? (int)filesize($partial) : 0;
        $httpOk = $http === 0 || ($http >= 200 && $http < 400);
        if ($executed !== false && $httpOk && $size > 0) {
            return array('ok' => true, 'size' => $size, 'error' => '', 'code' => 0);
        }

        if ($errno > 0) {
            $reason = $message !== '' ? $message : 'curl завершился с кодом ' . $errno;
        } elseif (!$httpOk) {
            $reason = 'сервер ответил HTTP ' . $http;
        } else {
            $reason = 'соединение закрылось до получения файла';
        }
        return array(
            'ok' => false,
            'size' => 0,
            'error' => $reason,
            'code' => $errno > 0 ? $errno : 0,
        );
    }

    /** Загрузка без cURL: медленнее и без прогресса, но не ломает установку. */
    private function downloadStream(string $url, string $target): array
    {
        $partial = $target . '.part';
        @unlink($partial);
        $context = stream_context_create(array(
            'http' => array('timeout' => self::TOTAL_TIMEOUT, 'follow_location' => 1),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $remote = @fopen($url, 'rb', false, $context);
        if ($remote === false) {
            return $this->failure('curl недоступен, а потоковое открытие адреса не удалось');
        }
        $local = @fopen($partial, 'wb');
        if ($local === false) {
            fclose($remote);
            return $this->failure('нет прав на запись в ' . basename($partial));
        }
        $copied = @stream_copy_to_stream($remote, $local);
        fclose($remote);
        fclose($local);
        if ($copied === false || $copied <= 0) {
            @unlink($partial);
            return $this->failure('сервер не отдал данные');
        }
        if (!@rename($partial, $target)) {
            @unlink($partial);
            return $this->failure('не удалось сохранить ' . basename($target));
        }
        clearstatcache(true, $target);
        return array(
            'ok' => true,
            'size' => (int)filesize($target),
            'error' => '',
            'attempts' => 1,
            'insecure' => false,
            'code' => 0,
        );
    }

    /**
     * Прогресс не чаще раза в две секунды и не чаще чем каждые 10%.
     * Ошибка в обработчике не должна прерывать загрузку.
     */
    private function progressCallback(callable $progress): callable
    {
        $lastAt = microtime(true);
        $lastPercent = -1;
        return static function ($resource, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($progress, &$lastAt, &$lastPercent): int {
            $total = (float)$downloadTotal;
            $percent = $total > 0 ? (int)floor(((float)$downloaded) * 100 / $total) : -1;
            $now = microtime(true);
            $due = ($now - $lastAt) >= 2.0 || ($percent >= 0 && $percent >= $lastPercent + 10);
            if (!$due) {
                return 0;
            }
            $lastAt = $now;
            $lastPercent = max($lastPercent, $percent);
            try {
                $progress((int)$downloaded, (int)$total, $percent);
            } catch (\Throwable $error) {
                // Индикатор не имеет права ломать обновление.
            }
            return 0;
        };
    }

    /** @return array{ok:bool,size:int,error:string,attempts:int,insecure:bool,code:int} */
    private function failure(string $message): array
    {
        return array('ok' => false, 'size' => 0, 'error' => $message, 'attempts' => 1, 'insecure' => false, 'code' => 0);
    }

    private function absolute(string $path): string
    {
        if ($path === '') {
            return $this->root;
        }
        if ($path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:#', $path)) {
            return $path;
        }
        return $this->root . '/' . $path;
    }
}
