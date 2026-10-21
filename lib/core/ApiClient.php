<?php

namespace MajorDoMo\Core;

final class ApiClient
{
    public function __construct(private OperationsQueue $queue, private float $timeout = 45.0) {}

    public function request($path, $method = 'GET', $params = 0, $wait = false): mixed
    {
        if (is_array($method)) { $params = $method; $method = 'GET'; }
        $params = is_array($params) ? $params : array();
        if (!$wait && preg_match('#^/api/(method|modulePropertySet)/([^/?]+)$#i', $path, $match)) {
            $payload = array('target' => rawurldecode($match[2]), 'path' => $path, 'params' => $params);
            return (bool)$this->queue->enqueue('core_runtime', strtolower($match[1]), json_encode($payload, JSON_THROW_ON_ERROR), false, 3600);
        }
        $params['no_session'] = 1;
        $url = preg_replace('#^/api/#', rtrim(BASE_URL, '/') . '/api.php/', $path);
        $url = preg_replace('#([^:])//#', '$1/', $url);
        $client = curl_init();
        try {
            $timeout = $wait ? $this->timeout : min(0.8, $this->timeout);
            curl_setopt_array($client, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => max(1, (int)(min($timeout, 10.0) * 1000)),
                CURLOPT_TIMEOUT_MS => max(1, (int)($timeout * 1000)),
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_NOSIGNAL => true
            ));
            if (strtoupper($method) === 'GET') $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
            elseif (strtoupper($method) === 'POST') curl_setopt_array($client, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params));
            else curl_setopt($client, CURLOPT_CUSTOMREQUEST, strtoupper($method));
            curl_setopt($client, CURLOPT_URL, $url);
            $body = curl_exec($client);
            $status = (int)curl_getinfo($client, CURLINFO_RESPONSE_CODE);
            if ($body === false || curl_errno($client) || $status < 200 || $status >= 400) return false;
            if ($body === '') return true;
            $data = json_decode($body, true);
            if (is_array($data)) return array_key_exists('apiHandleResult', $data) ? $data['apiHandleResult'] : $data;
            return $body;
        } finally {
            curl_close($client);
        }
    }
}
