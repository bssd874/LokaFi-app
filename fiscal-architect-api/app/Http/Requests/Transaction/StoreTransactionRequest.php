<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(['income', 'expense', 'transfer'])],

            'wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
            'from_wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
            'to_wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],

            'amount' => ['required', 'numeric', 'min:1'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],

            'merchant' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            'reference_code' => ['nullable', 'string', 'max:150'],

            'happened_at' => ['required', 'date'],

        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $type = $this->input('type');

            if (in_array($type, ['income', 'expense'], true)) {
                if (!$this->filled('wallet_id')) {
                    $validator->errors()->add('wallet_id', 'Wallet wajib diisi untuk income atau expense.');
                }

                if (!$this->filled('category_id')) {
                    $validator->errors()->add('category_id', 'Kategori wajib diisi untuk income atau expense.');
                }
            }

            if ($type === 'transfer') {
                if (!$this->filled('from_wallet_id')) {
                    $validator->errors()->add('from_wallet_id', 'Wallet asal wajib diisi untuk transfer.');
                }

                if (!$this->filled('to_wallet_id')) {
                    $validator->errors()->add('to_wallet_id', 'Wallet tujuan wajib diisi untuk transfer.');
                }

                if ($this->input('from_wallet_id') === $this->input('to_wallet_id')) {
                    $validator->errors()->add('to_wallet_id', 'Wallet tujuan tidak boleh sama dengan wallet asal.');
                }
            }
        });
    }
}
