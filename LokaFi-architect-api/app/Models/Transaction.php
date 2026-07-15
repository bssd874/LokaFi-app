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
        'invoice_id',
        'stellar_payment_id',
        'import_batch_id',
        'import_row_id',
        'from_wallet_id',
        'to_wallet_id',
        'category_id',
        'suggested_category_id',
        'amount',
        'fee',
        'currency',
        'merchant',
        'normalized_merchant',
        'description',
        'note',
        'reference_code',
        'happened_at',
        'external_transaction_id',
        'dedupe_fingerprint',
        'source',
        'raw_payload',
        'sanitized_description',
        'normalized_description',
        'categorization_status',
        'category_source',
        'categorization_confidence',
        'categorization_confidence_score',
        'categorization_explanation',
        'categorized_at',
        'imported_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'happened_at' => 'datetime',
        'categorized_at' => 'datetime',
        'imported_at' => 'datetime',
        'raw_payload' => 'array',
        'categorization_confidence_score' => 'integer',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function stellarPayment(): BelongsTo
    {
        return $this->belongsTo(StellarPayment::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(TransactionImportBatch::class, 'import_batch_id');
    }

    public function importRow(): BelongsTo
    {
        return $this->belongsTo(NormalizedTransactionImportRow::class, 'import_row_id');
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

    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'suggested_category_id');
    }

    public function categoryLabel()
    {
        return $this->hasOne(TransactionCategoryLabel::class);
    }

    public function aiCategorySuggestions()
    {
        return $this->hasMany(AiCategorySuggestion::class);
    }
}
