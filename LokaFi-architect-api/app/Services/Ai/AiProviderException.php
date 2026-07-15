<?php

namespace App\Services\Ai;

use RuntimeException;

class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = 'AI provider unavailable.',
    ) {
        parent::__construct($message);
    }
}
