<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'type',
        'currency',
        'opening_balance',
        'current_balance',
        'is_active',
        'bank_connection_id',
        'provider_code',
        'account_number_masked',
        'connection_status',
        'sync_source',
        'last_synced_at',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function outgoingTransfers()
    {
        return $this->hasMany(Transaction::class, 'from_wallet_id');
    }

    public function incomingTransfers()
    {
        return $this->hasMany(Transaction::class, 'to_wallet_id');
    }

    public function bankConnection()
    {
        return $this->belongsTo(BankConnection::class);
    }

    public function investmentOrders()
    {
        return $this->hasMany(InvestmentOrder::class);
    }
}
