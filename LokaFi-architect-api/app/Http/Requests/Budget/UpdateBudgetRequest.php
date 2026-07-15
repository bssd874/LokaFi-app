<?php

namespace App\Http\Requests\Budget;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Category;


class UpdateBudgetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true ;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['sometimes', 'required', 'integer', 'exists:categories,id'],
            'month' => ['sometimes', 'required', 'date_format:Y-m'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->filled('category_id')) {
                return;
            }

            $category = Category::where('id', $this->input('category_id'))
                ->where('user_id', $this->user()->id)
                ->first();

            if (!$category) {
                $validator->errors()->add('category_id', 'Kategori tidak valid atau bukan milik kamu.');
                return;
            }

            if ($category->type !== 'expense') {
                $validator->errors()->add('category_id', 'Budget hanya bisa dibuat untuk kategori expense.');
            }
        });
    }
}
