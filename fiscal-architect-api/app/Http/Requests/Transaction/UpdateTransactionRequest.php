<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransactionRequest extends FormRequest
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
            'type' => ['sometimes', 'required', 'string', Rule::in(['income', 'expense', 'transfer'])],

            'wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
            'from_wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
            'to_wallet_id' => ['nullable', 'integer', 'exists:wallets,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],

            'amount' => ['sometimes', 'required', 'numeric', 'min:1'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],

            'merchant' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'note' => ['nullable', 'string'],
            'reference_code' => ['nullable', 'string', 'max:150'],

            'happened_at' => ['sometimes', 'required', 'date'],
        ];
    }
}
