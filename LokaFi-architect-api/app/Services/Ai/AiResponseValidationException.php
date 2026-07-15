<?php

namespace App\Services\Ai;

use RuntimeException;

class AiResponseValidationException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = 'Invalid AI response.')
    {
        parent::__construct($message);
    }
}
