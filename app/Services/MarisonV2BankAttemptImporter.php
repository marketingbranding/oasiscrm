<?php

namespace App\Services;

use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerMigrationSourceRecord;
use App\Models\ConsumerStageEvent;
use App\Models\MarisonImportTransaction;

final class MarisonV2BankAttemptImporter
{
    public function __construct(private readonly MarisonV2PackageValidator $validator) {}

    public function import(ConsumerApplication $application, MarisonImportTransaction $importTransaction, array $transaction): array
    {
        $attempts = [];
        foreach ($transaction['process']['bank_attempts'] as $index => $row) {
            $key = $this->attemptKey($transaction['id_transaksi_v2'], $row, $index + 1);
            $attempts[$key] = $this->upsertAttempt($application, $importTransaction, $transaction, $row, $key);
        }
        foreach ($transaction['process']['pemberkasan'] as $index => $row) {
            $key = $this->matchKey($transaction['id_transaksi_v2'], $row, $attempts, $index + 1);
            $attempts[$key] = $this->upsertAttempt($application, $importTransaction, $transaction, $row, $key, 'pemberkasan');
            $this->event($application, $importTransaction, 'pemberkasan', (string) ($row['id_berkas'] ?? $key), $row, $this->date($row['tanggal_terima_bank'] ?? null));
        }
        foreach ($transaction['process']['proses_bank'] as $index => $row) {
            $key = $this->matchKey($transaction['id_transaksi_v2'], $row, $attempts, $index + 1);
            $attempts[$key] = $this->upsertAttempt($application, $importTransaction, $transaction, $row, $key, 'proses_bank');
            $this->event($application, $importTransaction, 'proses_bank', (string) ($row['no_sp3k'] ?? $row['id_bank_attempt'] ?? $key), $row, $this->date($row['tanggal_sp3k'] ?? null));
        }

        return array_values($attempts);
    }

    private function upsertAttempt(ConsumerApplication $application, MarisonImportTransaction $importTransaction, array $transaction, array $row, string $key, string $sourceType = 'bank_attempt'): ConsumerBankProcess
    {
        $sourceId = mb_substr($key, 0, 255);
        $record = ConsumerBankProcess::query()->firstOrNew([
            'consumer_application_id' => $application->id,
            'source' => MarisonV2PackageValidator::SOURCE_SYSTEM,
            'source_id' => $sourceId,
        ]);
        if (! $record->exists) {
            $record->fill(['metadata' => ['source_transaction_id' => $transaction['id_transaksi_v2'], 'id_bank_attempt' => $row['id_bank_attempt'] ?? null, 'attempt_no' => $row['attempt_no'] ?? null]]);
        }
        $attributes = array_filter([
            'id_berkas' => $row['id_berkas'] ?? null,
            'tanggal_terima_bank' => $this->date($row['tanggal_terima_bank'] ?? null),
            'bank_name' => $row['bank'] ?? $row['bank_name'] ?? null,
            'kc_unit' => $row['kc/unit'] ?? $row['kc_unit'] ?? null,
            'tipe_pemberkasan' => $row['tipe_pemberkasan'] ?? null,
            'request_plafond' => $this->decimal($row['request_plafond'] ?? null),
            'request_tenor' => $this->integer($row['request_tenor'] ?? null),
            'submitted_at' => $this->dateTime($row['submitted_at'] ?? $row['tanggal_terima_bank'] ?? null),
            'status' => $row['status'] ?? null,
            'notes' => $row['keterangan'] ?? $row['notes'] ?? null,
        ], fn ($value) => $value !== null);
        if ($sourceType === 'proses_bank') {
            $attributes = array_merge($attributes, array_filter([
                'no_sp3k' => $row['no_sp3k'] ?? null,
                'sp3k_at' => $this->dateTime($row['tanggal_sp3k'] ?? null),
                'response_type' => $row['jenis_respon'] ?? $row['response_type'] ?? null,
                'approved_plafond' => $this->decimal($row['approved_plafond'] ?? null),
                'approved_tenor' => $this->integer($row['approved_tenor'] ?? null),
                'revision_category' => $row['kategori_revisi'] ?? null,
                'revision_detail' => $row['detail_revisi'] ?? null,
                'obstacle' => $row['kendala'] ?? null,
            ], fn ($value) => $value !== null));
        }
        $record->fill($attributes)->save();
        $this->sourceRecord($importTransaction, $application, $sourceType.'_bank', $key, $row, $record);

        return $record;
    }

    private function attemptKey(string $transactionId, array $row, int $fallback): string
    {
        if (filled($row['id_bank_attempt'] ?? null)) {
            return (string) $row['id_bank_attempt'];
        }

        return $transactionId.':'.($row['id_berkas'] ?? 'NO-BERKAS').':'.($row['attempt_no'] ?? $fallback);
    }

    private function matchKey(string $transactionId, array $row, array $attempts, int $fallback): string
    {
        if (filled($row['id_bank_attempt'] ?? null)) {
            return (string) $row['id_bank_attempt'];
        }
        if (filled($row['id_berkas'] ?? null)) {
            foreach ($attempts as $key => $attempt) {
                if ((string) $attempt->id_berkas === (string) $row['id_berkas']) {
                    return $key;
                }
            }
        }

        return $this->attemptKey($transactionId, $row, $fallback);
    }

    private function event(ConsumerApplication $application, MarisonImportTransaction $importTransaction, string $stage, string $sourceId, array $row, ?string $date): void
    {
        $event = ConsumerStageEvent::query()->firstOrCreate([
            'consumer_application_id' => $application->id,
            'source' => MarisonV2PackageValidator::SOURCE_SYSTEM,
            'stage' => $stage,
            'source_id' => $sourceId,
        ], [
            'event_date' => $date,
            'occurred_at' => $this->dateTime($date),
            'status' => $row['status'] ?? $row['jenis_respon'] ?? null,
            'notes' => $row['keterangan'] ?? null,
            'metadata' => $this->metadata($row),
        ]);
        $this->sourceRecord($importTransaction, $application, $stage, $sourceId, $row, $event);
    }

    private function sourceRecord(MarisonImportTransaction $importTransaction, ConsumerApplication $application, string $type, string $id, array $row, object $target): void
    {
        ConsumerMigrationSourceRecord::query()->firstOrCreate([
            'source_system' => MarisonV2PackageValidator::SOURCE_SYSTEM,
            'branch_code' => $importTransaction->branch_code,
            'source_type' => $type,
            'source_record_id' => mb_substr($id, 0, 150),
        ], [
            'import_transaction_id' => $importTransaction->id,
            'consumer_application_id' => $application->id,
            'source_transaction_id' => $importTransaction->source_transaction_id,
            'payload_hash' => $this->validator->hash($row),
            'metadata' => $this->metadata($row),
            'target_type' => $target::class,
            'target_id' => $target->id,
        ]);
    }

    private function metadata(array $row): array
    {
        return array_filter($row, fn ($value, $key) => ! in_array($key, ['nama_konsumen', 'nik', 'no_ktp', 'no_hp', 'alamat'], true), ARRAY_FILTER_USE_BOTH);
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function dateTime(mixed $value): ?string
    {
        $date = $this->date($value);

        return $date ? $date.' 00:00:00' : (is_string($value) && strtotime($value) !== false ? date('Y-m-d H:i:s', strtotime($value)) : null);
    }

    private function decimal(mixed $value): ?string
    {
        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
