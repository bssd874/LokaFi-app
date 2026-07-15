<?php

namespace App\Services\Ai;

interface AiProviderClientInterface
{
    /**
     * @throws AiProviderException
     */
    public function categorize(array $payload): string;
}
