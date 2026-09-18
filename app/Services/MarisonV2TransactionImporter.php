<?php

namespace App\Services;

use App\Models\ConsumerAkadRecord;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBastRecord;
use App\Models\ConsumerLegacyIdentity;
use App\Models\ConsumerMigrationReconciliation;
use App\Models\ConsumerMigrationSourceRecord;
use App\Models\ConsumerPpjbDeveloper;
use App\Models\ConsumerPsjb;
use App\Models\ConsumerStageEvent;
use App\Models\MarisonImportTransaction;

final class MarisonV2TransactionImporter
{
    private const STAGE_ORDER = ['bi_checking', 'PSJB', 'pemberkasan', 'proses_bank', 'ppjb_dev', 'akad', 'bast'];

    public function __construct(
        private readonly MarisonV2PackageValidator $validator,
        private readonly MarisonV2BankAttemptImporter $banks,
    ) {}

    public function import(MarisonImportTransaction $import): array
    {
        $transaction = $import->payload;
        $project = $import->resolvedProject()->with('branch')->firstOrFail();
        $application = ConsumerApplication::query()->create([
            'customer_id' => null,
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'kavling_id' => null,
            'id_kavling' => $transaction['current_kavling'],
            'nama_konsumen' => null,
            'nik' => null,
            'application_status' => 'migration_pending',
            'consumer_status' => null,
            'status_cash' => mb_strtoupper((string) data_get($transaction, 'payment.method')) === 'CASH',
            'source_completeness_status' => 'Menunggu Rekonsiliasi',
            'notes' => 'Impor Marison V2; identitas konsumen dan kavling belum direkonsiliasi.',
        ]);
        $identity = ConsumerLegacyIdentity::query()->create([
            'consumer_application_id' => $application->id,
            'customer_id' => null,
            'legacy_source' => MarisonV2PackageValidator::SOURCE_SYSTEM,
            'spreadsheet_id' => $import->batch->source_spreadsheet_id,
            'sheet_name' => 'trx_penjualan',
            'external_key' => $import->source_transaction_id,
            'source_payload_hash' => $import->payload_hash,
            'mapping_status' => 'pending_customer_reconciliation',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->bi($application, $import, $transaction['process']['bi_checking']);
        $this->psjb($application, $import, $transaction['process']['psjb']);
        $bankAttempts = $this->banks->import($application, $import, $transaction);
        $canonicalSp3k = collect($bankAttempts)->filter(fn ($row) => $row->sp3k_at !== null)->sortByDesc('sp3k_at')->first()?->sp3k_at?->toDateString();
        $this->ppjb($application, $import, $transaction['process']['ppjb_dev'], $canonicalSp3k);
        $this->akad($application, $import, $transaction['process']['akad']);
        $this->bast($application, $import, $transaction['process']['bast']);
        $this->history($application, $import, $transaction['history']);

        $stage = $this->derivedStage($application);
        $application->update([
            'current_stage' => $stage,
            'source_last_process' => $stage,
            'akad_date' => $application->akadRecords()->max('tanggal_akad'),
        ]);
        $reconciliation = ConsumerMigrationReconciliation::query()->create([
            'consumer_application_id' => $application->id,
            'branch_id' => $application->branch_id,
            'project_id' => $application->project_id,
            'source_system' => MarisonV2PackageValidator::SOURCE_SYSTEM,
            'source_transaction_id' => $import->source_transaction_id,
            'source_customer_ref' => $import->source_customer_ref,
            'source_current_kavling' => $transaction['current_kavling'],
            'source_transaction_status' => data_get($transaction, 'source_state.status_transaksi'),
            'source_bank_status' => data_get($transaction, 'source_state.status_bank'),
            'source_kavling_status' => data_get($transaction, 'source_state.status_kavling'),
            'source_stage_status' => data_get($transaction, 'source_state.tahap_terkini'),
            'suggested_decision' => $this->suggestedDecision($transaction),
            'reconciliation_status' => 'PENDING',
            'source_payload_hash' => $import->payload_hash,
        ]);
        $import->update(['consumer_application_id' => $application->id, 'reconciliation_id' => $reconciliation->id]);

        return compact('application', 'identity', 'reconciliation');
    }

    private function bi(ConsumerApplication $application, MarisonImportTransaction $import, array $rows): void
    {
        foreach ($rows as $i => $row) {
            $id = (string) ($row['id_kons'] ?? 'bi-'.$i);
            $this->event($application, $import, 'bi_checking', $id, $row, $row['tanggal_slik'] ?? null, $row['hasil_slik'] ?? null);
        }
    }

    private function psjb(ConsumerApplication $application, MarisonImportTransaction $import, array $rows): void
    {
        foreach ($rows as $i => $row) {
            $id = (string) ($row['id_psjb'] ?? 'psjb-'.$i);
            $event = $this->event($application, $import, 'PSJB', $id, $row, $row['tanggal_psjb'] ?? null, $row['status'] ?? null);
            $record = ConsumerPsjb::query()->create([
                'consumer_application_id' => $application->id, 'consumer_stage_event_id' => $event->id,
                'id_kavling' => $row['id_kavling'] ?? $application->id_kavling, 'id_kons' => $row['id_kons'] ?? null, 'id_psjb' => $id,
                'tanggal_psjb' => $row['tanggal_psjb'], 'nama_koordinator' => $row['nama_koordinator'] ?? null, 'nama_sales' => $row['nama_sales'] ?? null,
                'harga_unit' => $this->decimal($row['harga_unit'] ?? null), 'tanggal_utj' => $this->date($row['tanggal_utj'] ?? null), 'utj' => $this->decimal($row['utj'] ?? null),
                'dp_all_in' => $this->decimal($row['dp_all_in'] ?? null), 'nominal_cicilan' => $this->decimal($row['nominal_cicilan'] ?? null),
                'jumlah_cicilan' => $this->integer($row['jumlah_cicilan'] ?? null), 'luas_klt' => $this->decimal($row['luas_klt'] ?? $row['luas_klt_m2'] ?? null),
                'harga_klt_m' => $this->decimal($row['harga_klt_m'] ?? $row['harga_klt/m'] ?? null), 'harga_klt_total' => $this->decimal($row['harga_klt_total'] ?? null),
                'cara_pembayaran' => $row['cara_pembayaran'] ?? null, 'status' => $row['status'] ?? null, 'keterangan' => $row['keterangan'] ?? null,
            ]);
            $this->source($import, $application, 'psjb', $id, $row, $record);
        }
    }

    private function ppjb(ConsumerApplication $application, MarisonImportTransaction $import, array $rows, ?string $canonicalSp3k): void
    {
        foreach ($rows as $i => $row) {
            $id = (string) ($row['id_ppjb_dev'] ?? 'ppjb-'.$i);
            $event = $this->event($application, $import, 'ppjb_dev', $id, $row, $row['tanggal_ttd_ppjb'] ?? null, $row['status'] ?? null);
            $record = ConsumerPpjbDeveloper::query()->create([
                'consumer_application_id' => $application->id, 'consumer_stage_event_id' => $event->id,
                'tanggal_sp3k' => $canonicalSp3k, 'tanggal_ttd_ppjb' => $this->date($row['tanggal_ttd_ppjb'] ?? null),
                'notes' => $row['keterangan'] ?? null, 'source_system' => MarisonV2PackageValidator::SOURCE_SYSTEM, 'source_branch_code' => $import->branch_code,
                'source_id' => $id, 'status' => $row['status'] ?? null, 'metadata' => $this->metadata($row),
            ]);
            $this->source($import, $application, 'ppjb_dev', $id, $row, $record);
        }
    }

    private function akad(ConsumerApplication $application, MarisonImportTransaction $import, array $rows): void
    {
        foreach ($rows as $i => $row) {
            $id = (string) ($row['no_ppjb_akad'] ?? 'akad-'.$i);
            $event = $this->event($application, $import, 'akad', $id, $row, $row['tanggal_akad'] ?? null, $row['status'] ?? null);
            $record = ConsumerAkadRecord::query()->create([
                'consumer_application_id' => $application->id, 'consumer_stage_event_id' => $event->id, 'source_system' => MarisonV2PackageValidator::SOURCE_SYSTEM, 'source_branch_code' => $import->branch_code, 'no_ppjb_akad' => $id,
                'tanggal_akad' => $this->date($row['tanggal_akad'] ?? null), 'kualitas_akad' => $row['kualitas_akad'] ?? null,
                'status_bangunan' => $row['progress_bangunan'] ?? $row['status_bangunan'] ?? null,
                'status_dp_konsumen' => $row['status_dp'] ?? $row['status_dp_konsumen'] ?? null, 'status_utilitas' => $row['status_utilitas'] ?? null,
                'status_konsumen' => $row['status'] ?? null, 'keterangan_terlambat' => $row['detail_kendala'] ?? $row['keterangan'] ?? null,
            ]);
            $this->source($import, $application, 'akad', $id, $row, $record);
        }
    }

    private function bast(ConsumerApplication $application, MarisonImportTransaction $import, array $rows): void
    {
        foreach ($rows as $i => $row) {
            $id = (string) ($row['no_bast'] ?? 'bast-'.$i);
            $event = $this->event($application, $import, 'bast', $id, $row, $row['tanggal_bast'] ?? null, $row['status'] ?? null);
            $record = ConsumerBastRecord::query()->create([
                'consumer_application_id' => $application->id, 'consumer_stage_event_id' => $event->id, 'source_system' => MarisonV2PackageValidator::SOURCE_SYSTEM, 'source_branch_code' => $import->branch_code, 'no_bast' => $id,
                'tanggal_bast' => $this->date($row['tanggal_bast'] ?? null), 'status' => $row['status'] ?? null, 'notes' => $row['keterangan'] ?? null,
            ]);
            $this->source($import, $application, 'bast', $id, $row, $record);
        }
    }

    private function history(ConsumerApplication $application, MarisonImportTransaction $import, array $history): void
    {
        foreach ($history['kavling'] as $i => $row) {
            $id = (string) ($row['event_id'] ?? 'kavling-'.$i);
            $this->event($application, $import, 'kavling_history', $id, $row, $row['event_timestamp'] ?? null, $row['event_type'] ?? null);
        }
        foreach ($history['status'] as $i => $row) {
            $id = (string) ($row['event_id'] ?? 'status-'.$i);
            $baseline = mb_strtoupper((string) ($row['event_type'] ?? $row['status'] ?? '')) === 'MIGRATION_BASELINE';
            $this->event($application, $import, $baseline ? 'migration_baseline' : 'status_history', $id, $row, $row['event_timestamp'] ?? null, $row['status'] ?? null);
        }
    }

    private function event(ConsumerApplication $application, MarisonImportTransaction $import, string $stage, string $id, array $row, mixed $date, mixed $status): ConsumerStageEvent
    {
        $event = ConsumerStageEvent::query()->create([
            'consumer_application_id' => $application->id, 'stage' => $stage, 'source_id' => mb_substr($id, 0, 255),
            'source' => $stage === 'status_history' || $stage === 'migration_baseline' ? 'marison_v2_history' : MarisonV2PackageValidator::SOURCE_SYSTEM,
            'event_date' => $this->date($date), 'occurred_at' => $this->dateTime($date), 'status' => is_scalar($status) ? (string) $status : null,
            'notes' => $row['keterangan'] ?? null, 'metadata' => $this->metadata($row),
        ]);
        $this->source($import, $application, $stage.'_event', $id, $row, $event);

        return $event;
    }

    private function source(MarisonImportTransaction $import, ConsumerApplication $application, string $type, string $id, array $row, object $target): void
    {
        ConsumerMigrationSourceRecord::query()->create([
            'import_transaction_id' => $import->id, 'consumer_application_id' => $application->id,
            'source_system' => MarisonV2PackageValidator::SOURCE_SYSTEM, 'branch_code' => $import->branch_code,
            'source_transaction_id' => $import->source_transaction_id, 'source_type' => $type, 'source_record_id' => mb_substr($id, 0, 150),
            'payload_hash' => $this->validator->hash($row), 'metadata' => $this->metadata($row), 'target_type' => $target::class, 'target_id' => $target->id,
        ]);
    }

    private function derivedStage(ConsumerApplication $application): ?string
    {
        $present = $application->stageEvents()->whereIn('stage', self::STAGE_ORDER)->pluck('stage');
        foreach (array_reverse(self::STAGE_ORDER) as $stage) {
            if ($present->contains($stage)) {
                return $stage;
            }
        }

        return null;
    }

    private function suggestedDecision(array $transaction): string
    {
        if ($transaction['process']['bast'] !== [] || $transaction['process']['akad'] !== []) {
            return 'SELESAI';
        }
        $latest = collect($transaction['history']['kavling'])->sortByDesc(fn ($row) => $row['event_timestamp'] ?? '')->first();
        if ($latest && str_contains(mb_strtoupper((string) ($latest['event_type'] ?? '')), 'PINDAH')) {
            return 'PINDAH_KAVLING';
        }
        $state = mb_strtoupper(implode(' ', array_filter($transaction['source_state'], 'is_scalar')));
        if (str_contains($state, 'MUNDUR')) {
            return 'MUNDUR';
        }
        if (str_contains($state, 'REJECT')) {
            return 'REJECT';
        }

        return 'LANJUT';
    }

    private function metadata(array $row): array
    {
        return $row;
    }

    private function date(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    private function dateTime(mixed $v): ?string
    {
        $d = $this->date($v);

        return $d ? $d.' 00:00:00' : (is_string($v) && strtotime($v) !== false ? date('Y-m-d H:i:s', strtotime($v)) : null);
    }

    private function decimal(mixed $v): ?string
    {
        return is_numeric($v) ? number_format((float) $v, 2, '.', '') : null;
    }

    private function integer(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}
