<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionCategoryLabel extends Model
{
    protected $fillable = [
        'user_id',
        'transaction_id',
        'category_id',
        'sanitized_description',
        'transaction_type',
        'amount',
        'source',
        'labeled_by',
        'is_verified',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_verified' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
