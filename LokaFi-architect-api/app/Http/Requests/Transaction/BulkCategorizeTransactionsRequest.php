<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;

class BulkCategorizeTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_ids' => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['integer', 'distinct', 'exists:transactions,id'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
        ];
    }
}
