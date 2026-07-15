<?php

namespace App\Services\TransactionImport;

use App\Models\TransactionImportBatch;
use App\Models\User;
use App\Models\Wallet;
use App\Services\TransactionSanitizationService;
use App\Services\TransactionTextNormalizationService;
use Carbon\Carbon;
use InvalidArgumentException;

class TransactionImportNormalizationService
{
    public function __construct(
        private readonly TransactionSanitizationService $sanitizer,
        private readonly TransactionImportDedupeService $dedupeService,
        private readonly TransactionTextNormalizationService $textNormalizer,
    ) {
    }

    public function suggestMapping(array $columns): array
    {
        $aliases = [
            'happened_at' => ['date', 'tanggal', 'transaction date', 'waktu', 'time', 'posted at', 'booking date'],
            'amount' => ['amount', 'nominal', 'jumlah', 'nilai', 'total', 'transaction amount'],
            'debit_amount' => ['debit', 'debet', 'withdrawal', 'keluar', 'paid out'],
            'credit_amount' => ['credit', 'kredit', 'deposit', 'masuk', 'paid in'],
            'type' => ['type', 'jenis', 'direction', 'mutasi', 'transaction type'],
            'description' => ['description', 'deskripsi', 'keterangan', 'remark', 'remarks', 'details', 'memo'],
            'merchant' => ['merchant', 'counterparty', 'beneficiary', 'payee', 'merchant name', 'nama merchant'],
            'reference_code' => ['reference', 'ref', 'reference code', 'nomor referensi', 'no referensi', 'memo'],
            'external_transaction_id' => ['id', 'transaction id', 'trx id', 'external id', 'reference no', 'no transaksi'],
            'fee' => ['fee', 'biaya', 'admin fee', 'charge'],
            'currency' => ['currency', 'mata uang', 'ccy'],
        ];

        $normalizedColumns = collect($columns)
            ->mapWithKeys(fn (string $column) => [$column => $this->normalizeColumnName($column)]);
        $mapping = [];

        foreach ($aliases as $field => $fieldAliases) {
            foreach ($normalizedColumns as $column => $normalizedColumn) {
                foreach ($fieldAliases as $alias) {
                    if ($normalizedColumn === $this->normalizeColumnName($alias)) {
                        $mapping[$field] = $column;
                        continue 3;
                    }
                }
            }

            foreach ($normalizedColumns as $column => $normalizedColumn) {
                foreach ($fieldAliases as $alias) {
                    if (str_contains($normalizedColumn, $this->normalizeColumnName($alias))) {
                        $mapping[$field] = $column;
                        continue 3;
                    }
                }
            }
        }

        return $mapping;
    }

    public function normalize(
        User $user,
        Wallet $wallet,
        TransactionImportBatch $batch,
        array $rawPayload,
        array $mapping,
    ): array {
        $happenedAt = $this->parseDate($this->mappedValue($rawPayload, $mapping, 'happened_at'));
        [$type, $amount] = $this->resolveTypeAndAmount($rawPayload, $mapping);
        $fee = $this->parseMoney($this->mappedValue($rawPayload, $mapping, 'fee')) ?? 0;

        if ($fee < 0) {
            throw new InvalidArgumentException('Biaya transaksi tidak valid.');
        }

        $currency = strtoupper(
            $this->mappedValue($rawPayload, $mapping, 'currency')
            ?: $wallet->currency
            ?: 'IDR',
        );
        $currency = mb_substr(preg_replace('/[^A-Z0-9]/', '', $currency) ?: 'IDR', 0, 10);

        $description = $this->mappedValue($rawPayload, $mapping, 'description')
            ?: $this->mappedValue($rawPayload, $mapping, 'merchant')
            ?: 'Imported statement transaction';

        $merchant = $this->sanitizer->sanitizeMerchant(
            $this->mappedValue($rawPayload, $mapping, 'merchant'),
            $user,
        );
        $sanitizedDescription = $this->sanitizer->sanitizeText($description, $user);
        $normalizedMerchant = $this->textNormalizer->canonicalMerchant($merchant, $sanitizedDescription);
        $normalizedDescription = $this->textNormalizer->descriptionSignature($sanitizedDescription, $merchant)
            ?: 'imported statement transaction';
        $referenceCode = $this->nullableSanitizedText(
            $this->mappedValue($rawPayload, $mapping, 'reference_code'),
            $user,
            150,
        );
        $externalTransactionId = $this->nullableSanitizedText(
            $this->mappedValue($rawPayload, $mapping, 'external_transaction_id'),
            $user,
            150,
        );

        $fingerprint = $this->dedupeService->fingerprint(
            user: $user,
            wallet: $wallet,
            sourceType: $batch->source_type,
            type: $type,
            amount: $amount,
            fee: $fee,
            currency: $currency,
            happenedAt: $happenedAt,
            normalizedMerchant: $normalizedMerchant,
            normalizedDescription: $normalizedDescription,
            referenceCode: $referenceCode,
            externalTransactionId: $externalTransactionId,
        );

        $normalizedPayload = [
            'type' => $type,
            'amount' => round($amount, 2),
            'fee' => round($fee, 2),
            'currency' => $currency,
            'merchant' => $merchant,
            'normalized_merchant' => $normalizedMerchant,
            'description' => $description,
            'sanitized_description' => $sanitizedDescription,
            'normalized_description' => $normalizedDescription,
            'reference_code' => $referenceCode,
            'external_transaction_id' => $externalTransactionId,
            'happened_at' => $happenedAt->format('Y-m-d H:i:s'),
            'dedupe_fingerprint' => $fingerprint,
        ];

        return [
            'transaction' => [
                'user_id' => $user->id,
                'type' => $type,
                'wallet_id' => $wallet->id,
                'category_id' => null,
                'amount' => round($amount, 2),
                'fee' => round($fee, 2),
                'currency' => $currency,
                'merchant' => $merchant,
                'normalized_merchant' => $normalizedMerchant,
                'description' => $description,
                'note' => $description,
                'reference_code' => $referenceCode,
                'happened_at' => $happenedAt->format('Y-m-d H:i:s'),
                'external_transaction_id' => $externalTransactionId,
                'dedupe_fingerprint' => $fingerprint,
                'source' => $batch->source_type,
                'raw_payload' => $this->sanitizer->sanitizePayload($rawPayload, $user),
                'sanitized_description' => $sanitizedDescription,
                'normalized_description' => $normalizedDescription,
                'categorization_status' => 'unclassified',
                'category_source' => 'unclassified',
                'categorized_at' => null,
                'import_batch_id' => $batch->id,
                'imported_at' => now(),
            ],
            'normalized_payload' => $normalizedPayload,
            'fingerprint' => $fingerprint,
            'external_transaction_id' => $externalTransactionId,
        ];
    }

    private function mappedValue(array $row, array $mapping, string $field): ?string
    {
        $column = $mapping[$field] ?? null;

        if (!$column || !array_key_exists($column, $row)) {
            return null;
        }

        $value = trim((string) $row[$column]);

        return $value === '' ? null : $value;
    }

    private function parseDate(?string $value): Carbon
    {
        if (!$value) {
            throw new InvalidArgumentException('Tanggal transaksi wajib diisi.');
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Tanggal transaksi tidak valid.');
        }
    }

    private function resolveTypeAndAmount(array $row, array $mapping): array
    {
        $debit = $this->parseMoney($this->mappedValue($row, $mapping, 'debit_amount'));
        $credit = $this->parseMoney($this->mappedValue($row, $mapping, 'credit_amount'));

        if (($debit ?? 0) > 0 || ($credit ?? 0) > 0) {
            if (($debit ?? 0) > 0 && ($credit ?? 0) > 0) {
                throw new InvalidArgumentException('Row memiliki debit dan credit sekaligus.');
            }

            return (($credit ?? 0) > 0)
                ? ['income', (float) $credit]
                : ['expense', (float) $debit];
        }

        $amount = $this->parseMoney($this->mappedValue($row, $mapping, 'amount'));

        if ($amount === null || $amount == 0.0) {
            throw new InvalidArgumentException('Nominal transaksi wajib lebih dari 0.');
        }

        $type = $this->normalizeTransactionType($this->mappedValue($row, $mapping, 'type'));

        if (!$type) {
            $type = $amount < 0 ? 'expense' : 'income';
        }

        return [$type, abs($amount)];
    }

    private function normalizeTransactionType(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $type = $this->textNormalizer->basicNormalize($value);

        if (in_array($type, ['income', 'credit', 'kredit', 'cr', 'masuk', 'pemasukan', 'deposit'], true)) {
            return 'income';
        }

        if (in_array($type, ['expense', 'debit', 'debet', 'db', 'keluar', 'pengeluaran', 'withdrawal'], true)) {
            return 'expense';
        }

        throw new InvalidArgumentException('Tipe transaksi tidak valid.');
    }

    private function parseMoney(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $text = trim($value);
        $negative = str_contains($text, '(') && str_contains($text, ')');
        $clean = preg_replace('/[^\d,.\-]/', '', $text) ?? '';

        if (str_starts_with($clean, '-')) {
            $negative = true;
        }

        $clean = str_replace('-', '', $clean);

        if ($clean === '') {
            throw new InvalidArgumentException('Nominal transaksi tidak valid.');
        }

        $lastComma = strrpos($clean, ',');
        $lastDot = strrpos($clean, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $clean = str_replace('.', '', $clean);
                $clean = str_replace(',', '.', $clean);
            } else {
                $clean = str_replace(',', '', $clean);
            }
        } elseif ($lastComma !== false) {
            $clean = preg_match('/,\d{1,2}$/', $clean)
                ? str_replace(',', '.', $clean)
                : str_replace(',', '', $clean);
        } elseif ($lastDot !== false) {
            if (substr_count($clean, '.') > 1 || preg_match('/\.\d{3}$/', $clean)) {
                $clean = str_replace('.', '', $clean);
            }
        }

        if (!is_numeric($clean)) {
            throw new InvalidArgumentException('Nominal transaksi tidak valid.');
        }

        $amount = (float) $clean;

        return $negative ? -1 * $amount : $amount;
    }

    private function nullableSanitizedText(?string $value, User $user, int $maxLength): ?string
    {
        $sanitized = $this->sanitizer->sanitizeText($value, $user);

        return $sanitized === '' ? null : mb_substr($sanitized, 0, $maxLength);
    }

    private function normalizeColumnName(string $value): string
    {
        return (string) $this->textNormalizer->basicNormalize($value);
    }
}
