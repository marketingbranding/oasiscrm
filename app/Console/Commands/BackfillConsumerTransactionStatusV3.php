<?php

namespace App\Console\Commands;

use App\Models\ConsumerApplication;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class BackfillConsumerTransactionStatusV3 extends Command
{
    protected $signature = 'consumer:transaction-status-v3-backfill
        {--dry-run : Hanya melaporkan pemetaan tanpa menulis data transaksi}
        {--output= : Path relatif pada disk local untuk laporan JSON}';

    protected $aliases = ['consumer:legacy-transaction-map', 'consumer:transaction-status-map'];

    protected $description = 'Melaporkan pemetaan status transaksi legacy ke status/event kanonis tanpa membuat riwayat palsu.';

    public function handle(): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Gunakan --dry-run. Perintah ini hanya menyediakan laporan sebelum migrasi ditinjau.');

            return self::FAILURE;
        }

        try {
            $output = $this->outputPath();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $counts = [
            'pindah_kavling_ke_lanjut' => 0,
            'reject_slik_ke_mundur' => 0,
            'reject_bank_ke_mundur' => 0,
            'reject_data_lama_ke_mundur' => 0,
            'diganti_konsumen_ke_ditutup' => 0,
            'sudah_kanonis' => 0,
            'perlu_tinjau' => 0,
        ];
        $mappings = [];
        $reviewCases = [];
        $distinctStatuses = [];

        ConsumerApplication::query()
            ->with('migrationReconciliation')
            ->orderBy('id')
            ->chunkById(500, function ($applications) use (&$counts, &$mappings, &$reviewCases, &$distinctStatuses): void {
                foreach ($applications as $application) {
                    $mapping = $this->map($application);
                    $statusKey = $mapping['mapping']['legacy_status'];
                    if (! array_key_exists($statusKey, $distinctStatuses)) {
                        $distinctStatuses[$statusKey] = [
                            'transaction_status' => $statusKey,
                            'count' => 0,
                            'mapping' => [
                                'transaction_status' => $mapping['mapping']['transaction_status'],
                                'needs_review' => $mapping['mapping']['needs_review'],
                            ],
                        ];
                    }
                    $distinctStatuses[$statusKey]['count']++;
                    $counts[$mapping['category']]++;
                    $mappings[] = $mapping['mapping'];
                    if ($mapping['review'] !== null) {
                        $reviewCases[] = $mapping['review'];
                    }
                }
            });

        $report = [
            'meta' => [
                'command' => 'consumer:transaction-status-v3-backfill',
                'dry_run' => true,
                'generated_at' => now()->toIso8601String(),
                'source' => 'consumer_applications.transaction_status/consumer_status',
            ],
            'counts' => $counts,
            'distinct_transaction_statuses' => $distinctStatuses,
            'mappings' => $mappings,
            'review_cases' => $reviewCases,
        ];

        Storage::disk('local')->put($output, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->table(
            ['Kategori', 'Jumlah'],
            collect($counts)->map(fn (int $count, string $category): array => [$category, $count])->values()->all(),
        );
        $this->line('Kasus perlu tinjau: '.count($reviewCases));
        $this->table(
            ['Status legacy', 'Jumlah', 'Pemetaan'],
            collect($distinctStatuses)->map(fn (array $status): array => [
                $status['transaction_status'] ?? '(kosong)',
                $status['count'],
                $status['mapping']['transaction_status'].($status['mapping']['needs_review'] ? ' (perlu tinjau)' : ''),
            ])->all(),
        );
        $this->info('Laporan tersimpan: '.$output);

        return self::SUCCESS;
    }

    /**
     * @return array{category: string, mapping: array<string, mixed>, review: array<string, mixed>|null}
     */
    private function map(ConsumerApplication $application): array
    {
        $legacyStatus = trim((string) ($application->transaction_status ?: ($application->consumer_status ?: $application->application_status)));
        $normalized = mb_strtoupper($legacyStatus);
        $base = [
            'application_id' => $application->id,
            'id_transaksi' => $application->id_transaksi,
            'legacy_status' => $legacyStatus === '' ? null : $legacyStatus,
        ];

        if (in_array($normalized, ['PINDAH KAVLING', 'PINDAH_KAVLING'], true)) {
            return [
                'category' => 'pindah_kavling_ke_lanjut',
                'mapping' => $base + ['transaction_status' => 'LANJUT', 'needs_review' => false, 'event_type' => 'PINDAH_KAVLING'],
                'review' => null,
            ];
        }

        if (in_array($normalized, ['REJECT', 'REJECT SLIK', 'REJECT BANK'], true)) {
            $reason = $normalized === 'REJECT SLIK'
                ? 'Reject SLIK'
                : ($normalized === 'REJECT BANK' ? 'Reject Bank' : $this->rejectReason($application));
            $review = $reason === 'Reject (data lama)'
                ? $base + ['case' => 'reject_reason_unknown', 'reason' => $reason]
                : null;

            return [
                'category' => match ($reason) {
                    'Reject SLIK' => 'reject_slik_ke_mundur',
                    'Reject Bank' => 'reject_bank_ke_mundur',
                    default => 'reject_data_lama_ke_mundur',
                },
                'mapping' => $base + ['transaction_status' => 'MUNDUR', 'needs_review' => $reason === 'Reject (data lama)', 'withdraw_reason' => $reason],
                'review' => $review,
            ];
        }

        if (in_array($normalized, ['DIGANTI KONSUMEN', 'DIGANTI_KONSUMEN', 'REPLACED', 'GANTI NAMA', 'GANTI_NAMA'], true)) {
            return [
                'category' => 'diganti_konsumen_ke_ditutup',
                'mapping' => $base + ['transaction_status' => 'DITUTUP', 'needs_review' => false, 'closed_by_event' => 'GANTI_NAMA'],
                'review' => null,
            ];
        }

        if (in_array($normalized, ['LANJUT', 'MUNDUR', 'DITUTUP', 'COMPLETED', 'SELESAI'], true)) {
            $mappedStatus = in_array($normalized, ['COMPLETED', 'SELESAI'], true) ? 'LANJUT' : $normalized;

            return [
                'category' => 'sudah_kanonis',
                'mapping' => $base + ['transaction_status' => $mappedStatus, 'needs_review' => false],
                'review' => null,
            ];
        }

        return [
            'category' => 'perlu_tinjau',
            'mapping' => $base + ['transaction_status' => 'LANJUT', 'needs_review' => true],
            'review' => $base + ['case' => 'legacy_status_unknown'],
        ];
    }

    private function rejectReason(ConsumerApplication $application): string
    {
        $reconciliation = $application->migrationReconciliation;
        $signals = mb_strtoupper(implode(' ', array_filter([
            $reconciliation?->source_transaction_status,
            $reconciliation?->source_bank_status,
            $reconciliation?->source_stage_status,
        ], fn (?string $value): bool => filled($value))));
        $hasSlik = str_contains($signals, 'SLIK') || str_contains($signals, 'BI CHECK');
        $hasBank = str_contains($signals, 'BANK') || str_contains($signals, 'KPR') || str_contains($signals, 'SP3K');

        return match (true) {
            $hasSlik && ! $hasBank => 'Reject SLIK',
            $hasBank && ! $hasSlik => 'Reject Bank',
            default => 'Reject (data lama)',
        };
    }

    private function outputPath(): string
    {
        $output = trim((string) ($this->option('output') ?: 'temp/consumer-transaction-status-v3-'.now()->format('Ymd_His').'.json'));
        if ($output === '' || str_contains($output, '..') || str_starts_with($output, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $output) === 1) {
            throw new InvalidArgumentException('Opsi --output harus berupa path relatif pada disk local dan tidak boleh keluar dari direktori storage.');
        }

        return $output;
    }
}
