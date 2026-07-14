<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'type',
        'wallet_id',
        'bank_connection_id',
        'from_wallet_id',
        'to_wallet_id',
        'category_id',
        'amount',
        'fee',
        'currency',
        'merchant',
        'description',
        'note',
        'reference_code',
        'happened_at',
        'external_transaction_id',
        'source',
        'raw_payload',
        'sanitized_description',
        'categorization_status',
        'category_source',
        'categorized_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'happened_at' => 'datetime',
        'categorized_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function bankConnection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class);
    }

    public function fromWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id');
    }

    public function toWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function categoryLabel()
    {
        return $this->hasOne(TransactionCategoryLabel::class);
    }
}
