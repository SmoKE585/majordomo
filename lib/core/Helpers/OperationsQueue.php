<?php

/** System helpers. Public names and argument defaults are part of the module API. */

function addToOperationsQueue($topic, $dataname, $datavalue = '', $uniq = false, $ttl = 60)
{
    // TODO: legacy compatibility facade.
    return \MajorDoMo\Core\Core::queue()->enqueue($topic, $dataname, $datavalue, $uniq, $ttl);
}

function checkOperationsQueue($topic)
{
    // TODO: legacy consume-on-read facade; new consumers explicitly claim and acknowledge.
    $items = \MajorDoMo\Core\Core::queue()->claim($topic);
    foreach ($items as $item) \MajorDoMo\Core\Core::queue()->acknowledge($item);
    return $items;
}
