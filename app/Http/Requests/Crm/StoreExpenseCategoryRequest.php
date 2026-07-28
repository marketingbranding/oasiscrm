<?php

namespace App\Http\Requests\Crm;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ExpenseCategory::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => Str::of((string) $this->input('name'))->slug('_')->lower()->toString(),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/', Rule::unique('expense_categories', 'code')],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
