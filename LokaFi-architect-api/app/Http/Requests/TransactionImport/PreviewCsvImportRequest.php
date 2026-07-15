<?php

namespace App\Http\Requests\TransactionImport;

use App\Models\TransactionImportBatch;
use App\Models\Wallet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewCsvImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_type' => ['required', 'string', Rule::in(TransactionImportBatch::SOURCES)],
            'wallet_id' => ['required', 'integer', 'exists:wallets,id'],
            'provider_code' => ['nullable', 'string', 'max:50'],
            'file' => ['required', 'file', 'max:2048'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $walletId = $this->input('wallet_id');

            if (!$walletId) {
                return;
            }

            $wallet = Wallet::where('id', $walletId)
                ->where('user_id', $this->user()->id)
                ->first();

            if (!$wallet) {
                $validator->errors()->add('wallet_id', 'Wallet tidak valid atau bukan milik kamu.');
            }
        });
    }
}
