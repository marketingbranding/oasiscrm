<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\LeadMaster;
use Illuminate\Support\Str;

final class SheetProjectOwnershipService
{
    /** @return array{rows: array<int, array>, row_indexes: array<int, int>, foreign_project_rows: int, unmapped_project_rows: int} */
    public function filterRows(Branch $branch, array $rows): array
    {
        $labels = $this->labelsForBranch($branch);
        $owned = $labels['owned'];
        $all = $labels['all'];
        $kept = [];
        $rowIndexes = [];
        $foreign = 0;
        $unmapped = 0;

        foreach ($rows as $index => $row) {
            $label = $this->projectLabel($row);
            if ($label === '') {
                $rowIndexes[] = (int) $index;
                $kept[] = $row;

                continue;
            }

            $normalized = $this->normalize($label);
            if (count($owned[$normalized] ?? []) >= 1 && count($all[$normalized] ?? []) === 1) {
                $rowIndexes[] = (int) $index;
                $kept[] = $row;
            } elseif (isset($all[$normalized])) {
                $foreign++;
            } else {
                $unmapped++;
                $kept[] = $row;
            }
        }

        return ['rows' => $kept, 'row_indexes' => $rowIndexes, 'foreign_project_rows' => $foreign, 'unmapped_project_rows' => $unmapped];
    }

    public function isOwnedOrUnmapped(Branch $branch, array $row): bool
    {
        return $this->filterRows($branch, [$row])['rows'] !== [];
    }

    public function isOperationallyOwned(Branch $branch, array $row): bool
    {
        $label = $this->projectLabel($row);
        if ($label === '') {
            return true;
        }

        $normalized = $this->normalize($label);
        $labels = $this->labelsForBranch($branch);

        return ! $labels['has_branch_scoped_projects'] || (isset($labels['all'][$normalized])
            && count($labels['owned'][$normalized] ?? []) === 1
            && count($labels['all'][$normalized] ?? []) === 1);
    }

    /** @return array{owned: array<string, array<int, true>>, all: array<string, array<int, true>>, has_branch_scoped_projects: bool} */
    private function labelsForBranch(Branch $branch): array
    {
        return (function () use ($branch): array {
            $projects = LeadMaster::query()
                ->where('is_active', true)
                ->get(['id', 'branch_id', 'project_name', 'sheet_project_name', 'sheet_project_aliases']);
            $owned = [];
            $all = [];
            $hasBranchScopedProjects = $projects->contains(fn (LeadMaster $project): bool => $project->branch_id !== null);
            foreach ($projects as $project) {
                $labels = [$project->project_name, $project->sheet_project_name, ...($project->sheet_project_aliases ?? [])];
                foreach ($labels as $label) {
                    $normalized = $this->normalize((string) $label);
                    if ($normalized === '') {
                        continue;
                    }
                    $all[$normalized][(int) $project->id] = true;
                    if (! $hasBranchScopedProjects || (int) $project->branch_id === (int) $branch->id) {
                        $owned[$normalized][(int) $project->id] = true;
                    }
                }
            }

            return ['owned' => $owned, 'all' => $all, 'has_branch_scoped_projects' => $hasBranchScopedProjects];
        })();
    }

    private function projectLabel(array $row): string
    {
        $normalized = collect($row)->mapWithKeys(fn ($value, $key): array => [Str::lower(trim((string) $key)) => is_scalar($value) ? trim((string) $value) : '']);

        foreach (['project_name', 'project name', 'nama_project', 'nama proyek', 'proyek', 'project'] as $key) {
            if (($value = $normalized->get($key)) !== null && $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }

    private function normalize(string $value): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($value)) ?? '');
    }
}
