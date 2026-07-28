<?php

namespace App\Services;

use App\Models\LeadMaster;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectAssignmentService
{
    public function __construct(
        private WorkspaceAccessService $workspaceAccess,
        private AccountAuditService $audit,
    ) {}

    /**
     * @param  array<int, array<string, mixed>|int>  $assignments
     */
    public function assign(User $user, array $assignments, ?int $primaryProjectId = null, ?User $actor = null): User
    {
        $normalized = $this->normalize($assignments, $primaryProjectId);
        if ($primaryProjectId !== null && ! array_key_exists($primaryProjectId, $normalized)) {
            throw ValidationException::withMessages(['primary_project_id' => 'Proyek utama harus termasuk dalam proyek yang ditugaskan.']);
        }
        if ($primaryProjectId !== null && ! $normalized[$primaryProjectId]['is_active']) {
            throw ValidationException::withMessages(['primary_project_id' => 'Proyek utama harus merupakan penugasan aktif.']);
        }
        if ($user->isSales() && collect($normalized)->doesntContain(fn (array $assignment) => $assignment['is_active'])) {
            throw ValidationException::withMessages([
                'assigned_project_ids' => 'User Sales harus memiliki minimal satu penugasan proyek aktif.',
            ]);
        }

        $projects = LeadMaster::query()->whereIn('id', array_keys($normalized))->where('is_active', true)->get();
        if ($projects->count() !== count($normalized)) {
            throw ValidationException::withMessages(['assigned_project_ids' => 'Semua proyek yang ditugaskan harus aktif.']);
        }

        $branchIds = $this->workspaceAccess->accessibleBranchIds($user);
        if ($projects->contains(fn (LeadMaster $project) => ! in_array((int) $project->branch_id, $branchIds, true))) {
            throw ValidationException::withMessages([
                'assigned_project_ids' => 'Proyek harus berada pada cabang yang dapat diakses pengguna.',
            ]);
        }

        return DB::transaction(function () use ($user, $normalized, $actor) {
            $old = $this->snapshot($user);
            $user->assignedProjects()->sync($normalized);
            $user->refresh()->load('assignedProjects');
            $this->audit->log('project_assignments_changed', $user, $actor, $old, $this->snapshot($user));

            return $user;
        });
    }

    private function normalize(array $assignments, ?int $primaryProjectId): array
    {
        $normalized = [];
        foreach ($assignments as $key => $value) {
            $projectId = is_array($value) ? (int) ($value['project_id'] ?? $key) : (int) $value;
            $attributes = is_array($value) ? $value : [];
            $start = filled($attributes['assignment_start_date'] ?? null)
                ? Carbon::parse($attributes['assignment_start_date'])->toDateString()
                : null;
            $end = filled($attributes['assignment_end_date'] ?? null)
                ? Carbon::parse($attributes['assignment_end_date'])->toDateString()
                : null;
            if ($start && $end && $end < $start) {
                throw ValidationException::withMessages([
                    'assignment_end_date' => 'Tanggal akhir penugasan tidak boleh sebelum tanggal mulai.',
                ]);
            }

            $isActive = (bool) ($attributes['is_active'] ?? true);
            $normalized[$projectId] = [
                'is_primary' => $isActive && $projectId === $primaryProjectId,
                'assignment_start_date' => $start,
                'assignment_end_date' => $end,
                'is_active' => $isActive,
            ];
        }

        return array_filter($normalized, fn (array $value, int $key) => $key > 0, ARRAY_FILTER_USE_BOTH);
    }

    private function snapshot(User $user): array
    {
        $projects = $user->assignedProjects()->get();

        return [
            'projects' => $projects->mapWithKeys(fn (LeadMaster $project) => [
                $project->id => [
                    'is_primary' => (bool) $project->pivot->is_primary,
                    'assignment_start_date' => $project->pivot->assignment_start_date?->toDateString(),
                    'assignment_end_date' => $project->pivot->assignment_end_date?->toDateString(),
                    'is_active' => (bool) $project->pivot->is_active,
                ],
            ])->all(),
        ];
    }
}
