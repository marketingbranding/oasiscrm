<?php

namespace App\Http\Requests\Crm;

use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends StoreExpenseRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('expense'));
    }

    public function rules(): array
    {
        $expense = $this->route('expense');
        $categoryRule = Rule::exists('expense_categories', 'id')->where(function ($query) use ($expense) {
            $query->whereNull('deleted_at')->where(function ($query) use ($expense) {
                $query->where('is_active', true)->orWhere('id', $expense->expense_category_id);
            });
        });

        return array_replace($this->expenseRules(), [
            'expense_category_id' => ['required', $categoryRule],
            'expected_updated_at' => ['required', 'string', 'max:40'],
        ]);
    }
}
