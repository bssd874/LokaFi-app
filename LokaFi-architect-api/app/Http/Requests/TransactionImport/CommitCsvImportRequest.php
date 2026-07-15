<?php

namespace App\Http\Requests\TransactionImport;

use App\Models\TransactionImportBatch;
use Illuminate\Foundation\Http\FormRequest;

class CommitCsvImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'integer', 'exists:transaction_import_batches,id'],
            'mapping' => ['required', 'array'],
            'mapping.happened_at' => ['required', 'string'],
            'mapping.amount' => ['nullable', 'string'],
            'mapping.debit_amount' => ['nullable', 'string'],
            'mapping.credit_amount' => ['nullable', 'string'],
            'mapping.type' => ['nullable', 'string'],
            'mapping.description' => ['nullable', 'string'],
            'mapping.merchant' => ['nullable', 'string'],
            'mapping.reference_code' => ['nullable', 'string'],
            'mapping.external_transaction_id' => ['nullable', 'string'],
            'mapping.fee' => ['nullable', 'string'],
            'mapping.currency' => ['nullable', 'string'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $batch = TransactionImportBatch::where('id', $this->input('batch_id'))
                ->where('user_id', $this->user()->id)
                ->first();

            if (!$batch) {
                $validator->errors()->add('batch_id', 'Batch import tidak valid atau bukan milik kamu.');
                return;
            }

            $mapping = collect($this->input('mapping', []))
                ->filter(fn ($value) => is_string($value) && trim($value) !== '');
            $columns = collect($batch->detected_columns ?? []);

            foreach ($mapping as $field => $column) {
                if (!$columns->contains($column)) {
                    $validator->errors()->add("mapping.{$field}", "Kolom {$column} tidak ditemukan di file CSV.");
                }
            }

            $hasAmount = $mapping->has('amount');
            $hasDebitCredit = $mapping->has('debit_amount') || $mapping->has('credit_amount');

            if (!$hasAmount && !$hasDebitCredit) {
                $validator->errors()->add('mapping.amount', 'Mapping amount atau debit/credit wajib diisi.');
            }
        });
    }
}
