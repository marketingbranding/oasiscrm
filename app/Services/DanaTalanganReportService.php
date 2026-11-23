<?php

namespace App\Services;

use App\Models\DanaTalangan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DanaTalanganReportService
{
    public function report(User $user, ?int $branchId, ?int $projectId, ?Carbon $from, ?Carbon $to): array
    {
        $records = DanaTalangan::query()->visibleTo($user, 'view')
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->when($projectId, fn ($query) => $query->where('project_id', $projectId))
            ->with(['payments' => fn ($query) => $query->whereBetween('paid_at', [$from?->toDateString() ?? '1900-01-01', $to?->toDateString() ?? '2999-12-31'])])
            ->when($from, fn ($query) => $query->whereDate('tanggal', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('tanggal', '<=', $to))
            ->get();

        $verified = $records->sum(fn (DanaTalangan $record): int => $this->sumCents($record->payments->where('is_verified', true)));
        $unverified = $records->sum(fn (DanaTalangan $record): int => $this->sumCents($record->payments->where('is_verified', false)));
        $nominal = $records->sum(fn (DanaTalangan $record): int => $this->toCents($record->nominal));

        return [
            'records' => $records,
            'count' => $records->count(),
            'nominal' => $this->formatCents($nominal),
            'verified' => $this->formatCents($verified),
            'unverified' => $this->formatCents($unverified),
            'remaining' => $this->formatCents(max(0, $nominal - $verified)),
            'overpaid' => $this->formatCents(max(0, $verified - $nominal)),
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
