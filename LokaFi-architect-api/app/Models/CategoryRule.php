<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CategoryRule extends Model
{
    public const MATCH_KEYWORD = 'keyword';
    public const MATCH_NORMALIZED_MERCHANT = 'normalized_merchant';

    protected $fillable = [
        'user_id',
        'category_id',
        'name',
        'match_type',
        'pattern',
        'transaction_type',
        'source',
        'default_category_name',
        'default_category_type',
        'priority',
        'confidence',
        'confidence_score',
        'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'confidence_score' => 'integer',
        'is_active' => 'boolean',
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
