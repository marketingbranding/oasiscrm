<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SalesOnboardingProposalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPrimaryRole('admin') === true
            && $this->user()->hasPermission('users.propose_sales');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'phone' => ['nullable', 'string', 'max:20'],
            'role_id' => ['required', Rule::exists(Role::class, 'id')->where('slug', 'sales')->where('is_active', true)],
            'branch_id' => ['required', Rule::exists(Branch::class, 'id')->where('is_active', true)],
            'project_id' => ['required', Rule::exists(LeadMaster::class, 'id')->where('is_active', true)],
            'coordinator_user_id' => ['required', Rule::exists(User::class, 'id')],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->email))]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['role_id', 'branch_id', 'project_id', 'coordinator_user_id'])) {
                return;
            }

            $coordinator = User::query()->find((int) $this->input('coordinator_user_id'));
            if ($coordinator?->hasPrimaryRole('sales_coordinator') !== true) {
                $validator->errors()->add('coordinator_user_id', 'Koordinator harus memiliki primary role Koordinator Sales.');
            }
        }];
    }
}
