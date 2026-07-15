<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransactionImportBatch extends Model
{
    public const SOURCE_BANK_CSV = 'bank_csv';
    public const SOURCE_EWALLET_CSV = 'ewallet_csv';

    public const SOURCES = [
        self::SOURCE_BANK_CSV,
        self::SOURCE_EWALLET_CSV,
    ];

    public const STATUS_PREVIEWED = 'previewed';
    public const STATUS_IMPORTED = 'imported';

    protected $fillable = [
        'user_id',
        'wallet_id',
        'source_type',
        'provider_code',
        'original_filename',
        'file_hash',
        'file_size_bytes',
        'detected_columns',
        'column_mapping',
        'status',
        'total_rows',
        'imported_count',
        'duplicate_count',
        'invalid_count',
        'failed_count',
        'processed_at',
    ];

    protected $casts = [
        'detected_columns' => 'array',
        'column_mapping' => 'array',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(NormalizedTransactionImportRow::class);
    }
}
