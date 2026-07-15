<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankConnection extends Model
{
    protected $fillable = [
        'user_id',
        'provider_code',
        'provider_name',
        'account_holder_name',
        'account_number_masked',
        'status',
        'mode',
        'consent_session_id',
        'consent_state',
        'external_connection_id',
        'external_account_id',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'expires_at',
        'last_synced_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }
}
