<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\SalesSheetIdentity;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SalesSheetIdentityService
{
    public function __construct(private readonly SalesLeadSheetOptionService $options) {}

    public function salesValue(Branch $branch, User $user, ?array $branchOptions = null): string
    {
        $values = $branchOptions ?? $this->options->forBranch($branch);
        $mapped = SalesSheetIdentity::query()->whereBelongsTo($branch)->whereBelongsTo($user)->value('spreadsheet_value');
        $resolved = $this->options->exactOption($values['sales'], $mapped ?: $user->name);
        if ($resolved === null) {
            throw ValidationException::withMessages(['sales_user_id' => 'Sales belum memiliki identitas yang cocok pada data_sales cabang terpilih.']);
        }

        return $resolved;
    }

    public function projectValue(LeadMaster $project, ?array $branchOptions = null): string
    {
        $branch = $project->branch()->firstOrFail();
        $values = $branchOptions ?? $this->options->forBranch($branch);
        $resolved = $this->options->exactOption($values['project'], $project->sheet_project_name ?: $project->project_name);
        if ($resolved === null) {
            throw ValidationException::withMessages(['project_id' => 'Proyek belum memiliki identitas yang cocok pada data_kav cabang terpilih.']);
        }

        return $resolved;
    }

    public function save(Branch $branch, User $user, string $value, User $actor): SalesSheetIdentity
    {
        $exact = $this->options->exactOption($this->options->forBranch($branch)['sales'], $value);
        if ($exact === null) {
            throw ValidationException::withMessages(['spreadsheet_value' => 'Sales PIC tidak tersedia pada data_sales cabang terpilih.']);
        }

        $conflict = SalesSheetIdentity::query()->where('branch_id', $branch->id)->where('spreadsheet_value', $exact)->where('user_id', '!=', $user->id)->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['spreadsheet_value' => 'Sales PIC tersebut sudah dipetakan ke pengguna lain pada cabang ini.']);
        }
        $identity = SalesSheetIdentity::query()->firstOrNew(['branch_id' => $branch->id, 'user_id' => $user->id]);
        $identity->fill(['spreadsheet_value' => $exact, 'updated_by' => $actor->id]);
        $identity->created_by ??= $actor->id;
        $identity->save();

        return $identity;
    }

    public function reverseSales(Branch $branch, LeadMaster $project, string $value): array
    {
        $mappedIds = SalesSheetIdentity::query()->where('branch_id', $branch->id)->get()
            ->filter(fn (SalesSheetIdentity $identity) => $this->options->exactOption([$identity->spreadsheet_value], $value) !== null)
            ->pluck('user_id');
        $today = today()->toDateString();
        $query = $project->assignedUsers()->where('users.is_active', true)->wherePivot('is_active', true)
            ->where(fn ($window) => $window->whereNull('project_user.assignment_start_date')->orWhereDate('project_user.assignment_start_date', '<=', $today))
            ->where(fn ($window) => $window->whereNull('project_user.assignment_end_date')->orWhereDate('project_user.assignment_end_date', '>=', $today));
        if ($mappedIds->isNotEmpty()) {
            $users = $query->whereIn('users.id', $mappedIds)->get();
        } else {
            $users = $query->get()->filter(fn (User $user) => $this->options->exactOption([$user->name], $value) !== null)->values();
        }

        return $users->count() === 1 ? [$users->first(), null] : [null, $users->isEmpty() ? 'sales_not_found' : 'sales_ambiguous'];
    }
}
