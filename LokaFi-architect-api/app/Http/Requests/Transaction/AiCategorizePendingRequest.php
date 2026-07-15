<?php

namespace App\Http\Requests\Transaction;

use App\Services\Ai\AiCategorizationService;
use Illuminate\Foundation\Http\FormRequest;

class AiCategorizePendingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . AiCategorizationService::BATCH_LIMIT],
        ];
    }
}
