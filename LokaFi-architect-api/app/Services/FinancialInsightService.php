<?php

namespace App\Services;

use App\Models\FinancialInsight;
use App\Models\User;
use App\Services\Ai\AiProviderClientInterface;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\FinancialInsightResponseValidator;
use App\Services\Ai\FinancialInsightValidationException;
use Carbon\CarbonImmutable;

class FinancialInsightService
{
    public function __construct(
        private readonly AiProviderClientInterface $providerClient,
        private readonly FinancialInsightPromptBuilder $promptBuilder,
        private readonly FinancialInsightResponseValidator $validator,
    ) {
    }

    public function show(User $user, array $filters): array
    {
        $context = $this->promptBuilder->build($user, $filters);
        $cached = $this->cachedInsight($user, $context, $filters, requireFresh: false);

        if (!$cached) {
            return $this->formatResponse(
                insight: null,
                context: $context,
                validationStatus: 'not_generated',
                userMessage: 'Belum ada AI explanation untuk periode ini.',
                cached: false,
            );
        }

        return $this->formatResponse(
            insight: $cached,
            context: $context,
            validationStatus: $cached->validation_status,
            userMessage: 'AI explanation cache ditemukan.',
            cached: true,
        );
    }

    public function generate(User $user, array $filters, bool $force = false): array
    {
        $context = $this->promptBuilder->build($user, $filters);

        if (!config('services.ai.financial_insights.enabled')) {
            $record = $this->storeAudit($user, $context, FinancialInsight::STATUS_DISABLED);

            return $this->formatResponse(
                insight: $record,
                context: $context,
                validationStatus: FinancialInsight::STATUS_DISABLED,
                userMessage: 'AI financial insight belum diaktifkan.',
                cached: false,
            );
        }

        if (!$force && $cached = $this->cachedInsight($user, $context, $filters, requireFresh: true)) {
            return $this->formatResponse(
                insight: $cached,
                context: $context,
                validationStatus: FinancialInsight::STATUS_CACHED,
                userMessage: 'AI explanation memakai cache terbaru.',
                cached: true,
            );
        }

        try {
            $validated = $this->requestValidatedInsight(
                $context['input'],
                array_keys($context['supporting_metrics']),
            );
        } catch (AiProviderException $exception) {
            $record = $this->storeAudit(
                user: $user,
                context: $context,
                validationStatus: FinancialInsight::STATUS_PROVIDER_ERROR,
            );

            return $this->formatResponse(
                insight: $record,
                context: $context,
                validationStatus: FinancialInsight::STATUS_PROVIDER_ERROR,
                userMessage: $this->providerErrorMessage($exception),
                cached: false,
                errorCode: $exception->errorCode,
            );
        } catch (FinancialInsightValidationException $exception) {
            $record = $this->storeAudit(
                user: $user,
                context: $context,
                validationStatus: FinancialInsight::STATUS_INVALID_RESPONSE,
            );

            return $this->formatResponse(
                insight: $record,
                context: $context,
                validationStatus: FinancialInsight::STATUS_INVALID_RESPONSE,
                userMessage: 'AI response tidak valid. Financial analytics tetap bisa digunakan.',
                cached: false,
                errorCode: $exception->errorCode,
            );
        }

        $record = $this->storeAudit(
            user: $user,
            context: $context,
            validationStatus: FinancialInsight::STATUS_VALID,
            structuredInsight: $validated,
        );

        return $this->formatResponse(
            insight: $record,
            context: $context,
            validationStatus: FinancialInsight::STATUS_VALID,
            userMessage: 'AI explanation berhasil dibuat.',
            cached: false,
        );
    }

    private function requestValidatedInsight(array $input, array $allowedEvidenceKeys): array
    {
        $lastValidationException = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $raw = $this->providerClient->categorize($input);

            try {
                return $this->validator->validate($raw, $allowedEvidenceKeys);
            } catch (FinancialInsightValidationException $exception) {
                $lastValidationException = $exception;
            }
        }

        throw $lastValidationException;
    }

    public function history(User $user, array $filters): array
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 20), 100));
        $query = $user->financialInsights()
            ->latest('generated_at')
            ->latest('id');

        if (!empty($filters['start_date'])) {
            $query->where('period_start', '>=', CarbonImmutable::parse($filters['start_date'])->toDateString());
        }

        if (!empty($filters['end_date'])) {
            $query->where('period_end', '<=', CarbonImmutable::parse($filters['end_date'])->toDateString());
        }

        $history = $query->paginate($perPage);

        return [
            'items' => collect($history->items())
                ->map(fn (FinancialInsight $insight) => $this->formatHistoryItem($insight))
                ->values(),
            'pagination' => [
                'current_page' => $history->currentPage(),
                'per_page' => $history->perPage(),
                'total' => $history->total(),
            ],
        ];
    }

    private function cachedInsight(User $user, array $context, array $filters, bool $requireFresh): ?FinancialInsight
    {
        $query = $user->financialInsights()
            ->where('prompt_version', $this->promptBuilder->promptVersion())
            ->where('provider', $this->providerName())
            ->where('model', $this->promptBuilder->modelName())
            ->where('input_hash', $context['input_hash'])
            ->where('validation_status', FinancialInsight::STATUS_VALID)
            ->latest('generated_at')
            ->latest('id');

        if ($requireFresh) {
            $query->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', now());
            });
        }

        return $query->first();
    }

    private function storeAudit(
        User $user,
        array $context,
        string $validationStatus,
        ?array $structuredInsight = null,
    ): FinancialInsight {
        $period = $context['input']['period'];
        $cacheHours = (int) config('services.ai.financial_insights.cache_hours', 24);

        return FinancialInsight::create([
            'user_id' => $user->id,
            'period_start' => $period['start'],
            'period_end' => $period['end'],
            'analytics_version' => $context['input']['calculation_version'],
            'input_hash' => $context['input_hash'],
            'provider' => $this->providerName(),
            'model' => $this->promptBuilder->modelName(),
            'prompt_version' => $this->promptBuilder->promptVersion(),
            'structured_insight' => $structuredInsight,
            'validation_status' => $validationStatus,
            'generated_at' => now(),
            'expires_at' => $validationStatus === FinancialInsight::STATUS_VALID
                ? now()->addHours($cacheHours)
                : null,
        ]);
    }

    private function formatResponse(
        ?FinancialInsight $insight,
        array $context,
        string $validationStatus,
        string $userMessage,
        bool $cached,
        ?string $errorCode = null,
    ): array {
        return [
            'insight' => $insight?->structured_insight,
            'record' => $insight ? $this->formatRecord($insight, $cached) : null,
            'validation_status' => $validationStatus,
            'error_code' => $errorCode,
            'cached' => $cached,
            'input_hash' => $context['input_hash'],
            'supporting_metrics' => $context['supporting_metrics'],
            'analytics_period' => $context['input']['period'],
            'calculation_version' => $context['input']['calculation_version'],
            'prompt_version' => $this->promptBuilder->promptVersion(),
            'user_message' => $userMessage,
            'disclaimer' => 'AI-generated insights are informational and based on recorded transaction data. They are not professional financial advice.',
        ];
    }

    private function formatRecord(FinancialInsight $insight, bool $cached): array
    {
        return [
            'id' => $insight->id,
            'period_start' => $insight->period_start?->toDateString(),
            'period_end' => $insight->period_end?->toDateString(),
            'analytics_version' => $insight->analytics_version,
            'provider' => $insight->provider,
            'model' => $insight->model,
            'prompt_version' => $insight->prompt_version,
            'validation_status' => $insight->validation_status,
            'generated_at' => $insight->generated_at?->toIso8601String(),
            'expires_at' => $insight->expires_at?->toIso8601String(),
            'cached' => $cached,
        ];
    }

    private function formatHistoryItem(FinancialInsight $insight): array
    {
        return [
            'id' => $insight->id,
            'period_start' => $insight->period_start?->toDateString(),
            'period_end' => $insight->period_end?->toDateString(),
            'analytics_version' => $insight->analytics_version,
            'provider' => $insight->provider,
            'model' => $insight->model,
            'prompt_version' => $insight->prompt_version,
            'validation_status' => $insight->validation_status,
            'headline' => $insight->structured_insight['headline'] ?? null,
            'generated_at' => $insight->generated_at?->toIso8601String(),
            'expires_at' => $insight->expires_at?->toIso8601String(),
        ];
    }

    private function providerName(): string
    {
        return (string) (config('services.ai.provider') ?: 'disabled');
    }

    private function providerErrorMessage(AiProviderException $exception): string
    {
        return match ($exception->errorCode) {
            'provider_high_demand' => 'Gemini sedang high demand dari sisi Google. Coba Generate lagi beberapa saat lagi.',
            'provider_rate_limited' => 'Gemini menolak request karena quota atau rate limit project sedang tercapai.',
            'provider_auth_failed' => 'Gemini menolak API key atau akses project. Cek API key dan izin Google AI project.',
            'provider_model_unavailable' => 'Model Gemini yang dipilih tidak tersedia untuk API key atau project ini.',
            default => 'AI provider belum tersedia. Financial analytics tetap bisa digunakan.',
        };
    }
}
