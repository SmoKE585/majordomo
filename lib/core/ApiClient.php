<?php

namespace MajorDoMo\Core;

final class ApiClient
{
    public function __construct(private OperationsQueue $queue, private float $timeout = 45.0) {}

    public function request($path, $method = 'GET', $params = 0, $wait = false): mixed
    {
        if (is_array($method)) { $params = $method; $method = 'GET'; }
        $params = is_array($params) ? $params : array();
        // Relative API paths are trusted in-process calls; absolute URLs retain
        // the legacy remote HTTP client contract.
        if (str_starts_with($path, '/api/') || str_starts_with($path, '/api.php/')) return $this->localRequest($path, strtoupper($method), $params, (bool)$wait);
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
    private function localRequest(string $path, string $method, array $params, bool $wait): mixed
    {
        $uri = parse_url($path); $query = array();
        parse_str((string)($uri['query'] ?? ''), $query);
        $params = array_replace($query, $params);
        PropertyAudit::attach($params);
        if (!isset($params['_chain']) && PropertyChain::current()) $params['_chain'] = PropertyChain::current();
        $path = (string)($uri['path'] ?? '');
        $resource = strtolower(explode('/', trim(preg_replace('#^/api(?:\.php)?/#', '', $path), '/'))[0] ?? '');
        if (!$wait && preg_match('#^/api/(method|modulePropertySet)/([^/?]+)$#i', $path, $match)) {
            $payload = array('target' => rawurldecode($match[2]), 'path' => $path, 'params' => $params);
            return (bool)$this->queue->enqueue('core_runtime', strtolower($match[1]), json_encode($payload, JSON_THROW_ON_ERROR), false, 3600);
        }
        if (!$wait && (in_array($resource, array('script', 'events', 'module'), true) || ($method !== 'GET' && $resource !== 'v1'))) {
            return (bool)$this->queue->enqueue('core_runtime', 'api', json_encode(array('target' => $resource, 'path' => $path, 'method' => $method, 'params' => $params), JSON_THROW_ON_ERROR), false, 3600);
        }
        $saved = array($_GET, $_POST, $_REQUEST, $_SERVER);
        $level = ob_get_level(); ob_start(); $adopted = false;
        try {
            $_GET = $params; $_POST = $method === 'GET' ? array() : $params; $_REQUEST = $params;
            $_SERVER['REQUEST_URI'] = $path; $_SERVER['REQUEST_METHOD'] = $method;
            $adopted = Core::audit()->adoptRequest($params);
            $request = new Api\Request($method, $path, $method === 'GET' ? $params : array(), $method === 'GET' ? array() : $params);
            $value = Core::apiDispatcher()->dispatch($request);
            $response = (new Api\Response(bin2hex(random_bytes(16)), microtime(true)))->success($value, $request->legacy, $request);
            return $request->legacy && array_key_exists('apiHandleResult', $response) ? $response['apiHandleResult'] : $response;
        } catch (Api\ApiException $error) {
            if (function_exists('DebMes')) DebMes('Internal API failed: ' . $error->errorCode, 'api', 'warning');
            return false;
        } finally {
            if ($adopted) Core::audit()->leave();
            while (ob_get_level() > $level) ob_end_clean();
            [$_GET, $_POST, $_REQUEST, $_SERVER] = $saved;
        }
    }
}
