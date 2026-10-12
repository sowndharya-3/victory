<?php

namespace App\Http;

use Exception;

class ApiException extends Exception
{
    public int $statusCode;
    public string $errorCode;
    public array $fields;

    public function __construct(string $message, int $statusCode, string $errorCode, array $fields = [])
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
        $this->fields = $fields;
    }
}
