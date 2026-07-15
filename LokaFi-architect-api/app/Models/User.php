<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;


#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    use HasApiTokens;
    protected $fillable = [
        'name',
        'email',
        'password',
        'timezone',
        'base_currency',
    ];
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function wallets()
    {
        return $this->hasMany(Wallet::class);
    }

    public function stellarWallets()
    {
        return $this->hasMany(StellarWallet::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function stellarPayments()
    {
        return $this->hasManyThrough(StellarPayment::class, Invoice::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function transactionCategoryLabels()
    {
        return $this->hasMany(TransactionCategoryLabel::class);
    }

    public function transactionCategoryMappings()
    {
        return $this->hasMany(TransactionCategoryMapping::class);
    }

    public function categoryRules()
    {
        return $this->hasMany(CategoryRule::class);
    }

    public function aiCategorySuggestions()
    {
        return $this->hasMany(AiCategorySuggestion::class);
    }

    public function financialInsights()
    {
        return $this->hasMany(FinancialInsight::class);
    }

    public function transactionImportBatches()
    {
        return $this->hasMany(TransactionImportBatch::class);
    }

    public function budgets()
    {
        return $this->hasMany(Budget::class);
    }

    public function bankConnections()
    {
        return $this->hasMany(BankConnection::class);
    }

    public function watchlists()
    {
        return $this->hasMany(Watchlist::class);
    }

    public function investmentOrders()
    {
        return $this->hasMany(InvestmentOrder::class);
    }

    
}
