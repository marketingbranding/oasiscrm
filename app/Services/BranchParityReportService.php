<?php

namespace App\Services;

use App\Data\BranchParityReport;
use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerParitySnapshot;
use App\Models\KonsumenProgressSheetRow;
use Illuminate\Support\Carbon;

final class BranchParityReportService
{
    public function __construct(
        private readonly ConsumerReadComparisonService $comparison,
        private readonly ConsumerReadinessService $readiness,
    ) {}

    public function report(Branch $branch): BranchParityReport
    {
        $differences = collect();
        $comparisonSummary = collect(['legacy_only' => 0, 'local_only' => 0, 'mismatch' => 0, 'ambiguous' => 0]);

        foreach ($branch->projects()->get() as $project) {
            $result = $this->comparison->compare($branch, $project);
            $readiness = $this->readiness->summarize($result);
            foreach ($comparisonSummary->keys() as $key) {
                $comparisonSummary[$key] += $readiness[$key] ?? 0;
            }
            $differences = $differences->merge(collect($result->rows)->filter(fn (array $row): bool => $row['status'] !== 'MATCHED')->map(fn (array $row): array => [
                'project_id' => $project->id,
                'status' => $row['status'],
                'legacy_key' => $row['legacy_identifier'],
                'oasis_application_id' => $row['local_application_id'],
                'kavling' => $row['kavling'],
                'mismatch_fields' => $row['mismatch_fields'],
            ]));
        }

        $applications = ConsumerApplication::query()->where('branch_id', $branch->id)->get();
        $statusCounts = $applications->countBy(fn (ConsumerApplication $application): string => $application->canonicalTransactionStatus());
        $occupied = $applications->filter(fn (ConsumerApplication $application): bool => $application->kavling_id !== null && $application->canonicalTransactionStatus() === 'LANJUT')->pluck('kavling_id')->unique()->count();
        $metrics = [
            'transactions_by_status' => collect(['LANJUT' => 0, 'MUNDUR' => 0, 'DITUTUP' => 0])->merge($statusCounts)->all(),
            'akad_by_month' => $applications->whereNotNull('akad_date')->countBy(fn (ConsumerApplication $application): string => $application->akad_date->format('Y-m')),
            'last_process' => $applications->countBy(fn (ConsumerApplication $application): string => (string) ($application->current_stage ?: 'BELUM ADA')),
            'occupied_kavling' => $occupied,
            'sheet_rows' => KonsumenProgressSheetRow::query()->where('branch_id', $branch->id)->count(),
            'oasis_transactions' => $applications->count(),
        ];
        $summary = [
            ...$comparisonSummary->all(),
            'sheet_only' => $comparisonSummary['legacy_only'],
            'oasis_only' => $comparisonSummary['local_only'],
            'unavailable' => $branch->sheet_id ? 0 : 1,
            'duplicate' => $comparisonSummary['ambiguous'],
        ];
        $status = array_sum($summary) === 0 ? 'LULUS' : 'GAGAL';

        $differences = $differences->values()->all();
        ConsumerParitySnapshot::create([
            'branch_id' => $branch->id,
            'checked_at' => Carbon::now(),
            'status' => $status,
            'summary' => $summary,
            'metrics' => $metrics,
            'differences' => $differences,
        ]);

        return new BranchParityReport($branch, $status, $summary, $metrics, $differences);
    }
}
