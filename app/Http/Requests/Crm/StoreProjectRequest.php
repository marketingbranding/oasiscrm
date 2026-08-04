<?php

namespace App\Http\Requests\Crm;

use App\Models\Branch;
use App\Services\SalesLeadSheetOptionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->isSuperadmin();
    }

    public function rules(): array
    {
        return [
            'branch_id' => 'nullable|exists:branches,id',
            'project_name' => 'required|string|max:255',
            'sheet_project_name' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->filled('sheet_project_name') || $validator->errors()->has('branch_id')) {
                return;
            }
            $branch = Branch::find($this->integer('branch_id'));
            if (! $branch || app(SalesLeadSheetOptionService::class)->exactOption(
                app(SalesLeadSheetOptionService::class)->forBranch($branch)['project'],
                $this->string('sheet_project_name')->toString(),
            ) === null) {
                $validator->errors()->add('sheet_project_name', 'Identitas proyek tidak tersedia pada data_kav cabang terpilih.');
            }
        }];
    }
}
