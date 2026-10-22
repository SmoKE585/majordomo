<?php

/** System helpers. Public names and argument defaults are part of the module API. */

function callAPI($api_url, $method = 'GET', $params = 0, $wait_response = false)
{
    // TODO: legacy compatibility facade; internal asynchronous work uses the durable queue.
    return \MajorDoMo\Core\Core::api()->request($api_url, $method, $params, $wait_response);
}

function callAPISync($api_url, $method = 'GET', $params = 0)
{
    return callAPI($api_url, $method, $params, true);
}
