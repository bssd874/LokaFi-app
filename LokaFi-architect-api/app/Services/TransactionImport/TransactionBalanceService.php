<?php

namespace App\Services\TransactionImport;

use App\Models\Transaction;
use App\Models\Wallet;

class TransactionBalanceService
{
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
