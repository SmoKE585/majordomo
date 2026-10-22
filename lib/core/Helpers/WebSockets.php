<?php

/** System helpers. Public names and argument defaults are part of the module API. */

function getWebSocketClientPath()
{
    return '/majordomo';
}

/** Returns queue acceptance, not delivery to a remote subscriber. */
function postToWebSocket($property, $value, $post_action = 'PostProperty')
{
    if (is_array($property) && is_array($value)) {
        if (count($property) !== count($value)) throw new \InvalidArgumentException('Property/value counts differ');
        $accepted = true;
        $values = array_values($value);
        foreach (array_values($property) as $index => $name) {
            $result = \MajorDoMo\Core\Core::websockets()->post($name, $values[$index], $post_action);
            if (!$result) $accepted = false;
        }
        return $accepted;
    }
    return \MajorDoMo\Core\Core::websockets()->post($property, $value, $post_action);
}

function postToWebSocketQueue($property, $value, $post_action = 'PostProperty')
{
    return \MajorDoMo\Core\Core::websockets()->post($property, $value, $post_action);
}
