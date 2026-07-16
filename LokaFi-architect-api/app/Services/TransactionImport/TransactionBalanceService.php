<?php

namespace App\Services\TransactionImport;

use App\Models\Transaction;
use App\Models\Wallet;

class TransactionBalanceService
{
    public function delta(Transaction $transaction): float
    {
        if ($transaction->type === 'income') {
            return (float) $transaction->amount;
        }

        if ($transaction->type === 'expense') {
            return -((float) $transaction->amount + (float) $transaction->fee);
        }

        return 0.0;
    }

    public function applyDelta(Wallet $wallet, float $delta): void
    {
        $delta = round($delta, 2);

        if ($delta > 0) {
            Wallet::whereKey($wallet->id)->increment('current_balance', $delta);
        } elseif ($delta < 0) {
            Wallet::whereKey($wallet->id)->decrement('current_balance', abs($delta));
        }
    }

    public function apply(Transaction $transaction): void
    {
        if ($transaction->type === 'income') {
            Wallet::whereKey($transaction->wallet_id)
                ->lockForUpdate()
                ->firstOrFail()
                ->increment('current_balance', $transaction->amount);
        }

        if ($transaction->type === 'expense') {
            Wallet::whereKey($transaction->wallet_id)
                ->lockForUpdate()
                ->firstOrFail()
                ->decrement('current_balance', $transaction->amount + $transaction->fee);
        }
    }
}
