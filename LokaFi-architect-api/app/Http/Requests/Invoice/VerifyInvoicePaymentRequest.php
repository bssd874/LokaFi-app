<?php

namespace App\Http\Requests\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class VerifyInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('transaction_hash')) {
            $this->merge([
                'transaction_hash' => strtolower((string) $this->input('transaction_hash')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'transaction_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'success' => ['prohibited'],
            'successful' => ['prohibited'],
            'paid' => ['prohibited'],
            'status' => ['prohibited'],
            'signed_transaction' => ['prohibited'],
            'secret_key' => ['prohibited'],
            'mnemonic' => ['prohibited'],
            'recovery_phrase' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_hash.required' => 'Transaction hash wajib dikirim untuk verifikasi.',
            'transaction_hash.size' => 'Transaction hash Stellar harus 64 karakter hex.',
            'transaction_hash.regex' => 'Transaction hash Stellar harus berupa hex valid.',
            '*.prohibited' => 'Field ini tidak boleh dikirim. Backend memverifikasi status pembayaran langsung ke Stellar Testnet.',
        ];
    }
}
