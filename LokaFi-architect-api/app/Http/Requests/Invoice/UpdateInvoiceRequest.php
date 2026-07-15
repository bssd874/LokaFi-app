<?php

namespace App\Http\Requests\Invoice;

use App\Services\InvoiceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('fiat_currency')) {
            $this->merge([
                'fiat_currency' => strtoupper((string) $this->input('fiat_currency')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'description' => ['sometimes', 'required', 'string', 'max:1000'],
            'fiat_currency' => ['sometimes', 'nullable', 'string', Rule::in([InvoiceService::FIAT_CURRENCY])],
            'fiat_amount' => ['sometimes', 'required', 'numeric', 'min:1000', 'max:999999999999.99'],
            'recipient_public_key' => ['sometimes', 'required', 'string', 'size:56', 'regex:/^G[A-Z2-7]{55}$/'],
            'expires_at' => ['sometimes', 'required', 'date', 'after:now'],
            'uuid' => ['prohibited'],
            'payment_memo' => ['prohibited'],
            'status' => ['prohibited'],
            'stellar_amount' => ['prohibited'],
            'demo_exchange_rate' => ['prohibited'],
            'paid_at' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'fiat_amount.min' => 'Nominal invoice minimal Rp1.000.',
            'recipient_public_key.regex' => 'Recipient public key Stellar tidak valid.',
            'recipient_public_key.size' => 'Recipient public key Stellar tidak valid.',
            'expires_at.after' => 'Expiration time harus di masa depan.',
            '*.prohibited' => 'Field invoice ini dikontrol oleh backend.',
        ];
    }
}
