<?php

namespace App\Services\Ai;

use RuntimeException;

class FinancialInsightValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
    ) {
        parent::__construct($errorCode);
    }
}
