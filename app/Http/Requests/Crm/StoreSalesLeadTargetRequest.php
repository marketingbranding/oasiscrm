<?php

namespace App\Http\Requests\Crm;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalesLeadTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperadmin() === true;
    }

    public function rules(): array
    {
        return [
            'sales_user_id' => [
                'required',
                'integer',
                Rule::exists(User::class, 'id')
                    ->where('is_active', true)
                    ->whereIn('role_id', Role::query()->where('slug', 'sales')->pluck('id')->all()),
            ],
            'month' => ['required', 'date_format:Y-m'],
            'target_leads' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'sales_user_id.required' => 'Sales wajib dipilih.',
            'sales_user_id.exists' => 'Pengguna yang dipilih bukan Sales aktif yang valid.',
            'month.required' => 'Bulan target wajib dipilih.',
            'month.date_format' => 'Bulan target harus berformat YYYY-MM.',
            'target_leads.required' => 'Target Lead wajib diisi.',
            'target_leads.min' => 'Target Lead minimal 1.',
        ];
    }
}
