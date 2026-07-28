<?php

namespace App\Http\Requests\Crm;

use App\Models\Expense;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Expense::class);
    }

    public function rules(): array
    {
        return $this->expenseRules() + [
            'submit_action' => ['required', Rule::in(['save', 'add_another'])],
        ];
    }

    protected function expenseRules(): array
    {
        $branchId = $this->input('branch_id');

        return [
            'expense_date' => ['required', 'date'],
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('is_active', true)],
            'project_id' => ['nullable', Rule::exists('lead_master', 'id')->where(fn ($query) => $query->where('is_active', true)->where('branch_id', $branchId))],
            'expense_category_id' => ['required', Rule::exists('expense_categories', 'id')->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'description' => ['required', 'string', 'max:255'],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', Rule::in(array_keys(Expense::PAYMENT_METHODS))],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
