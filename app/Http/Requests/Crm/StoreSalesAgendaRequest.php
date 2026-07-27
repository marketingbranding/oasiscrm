<?php

namespace App\Http\Requests\Crm;

use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\User;
use App\Services\WorkspaceAccessService;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSalesAgendaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->filled('branch_id') && $this->filled('project_id')) {
            $this->merge(['branch_id' => LeadMaster::whereKey($this->integer('project_id'))->value('branch_id')]);
        }
    }

    public function authorize(): bool
    {
        $user = $this->user();

        return $user->isSuperadmin() || $user->hasPrimaryRole(['sales', 'manager', 'admin', 'pusat']);
    }

    public function rules(): array
    {
        return [
            'owner_user_id' => ['required', 'integer', 'exists:users,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'project_id' => ['required', 'integer', 'exists:lead_master,id'],
            'scheduled_date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'sales_activity_category' => ['required', Rule::in(ContentItem::SALES_ACTIVITY_CATEGORIES)],
            'title' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['owner_user_id', 'branch_id', 'project_id', 'start_time', 'end_time'])) {
                return;
            }

            $actor = $this->user();
            $owner = User::with('role')->find($this->integer('owner_user_id'));
            $project = LeadMaster::find($this->integer('project_id'));
            $access = app(WorkspaceAccessService::class);

            if (Carbon::createFromFormat('H:i', $this->input('end_time'))->lte(Carbon::createFromFormat('H:i', $this->input('start_time')))) {
                $validator->errors()->add('end_time', 'Jam selesai harus setelah jam mulai.');
            }

            if (! $owner || ! $owner->is_active || ! $owner->isSales()) {
                $validator->errors()->add('owner_user_id', 'Pemilik agenda harus sales aktif.');

                return;
            }
            if ($actor->isSales() && ! $actor->is($owner)) {
                $validator->errors()->add('owner_user_id', 'Sales hanya dapat membuat agenda untuk dirinya sendiri.');
            }
            if (! $project || ! $project->is_active || (int) $project->branch_id !== $this->integer('branch_id') || ! $access->canViewBranch($owner, $project->branch_id)
                || ! $owner->assignedProjects()->whereKey($project?->id)->exists()) {
                $validator->errors()->add('project_id', 'Pilih proyek aktif yang ditugaskan kepada sales dan sesuai cabang.');
            } elseif (! $actor->isSales() && ! $actor->canViewAllBranches() && ! $access->canEditBranch($actor, $project->branch_id)) {
                $validator->errors()->add('project_id', 'Proyek berada di luar cabang yang dapat Anda kelola.');
            }
        }];
    }
}
