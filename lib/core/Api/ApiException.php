<?php

namespace MajorDoMo\Core\Api;

final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly array $details = array(), public readonly array $headers = array())
    {
        parent::__construct($message);
    }
}
