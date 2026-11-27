<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\ProjectTransferBatch;
use Illuminate\Support\Str;

final class SheetProjectOwnershipService
{
    /** @var array<int, array{owned: array<string, array<int, true>>, all: array<string, array<int, true>>, has_branch_scoped_projects: bool}> */
    private array $labelsByBranch = [];

    /** @return array{rows: array<int, array>, row_indexes: array<int, int>, all_rows: array<int, array>, all_row_indexes: array<int, int>, statuses: array<int, string>, foreign_project_rows: int, unmapped_project_rows: int} */
    public function filterRows(Branch $branch, array $rows): array
    {
        $labels = $this->labelsForBranch($branch);
        $owned = $labels['owned'];
        $all = $labels['all'];
        $kept = [];
        $rowIndexes = [];
        $allRows = [];
        $allRowIndexes = [];
        $allStatuses = [];
        $foreign = 0;
        $unmapped = 0;

        foreach ($rows as $index => $row) {
            $label = $this->projectLabel($row);
            $status = 'unmapped';
            if ($label !== '') {
                $normalized = $this->normalize($label);
                if (count($owned[$normalized] ?? []) >= 1 && count($all[$normalized] ?? []) === 1) {
                    $status = 'owned';
                } elseif (isset($all[$normalized])) {
                    $status = 'foreign';
                    $foreign++;
                } else {
                    $unmapped++;
                }
            }

            $allRows[] = $row;
            $allRowIndexes[] = (int) $index;
            $allStatuses[] = $status;
            if ($status !== 'foreign') {
                $rowIndexes[] = (int) $index;
                $kept[] = $row;
            }
        }

        return ['rows' => $kept, 'row_indexes' => $rowIndexes, 'all_rows' => $allRows, 'all_row_indexes' => $allRowIndexes, 'statuses' => $allStatuses, 'foreign_project_rows' => $foreign, 'unmapped_project_rows' => $unmapped];
    }

    public function isOwnedOrUnmapped(Branch $branch, array $row): bool
    {
        return $this->filterRows($branch, [$row])['rows'] !== [];
    }

    public function isOperationallyOwnedByStatus(?string $status): bool
    {
        return $status === 'owned';
    }

    public function classify(Branch $branch, ?string $status, array $row): string
    {
        return $status ?? ($this->filterRows($branch, [$row])['statuses'][0] ?? 'unmapped');
    }

    public function allowsOperationalRow(Branch $branch, ?string $status, array $row): bool
    {
        $project = $this->resolveUniqueProject($row);
        if ($project === null) {
            return $this->isOperationallyOwned($branch, $row);
        }

        $transfer = $this->latestTransfer($project);
        if ($transfer === null || $transfer->rolled_back_at !== null || ! in_array($transfer->status, ['committed', 'completed', 'cache_failed'], true)) {
            return $project->branch_id !== null && (int) $project->branch_id === (int) $branch->id;
        }

        return (int) $transfer->target_branch_id === (int) $branch->id;
    }

    private function resolveUniqueProject(array $row): ?LeadMaster
    {
        $label = $this->projectLabel($row);
        if ($label === '') {
            return null;
        }

        $normalized = $this->normalize($label);
        $projects = LeadMaster::query()
            ->where('is_active', true)
            ->get(['id', 'branch_id', 'project_name', 'sheet_project_name', 'sheet_project_aliases'])
            ->filter(function (LeadMaster $project) use ($normalized): bool {
                $labels = [$project->project_name, $project->sheet_project_name, ...($project->sheet_project_aliases ?? [])];

                return collect($labels)->contains(fn ($label): bool => $this->normalize((string) $label) === $normalized);
            });

        return $projects->count() === 1 ? $projects->first() : null;
    }

    private function latestTransfer(LeadMaster $project): ?ProjectTransferBatch
    {
        return ProjectTransferBatch::query()
            ->whereJsonContains('project_ids', $project->id)
            ->whereNotNull('executed_at')
            ->latest('executed_at')
            ->latest('id')
            ->first(['source_branch_id', 'target_branch_id', 'rolled_back_at', 'status']);
    }

    public function isOperationallyOwned(Branch $branch, array $row): bool
    {
        $label = $this->projectLabel($row);
        $labels = $this->labelsForBranch($branch);
        if ($label === '') {
            return ! $labels['has_branch_scoped_projects'];
        }

        $normalized = $this->normalize($label);

        return ! $labels['has_branch_scoped_projects'] || (isset($labels['all'][$normalized])
            && count($labels['owned'][$normalized] ?? []) === 1
            && count($labels['all'][$normalized] ?? []) === 1);
    }

    /** @return array{owned: array<string, array<int, true>>, all: array<string, array<int, true>>, has_branch_scoped_projects: bool} */
    private function labelsForBranch(Branch $branch): array
    {
        if (isset($this->labelsByBranch[$branch->id])) {
            return $this->labelsByBranch[$branch->id];
        }

        return $this->labelsByBranch[$branch->id] = (function () use ($branch): array {
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
