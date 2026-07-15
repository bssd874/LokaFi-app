<?php

namespace App\Services\Ai;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Collection;

class AiResponseValidator
{
    private const REQUIRED_KEYS = ['category_id', 'confidence', 'needs_review', 'reason'];
    private const ALLOWED_KEYS = ['category_id', 'confidence', 'needs_review', 'reason'];

    /**
     * @throws AiResponseValidationException
     */
    public function validate(string $rawResponse, Transaction $transaction, Collection $allowedCategories): array
    {
        try {
            $decoded = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new AiResponseValidationException('invalid_json');
        }

        if (!is_array($decoded) || array_values($decoded) === $decoded) {
            throw new AiResponseValidationException('invalid_schema');
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw new AiResponseValidationException('missing_fields');
            }
        }

        foreach (array_keys($decoded) as $key) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                throw new AiResponseValidationException('unexpected_fields');
            }
        }

        if (!is_int($decoded['category_id']) && $decoded['category_id'] !== null) {
            throw new AiResponseValidationException('invalid_category_id');
        }

        if (!is_numeric($decoded['confidence'])) {
            throw new AiResponseValidationException('invalid_confidence');
        }

        $confidence = (float) $decoded['confidence'];

        if ($confidence < 0 || $confidence > 1) {
            throw new AiResponseValidationException('invalid_confidence');
        }

        if (!is_bool($decoded['needs_review'])) {
            throw new AiResponseValidationException('invalid_needs_review');
        }

        if (!is_string($decoded['reason']) || trim($decoded['reason']) === '') {
            throw new AiResponseValidationException('invalid_reason');
        }

        $reason = mb_substr(trim($decoded['reason']), 0, 300);

        if (mb_strlen(trim($decoded['reason'])) > 300) {
            throw new AiResponseValidationException('reason_too_long');
        }

        $category = null;

        if ($decoded['category_id'] !== null) {
            $category = $allowedCategories->firstWhere('id', $decoded['category_id']);

            if (!$category instanceof Category) {
                throw new AiResponseValidationException('invalid_category');
            }

            if ($category->user_id !== $transaction->user_id) {
                throw new AiResponseValidationException('invalid_category_owner');
            }

            if ($category->type !== $transaction->type) {
                throw new AiResponseValidationException('category_type_mismatch');
            }
        }

        return [
            'category' => $category,
            'category_id' => $category?->id,
            'confidence' => $confidence,
            'needs_review' => (bool) $decoded['needs_review'],
            'reason' => $reason,
        ];
    }
}
