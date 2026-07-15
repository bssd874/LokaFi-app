<?php

namespace App\Services;

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\TransactionCategoryMapping;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TransactionCategorizationService
{
    public const STATUS_CATEGORIZED = 'categorized';
    public const STATUS_REVIEW_REQUIRED = 'review_required';
    public const STATUS_UNCLASSIFIED = 'unclassified';

    public const SOURCE_VERIFIED_MAPPING = 'verified_mapping';
    public const SOURCE_USER_RULE = 'user_rule';
    public const SOURCE_DEFAULT_RULE = 'default_rule';
    public const SOURCE_HISTORICAL_MAPPING = 'historical_mapping';
    public const SOURCE_REVIEW_REQUIRED = 'review_required';

    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';
    public const CONFIDENCE_NONE = 'none';

    public function __construct(
        private readonly TransactionTextNormalizationService $normalizer,
        private readonly TransactionSanitizationService $sanitizer,
        private readonly TransactionCategoryLabelService $labelService,
        private readonly DefaultCategoryService $defaultCategoryService,
    ) {
    }

    public function suggest(Transaction $transaction, bool $persist = false): array
    {
        $transaction = $this->ensureNormalized($transaction);

        if ($transaction->type === 'transfer') {
            return $this->formatSuggestion(
                transaction: $transaction,
                category: null,
                source: self::SOURCE_REVIEW_REQUIRED,
                confidence: self::CONFIDENCE_NONE,
                score: 0,
                explanation: 'Transfer tidak memakai kategori income/expense.',
                reviewRequired: true,
                autoAssign: false,
                persist: $persist,
            );
        }

        if ($transaction->category_id) {
            return $this->formatSuggestion(
                transaction: $transaction,
                category: $transaction->category()->first(),
                source: $transaction->category_source ?: 'current_category',
                confidence: $transaction->categorization_confidence ?: self::CONFIDENCE_HIGH,
                score: $transaction->categorization_confidence_score ?: 90,
                explanation: $transaction->categorization_explanation ?: 'Transaksi sudah memiliki kategori.',
                reviewRequired: false,
                autoAssign: false,
                persist: $persist,
            );
        }

        $context = $this->context($transaction);

        if ($mapping = $this->findExactMapping($transaction, $context)) {
            return $this->formatSuggestion(
                transaction: $transaction,
                category: $mapping->category,
                source: self::SOURCE_VERIFIED_MAPPING,
                confidence: self::CONFIDENCE_HIGH,
                score: 95,
                explanation: 'Kategori cocok dengan koreksi terverifikasi sebelumnya.',
                reviewRequired: false,
                autoAssign: true,
                persist: $persist,
            );
        }

        if ($ruleCategory = $this->matchRule($transaction->user, $transaction, $context, defaultRules: false)) {
            return $this->formatSuggestion(
                transaction: $transaction,
                category: $ruleCategory['category'],
                source: self::SOURCE_USER_RULE,
                confidence: self::CONFIDENCE_HIGH,
                score: $ruleCategory['score'],
                explanation: "Cocok dengan user rule: {$ruleCategory['rule']->name}.",
                reviewRequired: false,
                autoAssign: true,
                persist: $persist,
            );
        }

        if ($ruleCategory = $this->matchRule($transaction->user, $transaction, $context, defaultRules: true)) {
            return $this->formatSuggestion(
                transaction: $transaction,
                category: $ruleCategory['category'],
                source: self::SOURCE_DEFAULT_RULE,
                confidence: self::CONFIDENCE_MEDIUM,
                score: $ruleCategory['score'],
                explanation: "Cocok dengan safe default rule: {$ruleCategory['rule']->name}.",
                reviewRequired: false,
                autoAssign: true,
                persist: $persist,
            );
        }

        if ($historical = $this->findSimilarHistoricalMapping($transaction, $context)) {
            return $this->formatSuggestion(
                transaction: $transaction,
                category: $historical['mapping']->category,
                source: self::SOURCE_HISTORICAL_MAPPING,
                confidence: $historical['similarity'] >= 0.75 ? self::CONFIDENCE_MEDIUM : self::CONFIDENCE_LOW,
                score: $historical['similarity'] >= 0.75 ? 60 : 45,
                explanation: 'Mirip dengan mapping historis, perlu review user.',
                reviewRequired: true,
                autoAssign: false,
                persist: $persist,
            );
        }

        return $this->formatSuggestion(
            transaction: $transaction,
            category: null,
            source: self::SOURCE_REVIEW_REQUIRED,
            confidence: self::CONFIDENCE_NONE,
            score: 0,
            explanation: 'Belum ada mapping atau rule yang cocok.',
            reviewRequired: true,
            autoAssign: false,
            persist: $persist,
        );
    }

    public function apply(Transaction $transaction): Transaction
    {
        try {
            $suggestion = $this->suggest($transaction);

            if ($suggestion['auto_assign'] && $suggestion['category']) {
                return $this->applyAutomaticCategory($transaction->fresh(), $suggestion);
            }

            $transaction->fresh()->update([
                'suggested_category_id' => $suggestion['category']['id'] ?? null,
                'categorization_status' => self::STATUS_REVIEW_REQUIRED,
                'category_source' => $suggestion['source'],
                'categorization_confidence' => $suggestion['confidence'],
                'categorization_confidence_score' => $suggestion['confidence_score'],
                'categorization_explanation' => $suggestion['explanation'],
                'categorized_at' => null,
            ]);

            return $transaction->fresh(['wallet', 'fromWallet', 'toWallet', 'category', 'suggestedCategory', 'categoryLabel']);
        } catch (\Throwable $exception) {
            Log::warning('Deterministic categorization failed', [
                'transaction_id' => $transaction->id,
                'message' => $exception->getMessage(),
            ]);

            return $transaction->fresh(['wallet', 'fromWallet', 'toWallet', 'category', 'suggestedCategory', 'categoryLabel']);
        }
    }

    public function acceptSuggestion(Transaction $transaction): Transaction
    {
        $transaction = $transaction->fresh(['suggestedCategory']);
        $category = $transaction->suggestedCategory;

        if (!$category) {
            $suggestion = $this->suggest($transaction, persist: true);
            $category = isset($suggestion['category']['id'])
                ? Category::find($suggestion['category']['id'])
                : null;
        }

        if (!$category) {
            throw ValidationException::withMessages([
                'category_id' => 'Tidak ada suggestion yang bisa diterima.',
            ]);
        }

        return $this->correctCategory($transaction, $category, 'accepted_suggestion');
    }

    public function correctCategory(Transaction $transaction, Category $category, string $labeledBy = 'user'): Transaction
    {
        $updated = $this->labelService->categorize($transaction, $category, $labeledBy);

        $updated->update([
            'suggested_category_id' => null,
            'categorization_status' => self::STATUS_CATEGORIZED,
            'category_source' => 'user',
            'categorization_confidence' => self::CONFIDENCE_HIGH,
            'categorization_confidence_score' => 100,
            'categorization_explanation' => 'Kategori dikonfirmasi oleh user dan dipakai sebagai mapping berikutnya.',
            'categorized_at' => now(),
        ]);

        try {
            $this->upsertMappingFromFeedback($updated->fresh(), $category);
        } catch (\Throwable $exception) {
            Log::warning('Category mapping update failed after manual correction', [
                'transaction_id' => $transaction->id,
                'category_id' => $category->id,
                'message' => $exception->getMessage(),
            ]);
        }

        return $updated->fresh(['wallet', 'fromWallet', 'toWallet', 'category', 'suggestedCategory', 'categoryLabel']);
    }

    public function reprocess(User $user, array $transactionIds): array
    {
        $transactions = Transaction::where('user_id', $user->id)
            ->whereIn('id', $transactionIds)
            ->get();

        if ($transactions->count() !== count(array_unique($transactionIds))) {
            abort(403, 'Sebagian transaksi bukan milik kamu.');
        }

        $updated = 0;
        $reviewRequired = 0;
        $skipped = 0;

        foreach ($transactions as $transaction) {
            if ($transaction->type === 'transfer' || $transaction->category_id) {
                $skipped++;
                continue;
            }

            $result = $this->apply($transaction);

            if ($result->category_id) {
                $updated++;
            } elseif ($result->categorization_status === self::STATUS_REVIEW_REQUIRED) {
                $reviewRequired++;
            } else {
                $skipped++;
            }
        }

        return [
            'updated_count' => $updated,
            'review_required_count' => $reviewRequired,
            'skipped_count' => $skipped,
        ];
    }

    private function applyAutomaticCategory(Transaction $transaction, array $suggestion): Transaction
    {
        $category = Category::where('id', $suggestion['category']['id'])
            ->where('user_id', $transaction->user_id)
            ->where('type', $transaction->type)
            ->firstOrFail();

        return DB::transaction(function () use ($transaction, $category, $suggestion) {
            $description = $transaction->description
                ?? $transaction->note
                ?? $transaction->merchant
                ?? '';

            $sanitizedDescription = $transaction->sanitized_description
                ?: $this->sanitizer->sanitizeText($description, $transaction->user);

            $transaction->update([
                'category_id' => $category->id,
                'suggested_category_id' => null,
                'sanitized_description' => $sanitizedDescription,
                'categorization_status' => self::STATUS_CATEGORIZED,
                'category_source' => $suggestion['source'],
                'categorization_confidence' => $suggestion['confidence'],
                'categorization_confidence_score' => $suggestion['confidence_score'],
                'categorization_explanation' => $suggestion['explanation'],
                'categorized_at' => now(),
            ]);

            $transaction->categoryLabel()->updateOrCreate(
                ['transaction_id' => $transaction->id],
                [
                    'user_id' => $transaction->user_id,
                    'category_id' => $category->id,
                    'sanitized_description' => $sanitizedDescription,
                    'transaction_type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'source' => $transaction->source ?? 'manual',
                    'labeled_by' => $suggestion['source'],
                    'is_verified' => false,
                ],
            );

            if ($suggestion['source'] === self::SOURCE_VERIFIED_MAPPING) {
                TransactionCategoryMapping::where('user_id', $transaction->user_id)
                    ->where('category_id', $category->id)
                    ->where('transaction_type', $transaction->type)
                    ->where('source', (string) ($transaction->source ?? 'manual'))
                    ->increment('usage_count', 1, ['last_used_at' => now()]);
            }

            return $transaction->fresh(['wallet', 'fromWallet', 'toWallet', 'category', 'suggestedCategory', 'categoryLabel']);
        });
    }

    private function upsertMappingFromFeedback(Transaction $transaction, Category $category): void
    {
        $transaction = $this->ensureNormalized($transaction);
        $context = $this->context($transaction);

        if ($context['normalized_merchant'] === '' && $context['description_signature'] === '') {
            return;
        }

        $mapping = TransactionCategoryMapping::firstOrNew([
            'user_id' => $transaction->user_id,
            'transaction_type' => $transaction->type,
            'source' => $context['source'],
            'normalized_merchant' => $context['normalized_merchant'],
            'description_signature' => $context['description_signature'],
        ]);

        $mapping->fill([
            'category_id' => $category->id,
            'confidence' => self::CONFIDENCE_HIGH,
            'confidence_score' => 95,
            'usage_count' => ($mapping->exists ? $mapping->usage_count : 0) + 1,
            'last_used_at' => now(),
        ]);

        $mapping->save();
    }

    private function findExactMapping(Transaction $transaction, array $context): ?TransactionCategoryMapping
    {
        if ($context['normalized_merchant'] === '' && $context['description_signature'] === '') {
            return null;
        }

        return TransactionCategoryMapping::with('category')
            ->where('user_id', $transaction->user_id)
            ->where('transaction_type', $transaction->type)
            ->where('source', $context['source'])
            ->where(function ($query) use ($context) {
                if ($context['normalized_merchant'] !== '') {
                    $query->orWhere('normalized_merchant', $context['normalized_merchant']);
                }

                if ($context['description_signature'] !== '') {
                    $query->orWhere('description_signature', $context['description_signature']);
                }
            })
            ->whereHas('category', function ($query) use ($transaction) {
                $query->where('user_id', $transaction->user_id)
                    ->where('type', $transaction->type);
            })
            ->orderByDesc('usage_count')
            ->first();
    }

    private function matchRule(User $user, Transaction $transaction, array $context, bool $defaultRules): ?array
    {
        if ($defaultRules) {
            $this->defaultCategoryService->ensureForUser($user);
        }

        $rules = CategoryRule::query()
            ->with('category')
            ->where('is_active', true)
            ->when($defaultRules, fn ($query) => $query->whereNull('user_id'))
            ->when(!$defaultRules, fn ($query) => $query->where('user_id', $user->id))
            ->where(function ($query) use ($transaction) {
                $query->whereNull('transaction_type')
                    ->orWhere('transaction_type', $transaction->type);
            })
            ->where(function ($query) use ($context) {
                $query->whereNull('source')
                    ->orWhere('source', '')
                    ->orWhere('source', $context['source']);
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (!$this->ruleMatches($rule, $context)) {
                continue;
            }

            $category = $defaultRules
                ? $this->resolveDefaultCategory($user, $rule)
                : $rule->category;

            if (!$category || $category->user_id !== $user->id || $category->type !== $transaction->type) {
                continue;
            }

            return [
                'rule' => $rule,
                'category' => $category,
                'score' => $rule->confidence_score,
            ];
        }

        return null;
    }

    private function ruleMatches(CategoryRule $rule, array $context): bool
    {
        $haystack = trim($context['normalized_merchant'] . ' ' . $context['description_signature']);

        if ($rule->match_type === CategoryRule::MATCH_NORMALIZED_MERCHANT) {
            $pattern = $this->normalizer->canonicalMerchant($rule->pattern);

            return $pattern !== null
                && ($context['normalized_merchant'] === $pattern || str_contains($context['normalized_merchant'], $pattern));
        }

        return $this->normalizer->containsKeyword($haystack, $rule->pattern);
    }

    private function resolveDefaultCategory(User $user, CategoryRule $rule): ?Category
    {
        if (!$rule->default_category_name || !$rule->default_category_type) {
            return null;
        }

        return Category::where('user_id', $user->id)
            ->where('name', $rule->default_category_name)
            ->where('type', $rule->default_category_type)
            ->first();
    }

    private function findSimilarHistoricalMapping(Transaction $transaction, array $context): ?array
    {
        $mappings = TransactionCategoryMapping::with('category')
            ->where('user_id', $transaction->user_id)
            ->where('transaction_type', $transaction->type)
            ->where('source', $context['source'])
            ->whereHas('category', function ($query) use ($transaction) {
                $query->where('user_id', $transaction->user_id)
                    ->where('type', $transaction->type);
            })
            ->orderByDesc('usage_count')
            ->limit(50)
            ->get();

        $best = null;
        $bestScore = 0.0;

        foreach ($mappings as $mapping) {
            $score = max(
                $this->normalizer->similarity($context['normalized_merchant'], $mapping->normalized_merchant),
                $this->normalizer->similarity($context['description_signature'], $mapping->description_signature),
            );

            if ($score > $bestScore) {
                $best = $mapping;
                $bestScore = $score;
            }
        }

        if (!$best || $bestScore < 0.5) {
            return null;
        }

        return [
            'mapping' => $best,
            'similarity' => $bestScore,
        ];
    }

    private function ensureNormalized(Transaction $transaction): Transaction
    {
        $description = $transaction->sanitized_description
            ?: $this->sanitizer->sanitizeText(
                $transaction->description ?? $transaction->note ?? $transaction->merchant ?? '',
                $transaction->user,
            );

        $merchant = $transaction->merchant;
        $normalizedMerchant = $transaction->normalized_merchant
            ?: $this->normalizer->canonicalMerchant($merchant, $description);
        $descriptionSignature = $transaction->normalized_description
            ?: $this->normalizer->descriptionSignature($description, $merchant);

        $updates = [];

        if (!$transaction->sanitized_description && $description !== '') {
            $updates['sanitized_description'] = $description;
        }

        if (!$transaction->normalized_merchant && $normalizedMerchant) {
            $updates['normalized_merchant'] = $normalizedMerchant;
        }

        if (!$transaction->normalized_description && $descriptionSignature) {
            $updates['normalized_description'] = $descriptionSignature;
        }

        if ($updates !== []) {
            $transaction->update($updates);
            $transaction = $transaction->fresh();
        }

        return $transaction;
    }

    private function context(Transaction $transaction): array
    {
        return [
            'source' => (string) ($transaction->source ?? 'manual'),
            'normalized_merchant' => (string) ($transaction->normalized_merchant ?? ''),
            'description_signature' => (string) ($transaction->normalized_description ?? ''),
        ];
    }

    private function formatSuggestion(
        Transaction $transaction,
        ?Category $category,
        string $source,
        string $confidence,
        int $score,
        string $explanation,
        bool $reviewRequired,
        bool $autoAssign,
        bool $persist,
    ): array {
        if ($persist && !$transaction->category_id) {
            $transaction->update([
                'suggested_category_id' => $category?->id,
                'categorization_status' => $reviewRequired ? self::STATUS_REVIEW_REQUIRED : self::STATUS_UNCLASSIFIED,
                'category_source' => $source,
                'categorization_confidence' => $confidence,
                'categorization_confidence_score' => $score,
                'categorization_explanation' => $explanation,
            ]);
        }

        return [
            'transaction_id' => $transaction->id,
            'category' => $category ? [
                'id' => $category->id,
                'name' => $category->name,
                'type' => $category->type,
                'color' => $category->color,
                'icon' => $category->icon,
                'is_default' => $category->is_default,
            ] : null,
            'source' => $source,
            'confidence' => $confidence,
            'confidence_score' => $score,
            'explanation' => $explanation,
            'review_required' => $reviewRequired,
            'auto_assign' => $autoAssign,
        ];
    }
}
