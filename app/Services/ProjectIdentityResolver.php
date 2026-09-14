<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\LeadMaster;
use Illuminate\Validation\ValidationException;

final class ProjectIdentityResolver
{
    public function resolveExact(Branch|int $branch, string $identity, bool $activeOnly = true): ?LeadMaster
    {
        $projects = $this->matches($branch, $identity, $activeOnly);
        if ($projects->count() > 1) {
            throw ValidationException::withMessages(['project_identity' => 'Identitas proyek cocok dengan lebih dari satu proyek pada cabang.']);
        }

        return $projects->first();
    }

    public function resolveExactOrNull(Branch|int $branch, string $identity, bool $activeOnly = true): ?LeadMaster
    {
        try {
            return $this->resolveExact($branch, $identity, $activeOnly);
        } catch (ValidationException) {
            return null;
        }
    }

    public function resolveExactWithIssue(Branch|int $branch, string $identity, bool $activeOnly = true): array
    {
        try {
            $project = $this->resolveExact($branch, $identity, $activeOnly);
        } catch (ValidationException) {
            return [null, 'project_ambiguous'];
        }

        return $project === null ? [null, 'project_not_found'] : [$project, null];
    }

    public function resolveExactOrFail(Branch|int $branch, string $identity, bool $activeOnly = true): LeadMaster
    {
        $project = $this->resolveExact($branch, $identity, $activeOnly);
        if ($project === null) {
            throw ValidationException::withMessages(['project_identity' => 'Identitas proyek tidak ditemukan pada cabang.']);
        }

        return $project;
    }

    public function resolveExactAcrossBranches(string $identity, bool $activeOnly = true): ?LeadMaster
    {
        $projects = $this->matches(null, $identity, $activeOnly);
        if ($projects->count() > 1) {
            throw ValidationException::withMessages(['project_identity' => 'Identitas proyek cocok dengan lebih dari satu proyek aktif.']);
        }

        return $projects->first();
    }

    public function resolveExactAcrossBranchesWithIssue(string $identity, bool $activeOnly = true): array
    {
        try {
            $project = $this->resolveExactAcrossBranches($identity, $activeOnly);
        } catch (ValidationException) {
            return [null, 'project_ambiguous'];
        }

        return $project === null ? [null, 'project_not_found'] : [$project, null];
    }

    public function labels(LeadMaster $project): array
    {
        $labels = array_filter([$project->project_name, $project->sheet_project_name], fn ($label) => $this->normalize((string) $label) !== '');
        $seen = [];

        return array_values(array_filter($labels, function ($label) use (&$seen): bool {
            $normalized = $this->normalize((string) $label);
            if (isset($seen[$normalized])) {
                return false;
            }
            $seen[$normalized] = true;

            return true;
        }));
    }

    private function matches(Branch|int|null $branch, string $identity, bool $activeOnly)
    {
        $normalized = $this->normalize($identity);
        if ($normalized === '') {
            return collect();
        }

        return LeadMaster::query()
            ->when($branch !== null, fn ($query) => $query->where('branch_id', $branch instanceof Branch ? $branch->id : $branch))
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->get()
            ->filter(fn (LeadMaster $project) => collect($this->labels($project))->contains(fn (string $label) => $this->normalize($label) === $normalized))
            ->values();
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
    }
}
