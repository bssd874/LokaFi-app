<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;

class ReprocessTransactionCategorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_ids' => ['required', 'array', 'min:1', 'max:100'],
            'transaction_ids.*' => ['integer', 'distinct', 'exists:transactions,id'],
        ];
    }
}
