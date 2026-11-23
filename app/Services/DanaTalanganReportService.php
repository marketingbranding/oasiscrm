<?php

namespace App\Services;

use App\Models\DanaTalangan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DanaTalanganReportService
{
    public function report(User $user, ?int $branchId, ?int $projectId, Carbon $from, Carbon $to): array
    {
        $scope = app(OrganizationScopeService::class);
        abort_if($branchId !== null && ! in_array($branchId, $scope->branchIds($user, 'bridge_fund', 'view'), true), 403);
        abort_if($projectId !== null && ! in_array($projectId, $scope->projectIds($user, 'bridge_fund', 'view'), true), 403);

        $baseQuery = DanaTalangan::query()->visibleTo($user, 'view')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId));
        $records = (clone $baseQuery)
            ->whereDate('tanggal', '>=', $from)
            ->whereDate('tanggal', '<=', $to)
            ->with(['payments' => fn ($query) => $query->whereDate('paid_at', '<=', $to)])
            ->get();
        $paymentRecords = (clone $baseQuery)
            ->with(['payments' => fn ($query) => $query->whereBetween('paid_at', [$from, $to])])
            ->get();

        $verified = $records->sum(fn (DanaTalangan $record): int => $this->sumCents($record->payments->where('is_verified', true)));
        $unverified = $records->sum(fn (DanaTalangan $record): int => $this->sumCents($record->payments->where('is_verified', false)));
        $receivedVerified = $paymentRecords->sum(fn (DanaTalangan $record): int => $this->sumCents($record->payments->where('is_verified', true)));
        $receivedUnverified = $paymentRecords->sum(fn (DanaTalangan $record): int => $this->sumCents($record->payments->where('is_verified', false)));
        $nominal = $records->sum(fn (DanaTalangan $record): int => $this->toCents($record->nominal));
        $remaining = $records->sum(fn (DanaTalangan $record): int => max(0, $this->toCents($record->nominal) - $this->sumCents($record->payments->where('is_verified', true))));
        $overpaid = $records->sum(fn (DanaTalangan $record): int => max(0, $this->sumCents($record->payments->where('is_verified', true)) - $this->toCents($record->nominal)));

        return [
            'count' => $records->count(),
            'nominal' => $this->formatCents($nominal),
            'verified' => $this->formatCents($verified),
            'unverified' => $this->formatCents($unverified),
            'received_verified' => $this->formatCents($receivedVerified),
            'received_unverified' => $this->formatCents($receivedUnverified),
            'remaining' => $this->formatCents($remaining),
            'overpaid' => $this->formatCents($overpaid),
            'by_status' => $records->groupBy('status')->map->count()->all(),
        ];
    }

    private function sumCents(Collection $payments): int
    {
        return $payments->sum(fn ($payment): int => $this->toCents($payment->amount));
    }

    private function toCents(mixed $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), abs($cents % 100));
    }
}
