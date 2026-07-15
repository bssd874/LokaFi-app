<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;

class HttpAiProviderClient implements AiProviderClientInterface
{
    public function categorize(array $payload): string
    {
        $apiKey = (string) config('services.ai.api_key');
        $baseUrl = rtrim((string) config('services.ai.base_url'), '/');
        $provider = strtolower((string) config('services.ai.provider'));
        $isFinancialInsight = ($payload['task'] ?? null) === 'financial_insight';
        $model = (string) ($isFinancialInsight
            ? (config('services.ai.financial_insights.model') ?: config('services.ai.model'))
            : config('services.ai.model'));
        $timeout = (int) ($isFinancialInsight
            ? config('services.ai.financial_insights.timeout_seconds', 30)
            : config('services.ai.timeout_seconds', 20));

        if ($apiKey === '' || $baseUrl === '' || $model === '') {
            throw new AiProviderException('ai_not_configured', 'AI provider is not configured.');
        }

        if ($provider === 'gemini') {
            return $this->callGemini($apiKey, $baseUrl, $model, $timeout, $payload, $isFinancialInsight);
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->post($baseUrl, [
                    'model' => $model,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $isFinancialInsight
                                ? $this->financialInsightSystemPrompt()
                                : 'Return only JSON matching this schema: {"category_id": integer|null, "confidence": number between 0 and 1, "needs_review": boolean, "reason": string}. Select only from supplied category IDs.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                        ],
                    ],
                ]);
        } catch (\Throwable) {
            throw new AiProviderException('provider_timeout_or_network_error', 'AI provider request failed.');
        }

        if ($response->failed()) {
            throw $this->providerHttpException($response->status(), $response->json());
        }

        $json = $response->json();
        $content = data_get($json, 'choices.0.message.content');

        if (is_string($content) && trim($content) !== '') {
            return $content;
        }

        return json_encode($json, JSON_THROW_ON_ERROR);
    }

    private function callGemini(
        string $apiKey,
        string $baseUrl,
        string $model,
        int $timeout,
        array $payload,
        bool $isFinancialInsight,
    ): string {
        $model = preg_replace('#^models/#', '', $model) ?: $model;
        $baseUrl = $this->normalizeGeminiBaseUrl($baseUrl);
        $url = $baseUrl . '/models/' . rawurlencode($model) . ':generateContent';

        try {
            $response = Http::acceptJson()
                ->timeout($timeout)
                ->withQueryParameters(['key' => $apiKey])
                ->post($url, [
                    'systemInstruction' => [
                        'parts' => [
                            [
                                'text' => $isFinancialInsight
                                    ? $this->financialInsightSystemPrompt()
                                    : 'Return only JSON matching this schema: {"category_id": integer|null, "confidence": number between 0 and 1, "needs_review": boolean, "reason": string}. Select only from supplied category IDs.',
                            ],
                        ],
                    ],
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [
                                ['text' => json_encode($payload, JSON_THROW_ON_ERROR)],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'temperature' => 0.2,
                        'maxOutputTokens' => 900,
                    ],
                ]);
        } catch (\Throwable) {
            throw new AiProviderException('provider_timeout_or_network_error', 'AI provider request failed.');
        }

        if ($response->failed()) {
            throw $this->providerHttpException($response->status(), $response->json());
        }

        $content = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (is_string($content) && trim($content) !== '') {
            return $content;
        }

        throw new AiProviderException('provider_empty_response', 'AI provider returned an empty response.');
    }

    private function normalizeGeminiBaseUrl(string $baseUrl): string
    {
        $baseUrl = $baseUrl !== '' ? $baseUrl : 'https://generativelanguage.googleapis.com/v1beta';
        $baseUrl = preg_replace('#/openai/chat/completions$#', '', rtrim($baseUrl, '/')) ?: $baseUrl;
        $baseUrl = preg_replace('#/models(?:/.*)?$#', '', rtrim($baseUrl, '/')) ?: $baseUrl;

        return rtrim($baseUrl, '/');
    }

    private function providerHttpException(int $status, mixed $body): AiProviderException
    {
        $providerStatus = is_array($body) ? (string) data_get($body, 'error.status') : '';
        $message = is_array($body) ? (string) data_get($body, 'error.message') : '';

        if ($status === 429 || $providerStatus === 'RESOURCE_EXHAUSTED') {
            return new AiProviderException(
                'provider_rate_limited',
                'AI provider quota atau rate limit sedang tercapai.',
            );
        }

        if ($status === 503 || $providerStatus === 'UNAVAILABLE') {
            return new AiProviderException(
                'provider_high_demand',
                'Model AI sedang high demand dari provider. Coba lagi beberapa saat lagi.',
            );
        }

        if ($status === 401 || $status === 403) {
            return new AiProviderException(
                'provider_auth_failed',
                'AI provider menolak API key atau akses project.',
            );
        }

        if ($status === 404) {
            return new AiProviderException(
                'provider_model_unavailable',
                'Model AI tidak tersedia untuk API key atau project ini.',
            );
        }

        return new AiProviderException(
            'provider_http_' . $status,
            $message !== '' ? 'AI provider error: ' . mb_substr($message, 0, 160) : 'AI provider returned an error.',
        );
    }

    private function financialInsightSystemPrompt(): string
    {
        return implode(' ', [
            'Return only JSON matching this schema:',
            '{"headline": string, "summary": string, "highlights": [{"type": "positive|warning|critical|neutral", "title": string, "description": string, "evidence_keys": string[]}], "recommended_actions": [{"priority": "high|medium|low", "title": string, "description": string, "related_metric": string|null}], "disclaimer": string}.',
            'Write every user-facing text field in Bahasa Indonesia.',
            'Use a practical financial-coach tone for Indonesian personal finance users.',
            'Recommended actions should feel specific and actionable, such as reducing the largest discretionary category and moving the calculated target into savings or an emergency fund.',
            'Use only evidence_keys supplied by the user payload.',
            'Every recommended_actions item must include related_metric.',
            'Prefer related_metric action.largest_expense_reduction_20pct for spending reduction when available.',
            'Prefer related_metric action.safe_savings_allocation for savings or emergency fund allocation when available.',
            'Keep the response concise: maximum three highlights and two recommended_actions.',
            'Each text field must be one short sentence.',
            'Do not include explicit numbers, amounts, percentages, dates, account identifiers, or financial product recommendations in any text.',
            'Do not provide investment advice.',
        ]);
    }
}
