<?php

namespace App\Services\TransactionImport;

use App\Models\NormalizedTransactionImportRow;
use App\Models\Transaction;
use App\Models\TransactionImportBatch;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TransactionCategorizationService;
use App\Services\TransactionSanitizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class TransactionImportService
{
    public function __construct(
        private readonly CsvStatementParser $parser,
        private readonly TransactionSanitizationService $sanitizer,
        private readonly TransactionImportNormalizationService $normalizer,
        private readonly TransactionImportDedupeService $dedupeService,
        private readonly TransactionBalanceService $balanceService,
        private readonly TransactionCategorizationService $categorizationService,
    ) {
    }

    public function preview(User $user, Wallet $wallet, UploadedFile $file, array $data): array
    {
        $parsed = $this->parser->parse($file);

        $existingBatch = TransactionImportBatch::with(['wallet', 'rows' => fn ($query) => $query->orderBy('row_number')])
            ->where('user_id', $user->id)
            ->where('source_type', $data['source_type'])
            ->where('file_hash', $parsed['file_hash'])
            ->first();

        if ($existingBatch) {
            return $this->formatResult($existingBatch, duplicateFile: true);
        }

        $batch = DB::transaction(function () use ($user, $wallet, $file, $data, $parsed) {
            $batch = TransactionImportBatch::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'source_type' => $data['source_type'],
                'provider_code' => $data['provider_code'] ?? null,
                'original_filename' => $file->getClientOriginalName(),
                'file_hash' => $parsed['file_hash'],
                'file_size_bytes' => $parsed['file_size_bytes'],
                'detected_columns' => $parsed['columns'],
                'column_mapping' => $this->normalizer->suggestMapping($parsed['columns']),
                'status' => TransactionImportBatch::STATUS_PREVIEWED,
                'total_rows' => count($parsed['rows']),
            ]);

            foreach ($parsed['rows'] as $row) {
                $batch->rows()->create([
                    'row_number' => $row['row_number'],
                    'raw_payload' => $this->sanitizer->sanitizePayload($row['payload'], $user),
                    'status' => NormalizedTransactionImportRow::STATUS_PENDING,
                ]);
            }

            return $batch;
        });

        return $this->formatResult($batch->fresh(['wallet', 'rows']), duplicateFile: false);
    }

    public function commit(User $user, TransactionImportBatch $batch, array $mapping): array
    {
        if ($batch->user_id !== $user->id) {
            abort(403, 'Batch import tidak valid atau bukan milik kamu.');
        }

        if ($batch->status === TransactionImportBatch::STATUS_IMPORTED) {
            return $this->formatResult($batch->fresh(['wallet', 'rows']), idempotent: true);
        }

        $cleanMapping = $this->cleanMapping($mapping);

        $batch = DB::transaction(function () use ($user, $batch, $cleanMapping) {
            $lockedBatch = TransactionImportBatch::whereKey($batch->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBatch->status === TransactionImportBatch::STATUS_IMPORTED) {
                return $lockedBatch;
            }

            $wallet = Wallet::where('id', $lockedBatch->wallet_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $counts = [
                'imported_count' => 0,
                'duplicate_count' => 0,
                'invalid_count' => 0,
                'failed_count' => 0,
            ];
            $seenFingerprints = [];

            $rows = $lockedBatch->rows()
                ->orderBy('row_number')
                ->get();

            foreach ($rows as $row) {
                try {
                    $normalized = $this->normalizer->normalize(
                        user: $user,
                        wallet: $wallet,
                        batch: $lockedBatch,
                        rawPayload: $row->raw_payload ?? [],
                        mapping: $cleanMapping,
                    );

                    $fingerprint = $normalized['fingerprint'];
                    $existingTransaction = $this->dedupeService->existingTransaction(
                        user: $user,
                        sourceType: $lockedBatch->source_type,
                        fingerprint: $fingerprint,
                        externalTransactionId: $normalized['external_transaction_id'],
                    );

                    if (isset($seenFingerprints[$fingerprint]) || $existingTransaction) {
                        $row->update([
                            'transaction_id' => $existingTransaction?->id,
                            'normalized_payload' => $normalized['normalized_payload'],
                            'external_transaction_id' => $normalized['external_transaction_id'],
                            'dedupe_fingerprint' => $fingerprint,
                            'status' => NormalizedTransactionImportRow::STATUS_DUPLICATE,
                            'error_message' => 'Duplikat transaksi terdeteksi.',
                        ]);

                        $counts['duplicate_count']++;
                        $seenFingerprints[$fingerprint] = true;
                        continue;
                    }

                    $attributes = $normalized['transaction'];
                    $attributes['import_row_id'] = $row->id;

                    $transaction = Transaction::create($attributes);
                    $this->balanceService->apply($transaction);
                    $transaction = $this->categorizationService->apply($transaction);

                    $row->update([
                        'transaction_id' => $transaction->id,
                        'normalized_payload' => $normalized['normalized_payload'],
                        'external_transaction_id' => $normalized['external_transaction_id'],
                        'dedupe_fingerprint' => $fingerprint,
                        'status' => NormalizedTransactionImportRow::STATUS_IMPORTED,
                        'error_message' => null,
                    ]);

                    $counts['imported_count']++;
                    $seenFingerprints[$fingerprint] = true;
                } catch (InvalidArgumentException $exception) {
                    $row->update([
                        'status' => NormalizedTransactionImportRow::STATUS_INVALID,
                        'error_message' => $exception->getMessage(),
                    ]);

                    $counts['invalid_count']++;
                } catch (\Throwable $exception) {
                    Log::warning('CSV transaction import row failed', [
                        'batch_id' => $lockedBatch->id,
                        'row_number' => $row->row_number,
                        'message' => $exception->getMessage(),
                    ]);

                    $row->update([
                        'status' => NormalizedTransactionImportRow::STATUS_FAILED,
                        'error_message' => 'Row gagal diproses.',
                    ]);

                    $counts['failed_count']++;
                }
            }

            $lockedBatch->update(array_merge($counts, [
                'column_mapping' => $cleanMapping,
                'status' => TransactionImportBatch::STATUS_IMPORTED,
                'processed_at' => now(),
            ]));

            return $lockedBatch;
        });

        return $this->formatResult($batch->fresh(['wallet', 'rows']), idempotent: false);
    }

    public function formatResult(
        TransactionImportBatch $batch,
        bool $duplicateFile = false,
        bool $idempotent = false,
    ): array {
        $batch = $batch->fresh([
            'wallet:id,user_id,name,type,currency,current_balance',
            'rows' => fn ($query) => $query->orderBy('row_number'),
        ]);

        $rows = $batch->rows->map(fn (NormalizedTransactionImportRow $row) => [
            'id' => $row->id,
            'row_number' => $row->row_number,
            'status' => $row->status,
            'error_message' => $row->error_message,
            'transaction_id' => $row->transaction_id,
            'external_transaction_id' => $row->external_transaction_id,
            'dedupe_fingerprint' => $row->dedupe_fingerprint,
            'raw_payload' => $row->raw_payload,
            'normalized_payload' => $row->normalized_payload,
        ])->values();

        return [
            'batch' => [
                'id' => $batch->id,
                'wallet_id' => $batch->wallet_id,
                'wallet' => $batch->wallet,
                'source_type' => $batch->source_type,
                'provider_code' => $batch->provider_code,
                'original_filename' => $batch->original_filename,
                'file_hash' => $batch->file_hash,
                'file_size_bytes' => $batch->file_size_bytes,
                'detected_columns' => $batch->detected_columns ?? [],
                'column_mapping' => $batch->column_mapping ?? [],
                'status' => $batch->status,
                'total_rows' => $batch->total_rows,
                'imported_count' => $batch->imported_count,
                'duplicate_count' => $batch->duplicate_count,
                'invalid_count' => $batch->invalid_count,
                'failed_count' => $batch->failed_count,
                'processed_at' => $batch->processed_at,
                'created_at' => $batch->created_at,
                'updated_at' => $batch->updated_at,
            ],
            'summary' => [
                'total_rows' => $batch->total_rows,
                'imported_count' => $batch->imported_count,
                'duplicate_count' => $batch->duplicate_count,
                'invalid_count' => $batch->invalid_count,
                'failed_count' => $batch->failed_count,
            ],
            'preview_rows' => $rows->take(10)->values(),
            'rows' => $rows,
            'duplicate_file' => $duplicateFile,
            'idempotent' => $idempotent,
        ];
    }

    private function cleanMapping(array $mapping): array
    {
        return collect($mapping)
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value) => trim($value))
            ->all();
    }
}
