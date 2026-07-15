<?php

namespace App\Services\Ai;

class DisabledAiProviderClient implements AiProviderClientInterface
{
    public function __construct(private readonly string $errorCode = 'ai_disabled')
    {
    }

    public function categorize(array $payload): string
    {
        throw new AiProviderException($this->errorCode, 'AI provider is disabled.');
    }
}
