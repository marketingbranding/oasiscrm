<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\MarisonProjectMapping;

class MarisonV2ProjectResolver
{
    public function resolve(string $branchCode, string $sourceProjectId): array
    {
        $branch = Branch::query()->where('code', $branchCode)->first();
        if ($branch === null) {
            return ['branch' => null, 'mapping' => null, 'project' => null, 'error' => 'Kode cabang sumber tidak dikenal.'];
        }

        $mapping = MarisonProjectMapping::query()
            ->with('project')
            ->where('source_system', MarisonV2PackageValidator::SOURCE_SYSTEM)
            ->where('branch_code', $branchCode)
            ->where('source_project_id', $sourceProjectId)
            ->first();

        if ($mapping === null || $mapping->project === null || (int) $mapping->project->branch_id !== (int) $branch->id) {
            return ['branch' => $branch, 'mapping' => null, 'project' => null, 'error' => 'Pemetaan proyek sumber belum tersedia atau tidak sesuai cabang.'];
        }

        return ['branch' => $branch, 'mapping' => $mapping, 'project' => $mapping->project, 'error' => null];
    }
}
