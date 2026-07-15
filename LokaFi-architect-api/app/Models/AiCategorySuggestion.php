<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCategorySuggestion extends Model
{
    public const STATUS_VALID = 'valid';
    public const STATUS_CACHED = 'cached';
    public const STATUS_PROVIDER_ERROR = 'provider_error';
    public const STATUS_INVALID_RESPONSE = 'invalid_response';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'user_id',
        'transaction_id',
        'category_id',
        'provider',
        'model',
        'prompt_version',
        'input_hash',
        'sanitized_input_snapshot',
        'confidence',
        'needs_review',
        'validation_status',
        'error_code',
        'reason',
    ];

    protected $casts = [
        'sanitized_input_snapshot' => 'array',
        'confidence' => 'decimal:4',
        'needs_review' => 'boolean',
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
