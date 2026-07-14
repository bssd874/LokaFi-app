<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    protected $fillable = [
        'symbol',
        'name',
        'asset_type',
        'currency',
        'exchange',
        'current_price',
        'price_change_percentage',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'current_price' => 'decimal:8',
        'price_change_percentage' => 'decimal:4',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function investmentOrders(): HasMany
    {
        return $this->hasMany(InvestmentOrder::class);
    }

}
