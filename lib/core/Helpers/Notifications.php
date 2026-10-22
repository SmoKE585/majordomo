<?php

/** System helpers. Public names and argument defaults are part of the module API. */

function mdjCollectRequestFlashNotifications($scope = 'admin')
{
    $notifications = array();

    $ok_msg = gr('ok_msg');
    if ($ok_msg !== '') {
        $notifications[] = array('message' => $ok_msg, 'type' => 'success', 'title' => '');
    }

    $err_msg = gr('err_msg');
    if ($err_msg !== '') {
        $notifications[] = array('message' => $err_msg, 'type' => 'error', 'title' => '');
    }

    $notify_msg = gr('notify_msg');
    if ($notify_msg !== '') {
        $notifications[] = array(
            'message' => $notify_msg,
            'type' => gr('notify_type') !== '' ? gr('notify_type') : 'info',
            'title' => gr('notify_title')
        );
    }

    return $notifications;
}

function mdjConsumeFlashNotifications($scope = 'admin')
{
    $session = mdjEnsureSession();
    return $session->pull('flash:' . $scope, array());
}

function mdjEnsureSession()
{
    return \MajorDoMo\Core\Core::session();
}

function mdjExtractFlashNotificationsFromUrl($url, $scope = 'admin')
{
    $url = (string)$url;
    $parts = parse_url($url);
    if ($parts === false) {
        return $url;
    }

    $query = array();
    if (isset($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    $notifications = array();
    if (!empty($query['ok_msg'])) {
        $notifications[] = array('message' => $query['ok_msg'], 'type' => 'success');
        unset($query['ok_msg']);
    }
    if (!empty($query['err_msg'])) {
        $notifications[] = array('message' => $query['err_msg'], 'type' => 'error');
        unset($query['err_msg']);
    }
    if (!empty($query['notify_msg'])) {
        $notifications[] = array(
            'message' => $query['notify_msg'],
            'type' => !empty($query['notify_type']) ? $query['notify_type'] : 'info',
            'title' => !empty($query['notify_title']) ? $query['notify_title'] : ''
        );
        unset($query['notify_msg'], $query['notify_type'], $query['notify_title']);
    }

    foreach ($notifications as $notification) {
        mdjPushFlashNotification(
            $notification['message'],
            isset($notification['type']) ? $notification['type'] : 'info',
            isset($notification['title']) ? $notification['title'] : '',
            $scope
        );
    }

    $rebuilt = '';
    if (isset($parts['scheme'])) {
        $rebuilt .= $parts['scheme'] . '://';
    }
    if (isset($parts['user'])) {
        $rebuilt .= $parts['user'];
        if (isset($parts['pass'])) {
            $rebuilt .= ':' . $parts['pass'];
        }
        $rebuilt .= '@';
    }
    if (isset($parts['host'])) {
        $rebuilt .= $parts['host'];
    }
    if (isset($parts['port'])) {
        $rebuilt .= ':' . $parts['port'];
    }
    if (isset($parts['path'])) {
        $rebuilt .= $parts['path'];
    }

    $query_string = http_build_query($query);
    if ($query_string !== '') {
        $rebuilt .= '?' . $query_string;
    }
    if (isset($parts['fragment']) && $parts['fragment'] !== '') {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt !== '' ? $rebuilt : $url;
}

function mdjFlashError($message, $title = '', $scope = 'admin')
{
    return mdjPushFlashNotification($message, 'error', $title, $scope);
}

function mdjFlashInfo($message, $title = '', $scope = 'admin')
{
    return mdjPushFlashNotification($message, 'info', $title, $scope);
}

function mdjFlashSuccess($message, $title = '', $scope = 'admin')
{
    return mdjPushFlashNotification($message, 'success', $title, $scope);
}

function mdjFlashWarning($message, $title = '', $scope = 'admin')
{
    return mdjPushFlashNotification($message, 'warning', $title, $scope);
}

function mdjNormalizeFlashType($type)
{
    $type = strtolower(trim((string)$type));
    $map = array(
        'ok' => 'success',
        'success' => 'success',
        'done' => 'success',
        'error' => 'error',
        'danger' => 'error',
        'fail' => 'error',
        'warning' => 'warning',
        'warn' => 'warning',
        'info' => 'info',
        'default' => 'info'
    );

    return isset($map[$type]) ? $map[$type] : 'info';
}

function mdjPushFlashNotification($message, $type = 'info', $title = '', $scope = 'admin')
{
    $message = trim((string)$message);
    if ($message === '') {
        return false;
    }

    $session = mdjEnsureSession();
    $notifications = $session->get('flash:' . $scope, array());
    $notifications[] = array(
        'message' => $message,
        'title' => trim((string)$title),
        'type' => mdjNormalizeFlashType($type)
    );
    $session->set('flash:' . $scope, $notifications);

    return true;
}
