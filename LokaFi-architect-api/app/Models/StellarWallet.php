<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StellarWallet extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'public_key',
        'network',
        'wallet_provider',
        'connected_at',
    ];

    protected $casts = [
        'connected_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
