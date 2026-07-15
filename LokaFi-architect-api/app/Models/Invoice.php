<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Invoice extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'uuid',
        'user_id',
        'customer_name',
        'customer_email',
        'description',
        'fiat_currency',
        'fiat_amount',
        'demo_exchange_rate',
        'stellar_asset_code',
        'stellar_amount',
        'recipient_public_key',
        'payment_memo',
        'status',
        'expires_at',
        'paid_at',
    ];

    protected $casts = [
        'fiat_amount' => 'decimal:2',
        'demo_exchange_rate' => 'decimal:8',
        'stellar_amount' => 'decimal:7',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stellarPayments(): HasMany
    {
        return $this->hasMany(StellarPayment::class);
    }

    public function latestStellarPayment(): HasOne
    {
        return $this->hasOne(StellarPayment::class)->latestOfMany();
    }

    public function financeTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
