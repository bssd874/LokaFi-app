<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TransactionCategoryLabelService
{
    public function __construct(private readonly TransactionSanitizationService $sanitizer)
    {
    }

    public function categorize(Transaction $transaction, Category $category, string $labeledBy = 'user'): Transaction
    {
        if ($transaction->user_id !== $category->user_id) {
            abort(403, 'Kategori tidak valid atau bukan milik kamu');
        }

        if ($transaction->type === 'transfer') {
            abort(422, 'Transaksi transfer tidak dapat diberi kategori dataset.');
        }

        if ($category->type !== $transaction->type) {
            abort(422, 'Kategori tidak sesuai dengan tipe transaksi.');
        }

        return DB::transaction(function () use ($transaction, $category, $labeledBy) {
            $description = $transaction->description
                ?? $transaction->note
                ?? $transaction->merchant
                ?? '';

            $sanitizedDescription = $transaction->sanitized_description
                ?: $this->sanitizer->sanitizeText($description, $transaction->user);

            $transaction->update([
                'category_id' => $category->id,
                'sanitized_description' => $sanitizedDescription,
                'categorization_status' => 'categorized',
                'category_source' => $labeledBy === 'user' ? 'user' : 'imported',
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
                    'labeled_by' => $labeledBy,
                    'is_verified' => true,
                ],
            );

            return $transaction->fresh(['wallet', 'fromWallet', 'toWallet', 'category', 'categoryLabel']);
        });
    }

    public function bulkCategorize(Collection $transactions, Category $category): array
    {
        $updated = 0;
        $skipped = 0;

        foreach ($transactions as $transaction) {
            try {
                $this->categorize($transaction, $category);
                $updated++;
            } catch (\Throwable) {
                $skipped++;
            }
        }

        return [
            'updated_count' => $updated,
            'skipped_count' => $skipped,
        ];
    }
}
