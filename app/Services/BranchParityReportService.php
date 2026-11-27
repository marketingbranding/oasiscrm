<?php

namespace App\Services;

use App\Data\BranchParityReport;
use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerParitySnapshot;
use App\Models\KonsumenProgressSheetRow;
use App\Models\KonsumenProgressSyncStatus;
use Illuminate\Support\Carbon;

final class BranchParityReportService
{
    public function __construct(
        private readonly ConsumerReadComparisonService $comparison,
        private readonly ConsumerReadinessService $readiness,
        private readonly LegacyConsumerReadService $legacy,
        private readonly LocalConsumerReadService $local,
        private readonly SheetProjectOwnershipService $ownership,
    ) {}

    public function report(Branch $branch): BranchParityReport
    {
        $differences = collect();
        $comparisonSummary = collect(['legacy_only' => 0, 'local_only' => 0, 'mismatch' => 0, 'ambiguous' => 0]);
        $sideMetrics = ['sheet' => ['status' => [], 'akad' => [], 'process' => [], 'kavling' => 0], 'oasis' => ['status' => [], 'akad' => [], 'process' => [], 'kavling' => 0]];

        foreach ($branch->projects()->get() as $project) {
            $legacyRecords = $this->legacy->records($branch, $project);
            $localRecords = $this->local->records($branch, $project);
            $this->addSideMetrics($sideMetrics['sheet'], $legacyRecords);
            $this->addSideMetrics($sideMetrics['oasis'], $localRecords);
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

        $ownershipRows = KonsumenProgressSheetRow::query()->where('branch_id', $branch->id)->get()->map(fn (KonsumenProgressSheetRow $row): string => $this->ownership->classify($branch, $row->ownership_status, $row->row_data ?? []));
        $foreignRows = $ownershipRows->countBy()->get('foreign', 0);
        $unmappedRows = $ownershipRows->countBy()->get('unmapped', 0);
        $applications = ConsumerApplication::query()->where('branch_id', $branch->id)->get();
        $statusCounts = $applications->countBy(fn (ConsumerApplication $application): string => $application->canonicalTransactionStatus());
        $occupied = $applications->filter(fn (ConsumerApplication $application): bool => $application->kavling_id !== null && $application->canonicalTransactionStatus() === 'LANJUT')->pluck('kavling_id')->unique()->count();
        $metrics = [
            'transactions_by_status' => collect(['LANJUT' => 0, 'MUNDUR' => 0, 'DITUTUP' => 0])->merge($statusCounts)->all(),
            'akad_by_month' => $applications->whereNotNull('akad_date')->countBy(fn (ConsumerApplication $application): string => $application->akad_date->format('Y-m')),
            'last_process' => $applications->countBy(fn (ConsumerApplication $application): string => (string) ($application->current_stage ?: 'BELUM ADA')),
            'occupied_kavling' => $occupied,
            'sheet_rows' => $ownershipRows->count() - $foreignRows,
            'foreign_project_rows' => $foreignRows,
            'unmapped_project_rows' => $unmappedRows,
            'oasis_transactions' => $applications->count(),
            'per_side' => $sideMetrics,
            'delta' => $this->metricDelta($sideMetrics),
        ];
        $summary = [
            ...$comparisonSummary->all(),
            'sheet_only' => $comparisonSummary['legacy_only'],
            'oasis_only' => $comparisonSummary['local_only'],
            'unavailable' => $branch->sheet_id ? 0 : 1,
            'duplicate' => $comparisonSummary['ambiguous'],
            'foreign_project_rows' => $foreignRows,
        ];
        $sync = KonsumenProgressSyncStatus::query()->where('branch_id', $branch->id)->latest('finished_at')->first();
        $fresh = $sync?->status === 'success' && $sync->finished_at?->gte(now()->subHours(24));
        $complete = $fresh && $metrics['sheet_rows'] > 0;
        if (! $complete) {
            $summary['alasan_tidak_lengkap'] = $metrics['sheet_rows'] === 0 ? 'Cermin sheet kosong.' : 'Sinkronisasi terakhir tidak sukses atau sudah basi.';
        }
        $status = ! $complete ? 'TIDAK_LENGKAP' : ($differences->isEmpty() ? 'LULUS' : 'GAGAL');

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

    private function metricDelta(array $metrics): array
    {
        return [
            'status' => $this->subtractCounts($metrics['oasis']['status'], $metrics['sheet']['status']),
            'akad' => $this->subtractCounts($metrics['oasis']['akad'], $metrics['sheet']['akad']),
            'process' => $this->subtractCounts($metrics['oasis']['process'], $metrics['sheet']['process']),
            'kavling' => $metrics['oasis']['kavling'] - $metrics['sheet']['kavling'],
        ];
    }

    private function subtractCounts(array $left, array $right): array
    {
        return collect(array_unique([...array_keys($left), ...array_keys($right)]))->mapWithKeys(fn (string $key): array => [$key => ($left[$key] ?? 0) - ($right[$key] ?? 0)])->all();
    }

    private function addSideMetrics(array &$metrics, array $records): void
    {
        foreach ($records as $record) {
            $details = ConsumerApplication::canonicalTransactionStatusDetailsFor($record->applicationStatus);
            $status = $record->applicationStatus === null || trim($record->applicationStatus) === '' ? 'TANPA STATUS' : $details['status'];
            $metrics['status'][$status] = ($metrics['status'][$status] ?? 0) + 1;
            if ($record->akadDate) {
                $month = substr($record->akadDate, 0, 7);
                $metrics['akad'][$month] = ($metrics['akad'][$month] ?? 0) + 1;
            }
            $stage = $record->currentStage ?: 'BELUM ADA';
            $metrics['process'][$stage] = ($metrics['process'][$stage] ?? 0) + 1;
            if ($record->kavlingId || $record->kavlingLabel) {
                $metrics['kavling']++;
            }
        }
    }
}
