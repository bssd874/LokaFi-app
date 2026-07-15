<?php

namespace App\Services;

use App\Models\TransactionCategoryLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class TransactionDatasetExportService
{
    public function __construct(private readonly TransactionSanitizationService $sanitizer)
    {
    }

    public function query(?int $userId = null, bool $verifiedOnly = true): Builder
    {
        return TransactionCategoryLabel::query()
            ->with(['category', 'user'])
            ->when($userId, fn (Builder $query) => $query->where('user_id', $userId))
            ->when($verifiedOnly, fn (Builder $query) => $query->where('is_verified', true))
            ->orderBy('id');
    }

    public function rows(?int $userId = null, bool $verifiedOnly = true): Collection
    {
        return $this->query($userId, $verifiedOnly)
            ->get()
            ->map(function (TransactionCategoryLabel $label) {
                return [
                    'description' => $this->sanitizer->sanitizeText($label->sanitized_description, $label->user),
                    'label' => $label->category?->name ?? 'Uncategorized',
                    'type' => $label->transaction_type,
                    'amount' => (float) $label->amount,
                    'source' => $label->source,
                ];
            });
    }

    public function writeCsv(string $path, ?int $userId = null, bool $verifiedOnly = true): array
    {
        $directory = dirname($path);

        if (!File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        $rows = $this->rows($userId, $verifiedOnly);
        $skippedCount = $verifiedOnly
            ? TransactionCategoryLabel::query()
                ->when($userId, fn (Builder $query) => $query->where('user_id', $userId))
                ->where('is_verified', false)
                ->count()
            : 0;

        $handle = fopen($path, 'wb');
        fputcsv($handle, ['description', 'label', 'type', 'amount', 'source']);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['description'],
                $row['label'],
                $row['type'],
                $row['amount'],
                $row['source'],
            ]);
        }

        fclose($handle);

        return [
            'exported_count' => $rows->count(),
            'skipped_unverified_count' => $skippedCount,
            'path' => $path,
        ];
    }
}
