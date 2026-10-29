<?php

namespace App\Services;

use App\Models\LeadMaster;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SalesAgendaProjectResolver
{
    public function resolve(User $sales, mixed $date = null): ?LeadMaster
    {
        $date = $date ? now()->parse($date)->toDateString() : today()->toDateString();
        $projects = $sales->assignedProjects()
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

        $primaryProjects = $projects->filter(fn (LeadMaster $project) => (bool) $project->pivot->is_primary);

        if ($primaryProjects->count() === 1) {
            return $primaryProjects->first();
        }

        return $primaryProjects->isEmpty() && $projects->count() === 1 ? $projects->first() : null;
    }
}
