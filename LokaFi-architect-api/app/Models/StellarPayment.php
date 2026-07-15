<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StellarPayment extends Model
{
    public const NETWORK_TESTNET = 'testnet';
    public const ASSET_CODE_XLM = 'XLM';
    public const STATUS_CONFIRMED = 'confirmed';

    protected $fillable = [
        'invoice_id',
        'transaction_id',
        'sender_public_key',
        'receiver_public_key',
        'asset_code',
        'amount',
        'transaction_hash',
        'ledger',
        'memo',
        'network',
        'status',
        'confirmed_at',
        'safe_raw_payload',
    ];

    protected $casts = [
        'amount' => 'decimal:7',
        'confirmed_at' => 'datetime',
        'safe_raw_payload' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
