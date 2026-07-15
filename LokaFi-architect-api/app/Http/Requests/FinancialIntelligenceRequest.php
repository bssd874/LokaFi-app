<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FinancialIntelligenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'wallet_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', 'in:manual,bank_csv,ewallet_csv,stellar,brankas,open_banking_simulator,open_banking_provider,portfolio_simulator'],
            'category_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user();

            if (!$user) {
                return;
            }

            if ($this->filled('wallet_id')) {
                $walletExists = Wallet::where('id', $this->integer('wallet_id'))
                    ->where('user_id', $user->id)
                    ->exists();

                if (!$walletExists) {
                    $validator->errors()->add('wallet_id', 'Wallet tidak valid atau bukan milik kamu.');
                }
            }

            if ($this->filled('category_id')) {
                $categoryExists = Category::where('id', $this->integer('category_id'))
                    ->where('user_id', $user->id)
                    ->exists();

                if (!$categoryExists) {
                    $validator->errors()->add('category_id', 'Kategori tidak valid atau bukan milik kamu.');
                }
            }

            $start = $this->input('start_date')
                ? CarbonImmutable::parse($this->input('start_date'))->startOfDay()
                : now()->toImmutable()->startOfMonth();
            $end = $this->input('end_date')
                ? CarbonImmutable::parse($this->input('end_date'))->endOfDay()
                : now()->toImmutable()->endOfMonth();

            $maxDays = (int) config('financial_intelligence.max_period_days', 366);
            $days = $start->diffInDays($end) + 1;

            if ($days > $maxDays) {
                $validator->errors()->add('end_date', "Rentang analitik maksimal {$maxDays} hari.");
            }
        });
    }

    public function analyticsFilters(): array
    {
        return [
            'start_date' => $this->input('start_date'),
            'end_date' => $this->input('end_date'),
            'wallet_id' => $this->filled('wallet_id') ? $this->integer('wallet_id') : null,
            'source' => $this->input('source'),
            'category_id' => $this->filled('category_id') ? $this->integer('category_id') : null,
            'page' => $this->integer('page', 1),
            'per_page' => $this->integer('per_page', 50),
        ];
    }
}
