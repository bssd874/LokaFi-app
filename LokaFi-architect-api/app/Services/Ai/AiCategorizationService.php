<?php

namespace App\Services\Ai;

use App\Models\AiCategorySuggestion;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionCategorizationService;
use App\Services\TransactionSanitizationService;
use Illuminate\Support\Collection;

class AiCategorizationService
{
    public const PROMPT_VERSION = 'lokafi-category-v1';
    public const SOURCE_AI = 'ai_suggestion';
    public const SOURCE_AI_ERROR = 'ai_error';
    public const CACHE_TTL_DAYS = 7;
    public const BATCH_LIMIT = 25;

    public function __construct(
        private readonly AiProviderClientInterface $providerClient,
        private readonly AiResponseValidator $validator,
        private readonly TransactionCategorizationService $deterministicService,
        private readonly TransactionSanitizationService $sanitizer,
    ) {
    }

    public function suggest(Transaction $transaction): array
    {
        $transaction = $transaction->fresh(['user', 'category', 'suggestedCategory']);

        if ($transaction->category_id) {
            return [
                'transaction' => $transaction,
                'suggestion' => null,
                'skipped_ai' => true,
                'source' => $transaction->category_source ?: 'current_category',
                'validation_status' => 'skipped_existing_category',
                'user_message' => 'Transaksi sudah memiliki kategori. AI tidak dipanggil.',
            ];
        }

        if ($transaction->type === 'transfer') {
            $this->markReviewRequired($transaction, 'Transfer tidak membutuhkan AI kategori.');

            return [
                'transaction' => $transaction->fresh(['wallet', 'fromWallet', 'toWallet', 'category', 'suggestedCategory', 'categoryLabel']),
                'suggestion' => null,
                'skipped_ai' => true,
                'source' => TransactionCategorizationService::SOURCE_REVIEW_REQUIRED,
                'validation_status' => 'skipped_transfer',
                'user_message' => 'Transfer tidak memakai kategori AI.',
            ];
        }

        $deterministic = $this->deterministicService->suggest($transaction, persist: true);

        if (!$deterministic['review_required']) {
            return [
                'transaction' => $transaction->fresh(['wallet', 'fromWallet', 'toWallet', 'category', 'suggestedCategory', 'categoryLabel']),
                'suggestion' => $deterministic,
                'skipped_ai' => true,
                'source' => $deterministic['source'],
                'validation_status' => 'skipped_deterministic',
                'user_message' => 'Deterministic categorization sudah menemukan suggestion. AI tidak dipanggil.',
            ];
        }

        $allowedCategories = $this->allowedCategories($transaction);
        $payload = $this->buildPayload($transaction, $allowedCategories);
        $inputHash = $this->inputHash($payload);
        $provider = $this->providerName();
        $model = $this->modelName();

        if ($cached = $this->validCachedSuggestion($transaction->user, $inputHash, $provider, $model)) {
            $audit = $this->createAuditFromCache($transaction, $cached);

            return $this->applyValidSuggestion($transaction, $audit, cached: true);
        }

        try {
            $rawResponse = $this->providerClient->categorize($payload);
            $validated = $this->validator->validate($rawResponse, $transaction, $allowedCategories);
        } catch (AiProviderException $exception) {
            $audit = $this->storeAudit(
                transaction: $transaction,
                payload: $payload,
                inputHash: $inputHash,
                categoryId: null,
                confidence: null,
                needsReview: true,
                validationStatus: AiCategorySuggestion::STATUS_PROVIDER_ERROR,
                errorCode: $exception->errorCode,
                reason: 'AI provider belum tersedia. Review manual diperlukan.',
            );

            return $this->applyFailure($transaction, $audit, 'AI provider belum tersedia. Review manual diperlukan.');
        } catch (AiResponseValidationException $exception) {
            $audit = $this->storeAudit(
                transaction: $transaction,
                payload: $payload,
                inputHash: $inputHash,
                categoryId: null,
                confidence: null,
                needsReview: true,
                validationStatus: AiCategorySuggestion::STATUS_INVALID_RESPONSE,
                errorCode: $exception->errorCode,
                reason: 'AI mengembalikan response tidak valid. Review manual diperlukan.',
            );

            return $this->applyFailure($transaction, $audit, 'AI response tidak valid. Review manual diperlukan.');
        }

        $audit = $this->storeAudit(
            transaction: $transaction,
            payload: $payload,
            inputHash: $inputHash,
            categoryId: $validated['category_id'],
            confidence: $validated['confidence'],
            needsReview: $validated['needs_review'],
            validationStatus: AiCategorySuggestion::STATUS_VALID,
            errorCode: null,
            reason: $validated['reason'],
        );

        return $this->applyValidSuggestion($transaction, $audit, cached: false);
    }

    public function accept(Transaction $transaction): Transaction
    {
        $transaction = $transaction->fresh(['suggestedCategory']);

        if (!$transaction->suggestedCategory || $transaction->category_source !== self::SOURCE_AI) {
            abort(422, 'Tidak ada AI suggestion yang bisa diterima.');
        }

        return $this->deterministicService->correctCategory(
            $transaction,
            $transaction->suggestedCategory,
            'accepted_ai_suggestion',
        );
    }

    public function categorizePending(User $user, int $limit): array
    {
        $limit = max(1, min($limit, self::BATCH_LIMIT));

        $transactions = $user->transactions()
            ->whereNull('category_id')
            ->where('type', '!=', 'transfer')
            ->whereIn('categorization_status', [
                TransactionCategorizationService::STATUS_UNCLASSIFIED,
                TransactionCategorizationService::STATUS_REVIEW_REQUIRED,
            ])
            ->oldest('happened_at')
            ->limit($limit)
            ->get();

        $suggested = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($transactions as $transaction) {
            $result = $this->suggest($transaction);

            if (($result['source'] ?? null) === self::SOURCE_AI && ($result['suggestion']['category'] ?? null)) {
                $suggested++;
            } elseif (($result['validation_status'] ?? null) === AiCategorySuggestion::STATUS_PROVIDER_ERROR
                || ($result['validation_status'] ?? null) === AiCategorySuggestion::STATUS_INVALID_RESPONSE) {
                $failed++;
            } else {
                $skipped++;
            }
        }

        return [
            'processed_count' => $transactions->count(),
            'suggested_count' => $suggested,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'limit' => $limit,
        ];
    }

    private function applyValidSuggestion(Transaction $transaction, AiCategorySuggestion $audit, bool $cached): array
    {
        $category = $audit->category;
        $label = $this->confidenceLabel((float) $audit->confidence);
        $score = (int) round(((float) $audit->confidence) * 100);

        $transaction->update([
            'suggested_category_id' => $category?->id,
            'categorization_status' => TransactionCategorizationService::STATUS_REVIEW_REQUIRED,
            'category_source' => self::SOURCE_AI,
            'categorization_confidence' => $label,
            'categorization_confidence_score' => $score,
            'categorization_explanation' => $audit->reason,
            'categorized_at' => null,
        ]);

        return [
            'transaction' => $transaction->fresh(['wallet', 'fromWallet', 'toWallet', 'category', 'suggestedCategory', 'categoryLabel']),
            'suggestion' => $this->formatSuggestion($audit, $cached),
            'skipped_ai' => $cached,
            'source' => self::SOURCE_AI,
            'validation_status' => $audit->validation_status,
            'user_message' => $cached
                ? 'AI suggestion memakai cache terbaru.'
                : 'AI suggestion berhasil dibuat. Review sebelum menerima.',
        ];
    }

    private function applyFailure(Transaction $transaction, AiCategorySuggestion $audit, string $message): array
    {
        $this->markReviewRequired($transaction, $message);

        return [
            'transaction' => $transaction->fresh(['wallet', 'fromWallet', 'toWallet', 'category', 'suggestedCategory', 'categoryLabel']),
            'suggestion' => $this->formatSuggestion($audit, cached: false),
            'skipped_ai' => false,
            'source' => self::SOURCE_AI_ERROR,
            'validation_status' => $audit->validation_status,
            'error_code' => $audit->error_code,
            'user_message' => $message,
        ];
    }

    private function markReviewRequired(Transaction $transaction, string $message): void
    {
        $transaction->update([
            'suggested_category_id' => null,
            'categorization_status' => TransactionCategorizationService::STATUS_REVIEW_REQUIRED,
            'category_source' => self::SOURCE_AI_ERROR,
            'categorization_confidence' => TransactionCategorizationService::CONFIDENCE_NONE,
            'categorization_confidence_score' => 0,
            'categorization_explanation' => $message,
            'categorized_at' => null,
        ]);
    }

    private function storeAudit(
        Transaction $transaction,
        array $payload,
        string $inputHash,
        ?int $categoryId,
        ?float $confidence,
        bool $needsReview,
        string $validationStatus,
        ?string $errorCode,
        string $reason,
    ): AiCategorySuggestion {
        return AiCategorySuggestion::create([
            'user_id' => $transaction->user_id,
            'transaction_id' => $transaction->id,
            'category_id' => $categoryId,
            'provider' => $this->providerName(),
            'model' => $this->modelName(),
            'prompt_version' => self::PROMPT_VERSION,
            'input_hash' => $inputHash,
            'sanitized_input_snapshot' => $payload,
            'confidence' => $confidence,
            'needs_review' => $needsReview,
            'validation_status' => $validationStatus,
            'error_code' => $errorCode,
            'reason' => mb_substr($reason, 0, 300),
        ])->fresh(['category']);
    }

    private function createAuditFromCache(Transaction $transaction, AiCategorySuggestion $cached): AiCategorySuggestion
    {
        return AiCategorySuggestion::create([
            'user_id' => $transaction->user_id,
            'transaction_id' => $transaction->id,
            'category_id' => $cached->category_id,
            'provider' => $cached->provider,
            'model' => $cached->model,
            'prompt_version' => $cached->prompt_version,
            'input_hash' => $cached->input_hash,
            'sanitized_input_snapshot' => $cached->sanitized_input_snapshot,
            'confidence' => $cached->confidence,
            'needs_review' => $cached->needs_review,
            'validation_status' => AiCategorySuggestion::STATUS_CACHED,
            'error_code' => null,
            'reason' => $cached->reason,
        ])->fresh(['category']);
    }

    private function validCachedSuggestion(User $user, string $inputHash, string $provider, ?string $model): ?AiCategorySuggestion
    {
        return AiCategorySuggestion::with('category')
            ->where('user_id', $user->id)
            ->where('input_hash', $inputHash)
            ->where('provider', $provider)
            ->where('model', $model)
            ->where('validation_status', AiCategorySuggestion::STATUS_VALID)
            ->where('created_at', '>=', now()->subDays(self::CACHE_TTL_DAYS))
            ->latest()
            ->first();
    }

    private function buildPayload(Transaction $transaction, Collection $allowedCategories): array
    {
        $description = $transaction->sanitized_description
            ?: $this->sanitizer->sanitizeText(
                $transaction->description ?? $transaction->note ?? $transaction->merchant ?? '',
                $transaction->user,
            );

        return [
            'prompt_version' => self::PROMPT_VERSION,
            'sanitized_description' => $description,
            'normalized_description' => $transaction->normalized_description,
            'normalized_merchant' => $transaction->normalized_merchant,
            'transaction_type' => $transaction->type,
            'source' => $transaction->source ?? 'manual',
            'amount_range' => $this->amountRange((float) $transaction->amount),
            'allowed_categories' => $allowedCategories
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'type' => $category->type,
                ])
                ->values()
                ->all(),
        ];
    }

    private function inputHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function allowedCategories(Transaction $transaction): Collection
    {
        return Category::where('user_id', $transaction->user_id)
            ->where('type', $transaction->type)
            ->orderBy('id')
            ->get();
    }

    private function amountRange(float $amount): string
    {
        return match (true) {
            $amount < 25000 => 'lt_25k',
            $amount < 100000 => '25k_100k',
            $amount < 500000 => '100k_500k',
            $amount < 1000000 => '500k_1m',
            default => 'gte_1m',
        };
    }

    private function confidenceLabel(float $confidence): string
    {
        return match (true) {
            $confidence >= 0.8 => TransactionCategorizationService::CONFIDENCE_HIGH,
            $confidence >= 0.5 => TransactionCategorizationService::CONFIDENCE_MEDIUM,
            $confidence > 0 => TransactionCategorizationService::CONFIDENCE_LOW,
            default => TransactionCategorizationService::CONFIDENCE_NONE,
        };
    }

    private function formatSuggestion(AiCategorySuggestion $audit, bool $cached): array
    {
        return [
            'id' => $audit->id,
            'category' => $audit->category ? [
                'id' => $audit->category->id,
                'name' => $audit->category->name,
                'type' => $audit->category->type,
                'color' => $audit->category->color,
                'icon' => $audit->category->icon,
                'is_default' => $audit->category->is_default,
            ] : null,
            'confidence' => $audit->confidence !== null ? (float) $audit->confidence : null,
            'confidence_label' => $audit->confidence !== null
                ? $this->confidenceLabel((float) $audit->confidence)
                : TransactionCategorizationService::CONFIDENCE_NONE,
            'needs_review' => $audit->needs_review,
            'reason' => $audit->reason,
            'validation_status' => $audit->validation_status,
            'error_code' => $audit->error_code,
            'cached' => $cached,
            'provider' => $audit->provider,
            'model' => $audit->model,
        ];
    }

    private function providerName(): string
    {
        return (string) (config('services.ai.provider') ?: 'disabled');
    }

    private function modelName(): ?string
    {
        $model = config('services.ai.model');

        return is_string($model) && $model !== '' ? $model : null;
    }
}
