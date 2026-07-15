<?php

namespace App\Services\TransactionImport;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonInterface;

class TransactionImportDedupeService
{
    public function fingerprint(
        User $user,
        Wallet $wallet,
        string $sourceType,
        string $type,
        float $amount,
        float $fee,
        string $currency,
        CarbonInterface $happenedAt,
        ?string $normalizedMerchant,
        string $normalizedDescription,
        ?string $referenceCode,
        ?string $externalTransactionId,
    ): string {
        if ($externalTransactionId) {
            return hash('sha256', implode('|', [
                $user->id,
                $sourceType,
                mb_strtolower($externalTransactionId),
            ]));
        }

        return hash('sha256', implode('|', [
            $user->id,
            $wallet->id,
            $sourceType,
            $type,
            strtoupper($currency),
            number_format($amount, 2, '.', ''),
            number_format($fee, 2, '.', ''),
            $happenedAt->format('Y-m-d H:i:s'),
            $normalizedMerchant ?? '',
            $normalizedDescription,
            mb_strtolower((string) $referenceCode),
        ]));
    }

    public function existingTransaction(
        User $user,
        string $sourceType,
        string $fingerprint,
        ?string $externalTransactionId,
    ): ?Transaction {
        $query = Transaction::where('user_id', $user->id)
            ->where('source', $sourceType)
            ->where(function ($query) use ($fingerprint, $externalTransactionId) {
                $query->where('dedupe_fingerprint', $fingerprint);

                if ($externalTransactionId) {
                    $query->orWhere('external_transaction_id', $externalTransactionId);
                }
            });

        return $query->first();
    }
}
