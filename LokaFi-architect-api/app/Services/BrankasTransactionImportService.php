<?php

namespace App\Services;

use App\Models\BankConnection;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\Log;

class BrankasTransactionImportService
{
    public function __construct(private readonly TransactionSanitizationService $sanitizer)
    {
    }

    public function import(BankConnection $connection, Wallet $wallet, array $transactions): array
    {
        $importedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($transactions as $item) {
            try {
                $normalized = $this->normalizeForStorage($connection, $wallet, $item);

                $alreadyExists = Transaction::where('user_id', $connection->user_id)
                    ->where('bank_connection_id', $connection->id)
                    ->where('external_transaction_id', $normalized['external_transaction_id'])
                    ->exists();

                if ($alreadyExists) {
                    $skippedCount++;
                    continue;
                }

                Transaction::create($normalized);
                $importedCount++;
            } catch (\Throwable $exception) {
                $errorCount++;

                Log::warning('Brankas transaction import skipped invalid item', [
                    'bank_connection_id' => $connection->id,
                    'external_transaction_id' => $item['external_transaction_id'] ?? null,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'imported_count' => $importedCount,
            'skipped_count' => $skippedCount,
            'error_count' => $errorCount,
        ];
    }

    private function normalizeForStorage(BankConnection $connection, Wallet $wallet, array $item): array
    {
        $type = $item['type'] ?? null;
        if (!in_array($type, ['income', 'expense'], true)) {
            throw new \InvalidArgumentException('Tipe transaksi Brankas tidak valid.');
        }

        $amount = (float) ($item['amount'] ?? 0);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Nominal transaksi Brankas tidak valid.');
        }

        if (empty($item['happened_at'])) {
            throw new \InvalidArgumentException('Tanggal transaksi Brankas tidak valid.');
        }

        $description = $this->sanitizer->transactionDescription($item);
        $sanitizedDescription = $this->sanitizer->sanitizeText($description, $connection->user);
        $merchant = $this->sanitizer->sanitizeMerchant($item['merchant'] ?? null, $connection->user);
        $externalTransactionId = $item['external_transaction_id'] ?? null;

        if (!$externalTransactionId) {
            $externalTransactionId = $this->fingerprint(
                bankConnectionId: $connection->id,
                amount: $amount,
                happenedAt: (string) $item['happened_at'],
                sanitizedDescription: $sanitizedDescription,
            );
        }

        return [
            'user_id' => $connection->user_id,
            'wallet_id' => $wallet->id,
            'bank_connection_id' => $connection->id,
            'category_id' => null,
            'type' => $type,
            'amount' => $amount,
            'fee' => (float) ($item['fee'] ?? 0),
            'currency' => $item['currency'] ?? $wallet->currency ?? 'IDR',
            'merchant' => $merchant,
            'description' => $description,
            'note' => $description,
            'reference_code' => $item['reference_code'] ?? null,
            'happened_at' => $item['happened_at'],
            'external_transaction_id' => (string) $externalTransactionId,
            'source' => 'brankas',
            'raw_payload' => $this->sanitizer->sanitizePayload($item['raw_payload'] ?? $item, $connection->user),
            'sanitized_description' => $sanitizedDescription,
            'categorization_status' => 'unclassified',
            'category_source' => 'unclassified',
            'categorized_at' => null,
        ];
    }

    private function fingerprint(
        int $bankConnectionId,
        float $amount,
        string $happenedAt,
        string $sanitizedDescription,
    ): string {
        return hash('sha256', implode('|', [
            $bankConnectionId,
            number_format($amount, 2, '.', ''),
            $happenedAt,
            mb_strtolower($sanitizedDescription),
        ]));
    }
}
