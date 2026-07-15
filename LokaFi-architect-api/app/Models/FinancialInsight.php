<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialInsight extends Model
{
    public const STATUS_VALID = 'valid';
    public const STATUS_CACHED = 'cached';
    public const STATUS_PROVIDER_ERROR = 'provider_error';
    public const STATUS_INVALID_RESPONSE = 'invalid_response';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'user_id',
        'period_start',
        'period_end',
        'analytics_version',
        'input_hash',
        'provider',
        'model',
        'prompt_version',
        'structured_insight',
        'validation_status',
        'generated_at',
        'expires_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'structured_insight' => 'array',
        'generated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
