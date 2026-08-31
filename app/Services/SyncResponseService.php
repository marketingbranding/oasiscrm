<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class SyncResponseService
{
    public function make(string $module, array $scope, ?Model $status, array $result = []): array
    {
        $idle = ! $status && $result === [];
        $rawStatus = $result['status'] ?? $status?->status ?? 'idle';
        $stalled = in_array($rawStatus, ['running', 'syncing'], true) && $status?->started_at?->lt(now()->subMinutes(15));
        $terminal = ! $idle && ($stalled || ! in_array($rawStatus, ['running', 'syncing'], true));
        $outcome = match ($result['outcome'] ?? $rawStatus) {
            'warning', 'partial_success' => 'partial_success',
            'success' => 'success',
            'failed' => 'failed',
            default => $terminal ? (($result['ok'] ?? false) ? 'success' : 'failed') : null,
        };
        $ok = $outcome === 'success';
        $summary = $result['summary'] ?? $status?->summary ?? [];
        $startedAt = $status?->started_at;
        $finishedAt = $status?->finished_at;
        $duration = $status?->duration_ms;
        if (! $duration && $startedAt && $finishedAt) {
            $duration = $startedAt->diffInMilliseconds($finishedAt);
        }

        return [
            'ok' => $ok,
            'status' => $idle ? 'idle' : ($stalled ? 'timed_out' : ($terminal ? ($outcome ?: 'failed') : 'syncing')),
            'code' => $result['code'] ?? ($idle ? 'sync_idle' : ($stalled ? 'sync_status_uncertain' : ($terminal ? 'sync_'.($outcome ?: 'failed') : 'sync_running'))),
            'message' => $idle ? 'Belum pernah disinkronkan.' : ($stalled ? 'Proses memerlukan waktu lebih lama dari biasanya. Periksa status sebelum mencoba kembali.' : $this->message($outcome, $terminal)),
            'scope' => $scope,
            'started_at' => $startedAt?->toIso8601String(),
            'finished_at' => $finishedAt?->toIso8601String(),
            'duration_ms' => $duration,
            'summary' => $this->summary($module, $summary),
            'details' => ['module_summary' => $summary],
            'last_successful_sync_at' => $status?->last_successful_at?->toIso8601String(),
            'initiated_by' => $status?->initiator?->name,
            'retryable' => $stalled || $outcome === 'failed' || ($result['code'] ?? null) === 'sync_already_running',
            'error_code' => $stalled ? null : ($outcome === 'failed' ? 'google_connection_failed' : null),
            'local_data_changed' => $outcome === 'success' || $outcome === 'partial_success',
        ];
    }

    private function message(?string $outcome, bool $terminal): string
    {
        if (! $terminal) {
            return 'Sinkronisasi sedang diproses.';
        }

        return match ($outcome) {
            'success' => 'Sinkronisasi berhasil diselesaikan.',
            'partial_success' => 'Sinkronisasi selesai dengan beberapa data gagal atau perlu diperiksa.',
            default => 'Sinkronisasi gagal sebelum seluruh data dapat diperbarui.',
        };
    }

    private function summary(string $module, array $summary): array
    {
        if ($module === 'dana-talangan') {
            return array_filter([
                'checked' => isset($summary['matched'], $summary['imported']) ? $summary['matched'] + $summary['imported'] : null,
                'created' => $summary['imported'] ?? null,
                'updated' => $summary['updated'] ?? null,
                'unchanged' => $summary['unchanged'] ?? null,
                'deleted' => $summary['deleted'] ?? null,
                'failed' => $summary['push_failed'] ?? null,
            ], fn ($value) => $value !== null);
        }

        if ($module === 'sales-lead-lifecycle') {
            return array_filter([
                'checked' => $summary !== [] ? array_sum([
                    $summary['imported'] ?? ($summary['claimed'] ?? 0),
                    $summary['updated'] ?? 0,
                    $summary['linked'] ?? 0,
                    $summary['unresolved'] ?? 0,
                    $summary['ignored_deleted'] ?? 0,
                ]) : null,
                'imported' => $summary['imported'] ?? ($summary['claimed'] ?? null),
                'updated' => $summary['updated'] ?? null,
                'linked' => $summary['linked'] ?? null,
                'unchanged' => $summary['unchanged'] ?? null,
                'claimable' => $summary['claimable'] ?? null,
                'unresolved' => $summary['unresolved'] ?? null,
                'ignored_deleted' => $summary['ignored_deleted'] ?? null,
                'capabilities' => $summary['capabilities'] ?? null,
            ], fn ($value) => $value !== null);
        }

        return ['checked' => array_sum(array_filter($summary, 'is_numeric'))];
    }
}
