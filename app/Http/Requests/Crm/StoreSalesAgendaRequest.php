<?php

namespace App\Http\Requests\Crm;

use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\User;
use App\Services\WorkspaceAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSalesAgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user->isSuperadmin() || $user->hasRole(['sales', 'manager', 'admin', 'pusat']);
    }

    public function rules(): array
    {
        return [
            'owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'project_id' => ['required', 'integer', 'exists:lead_master,id'],
            'scheduled_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['nullable', 'required_without:duration_minutes', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'required_without:end_time', 'integer', 'min:1', 'max:1440'],
            'sales_activity_category' => ['required', Rule::in(ContentItem::SALES_ACTIVITY_CATEGORIES)],
            'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['owner_user_id', 'project_id'])) {
                return;
            }

            $actor = $this->user();
            $owner = User::with('role')->find($this->integer('owner_user_id'));
            $project = LeadMaster::find($this->integer('project_id'));
            $access = app(WorkspaceAccessService::class);

            if (! $owner || ! $owner->is_active || ! $owner->hasRole('sales')) {
                $validator->errors()->add('owner_user_id', 'Pemilik agenda harus sales aktif.');

                return;
            }
            if ($actor->hasRole('sales') && ! $actor->is($owner)) {
                $validator->errors()->add('owner_user_id', 'Sales hanya dapat membuat agenda untuk dirinya sendiri.');
            }
            if (! $project || ! $project->is_active || ! $access->canViewBranch($owner, $project->branch_id)
                || ! $owner->assignedProjects()->whereKey($project?->id)->exists()) {
                $validator->errors()->add('project_id', 'Pilih proyek aktif yang ditugaskan kepada sales dan sesuai cabang.');
            } elseif (! $actor->hasRole('sales') && ! $actor->canViewAllBranches() && ! $access->canEditBranch($actor, $project->branch_id)) {
                $validator->errors()->add('project_id', 'Proyek berada di luar cabang yang dapat Anda kelola.');
            }
        }];
    }
}
