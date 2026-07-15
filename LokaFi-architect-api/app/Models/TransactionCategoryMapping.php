<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionCategoryMapping extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'transaction_type',
        'source',
        'normalized_merchant',
        'description_signature',
        'confidence',
        'confidence_score',
        'usage_count',
        'last_used_at',
    ];

    protected $casts = [
        'confidence_score' => 'integer',
        'usage_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
