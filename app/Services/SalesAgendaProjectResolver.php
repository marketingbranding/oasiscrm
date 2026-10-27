<?php

namespace App\Services;

use App\Models\LeadMaster;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SalesAgendaProjectResolver
{
    public function resolve(User $sales, mixed $date = null): ?LeadMaster
    {
        $projects = $this->activeProjects($sales, $date);
        $primaryProjects = $projects->filter(fn (LeadMaster $project) => (bool) $project->pivot->is_primary);

        if ($primaryProjects->count() === 1) {
            return $primaryProjects->first();
        }

        return $primaryProjects->isEmpty() && $projects->count() === 1 ? $projects->first() : null;
    }

    public function resolveAssigned(User $sales, int $projectId, mixed $date = null): ?LeadMaster
    {
        return $this->activeProjects($sales, $date)->firstWhere('id', $projectId);
    }

    private function activeProjects(User $sales, mixed $date = null): Collection
    {
        $date = $date ? now()->parse($date)->toDateString() : today()->toDateString();

        return $sales->assignedProjects()
            ->where('lead_master.is_active', true)
            ->wherePivot('is_active', true)
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('project_user.assignment_start_date')
                    ->orWhereDate('project_user.assignment_start_date', '<=', $date);
            })
            ->where(function (Builder $query) use ($date) {
                $query->whereNull('project_user.assignment_end_date')
                    ->orWhereDate('project_user.assignment_end_date', '>=', $date);
            })
            ->with('branch')
            ->get();
    }
}
