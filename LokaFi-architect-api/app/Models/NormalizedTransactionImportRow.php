<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizedTransactionImportRow extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_DUPLICATE = 'duplicate';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'transaction_import_batch_id',
        'transaction_id',
        'row_number',
        'raw_payload',
        'normalized_payload',
        'external_transaction_id',
        'dedupe_fingerprint',
        'status',
        'error_message',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'normalized_payload' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(TransactionImportBatch::class, 'transaction_import_batch_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
