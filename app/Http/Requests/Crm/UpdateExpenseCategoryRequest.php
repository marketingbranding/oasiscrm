<?php

namespace App\Http\Requests\Crm;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->route('expenseCategory');

        return $category instanceof ExpenseCategory && $this->user()->can('update', $category);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
