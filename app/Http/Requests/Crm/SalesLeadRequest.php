<?php

namespace App\Http\Requests\Crm;

use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\WorkspaceAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class SalesLeadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'project_id' => ['required', 'integer', 'exists:lead_master,id'],
            'sales_user_id' => ['required', 'integer', 'exists:users,id'],
            'lead_source_id' => ['required', 'integer', Rule::exists('lead_sources', 'id')->where(function ($query) {
                $query->where('is_active', true);
                if ($this->lead()?->lead_source_id) {
                    $query->orWhere('id', $this->lead()->lead_source_id);
                }
            })],
            'lead_date' => ['required', 'date'],
            'customer_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'linked_consumer_reference' => ['nullable', 'string', 'max:255'],
            'expected_updated_at' => ['sometimes', 'required', 'string', 'max:40'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['branch_id', 'project_id', 'sales_user_id'])) {
                return;
            }

            $user = $this->user();
            $access = app(WorkspaceAccessService::class);
            $branchId = (int) $this->input('branch_id');
            $project = LeadMaster::find($this->integer('project_id'));
            $owner = User::with('role')->find($this->integer('sales_user_id'));

            if (! $access->canViewBranch($user, $branchId)) {
                $validator->errors()->add('branch_id', 'Cabang tidak dapat diakses.');
            }
            if (! $project || ! $project->is_active || (int) $project->branch_id !== $branchId || ! $access->canAccessProject($user, $project)) {
                $validator->errors()->add('project_id', 'Proyek aktif harus berada di cabang yang dipilih dan dapat diakses.');
            }
            if (! $owner || ! $owner->is_active || ! $owner->hasRole('sales') || ! $access->canViewBranch($owner, $branchId)) {
                $validator->errors()->add('sales_user_id', 'Sales aktif harus berada di cabang yang dipilih.');
            } elseif (! $owner->assignedProjects()->whereKey($this->integer('project_id'))->exists()) {
                $validator->errors()->add('sales_user_id', 'Sales belum ditugaskan ke proyek yang dipilih.');
            }

            if ($user->hasRole('sales') && (int) $owner?->id !== (int) $user->id) {
                $validator->errors()->add('sales_user_id', 'Sales hanya dapat memilih dirinya sendiri.');
            }
        }];
    }

    protected function lead(): ?SalesLead
    {
        $lead = $this->route('sales_lead');

        return $lead instanceof SalesLead ? $lead : null;
    }
}
