<?php

namespace App\Http\Requests\Stellar;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStellarWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'network' => strtolower((string) ($this->input('network') ?? 'testnet')),
            'wallet_provider' => strtolower((string) ($this->input('wallet_provider') ?? 'freighter')),
        ]);
    }

    public function rules(): array
    {
        return [
            'public_key' => ['required', 'string', 'size:56', 'regex:/^G[A-Z2-7]{55}$/'],
            'network' => ['required', 'string', Rule::in(['testnet'])],
            'wallet_provider' => ['required', 'string', Rule::in(['freighter'])],
            'secret_key' => ['prohibited'],
            'private_key' => ['prohibited'],
            'mnemonic' => ['prohibited'],
            'recovery_phrase' => ['prohibited'],
            'signed_transaction' => ['prohibited'],
            'signed_transaction_payload' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'public_key.regex' => 'Public key Stellar tidak valid.',
            'network.in' => 'Stellar hanya mendukung Testnet untuk demo ini.',
            'wallet_provider.in' => 'Wallet provider harus Freighter.',
            '*.prohibited' => 'Data rahasia wallet tidak boleh dikirim atau disimpan.',
        ];
    }
}
