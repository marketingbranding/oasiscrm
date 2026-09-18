<?php

namespace App\Services;

use App\Models\ConsumerLegacyIdentity;
use App\Models\Kavling;
use App\Models\MarisonImportBatch;
use App\Models\MarisonImportTransaction;
use App\Models\MarisonUnlinkedRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class MarisonV2ImportPreviewService
{
    public const STATUS_READY = 'READY';

    public const STATUS_ALREADY_IMPORTED = 'ALREADY_IMPORTED';

    public const STATUS_SOURCE_CHANGED_REVIEW = 'SOURCE_CHANGED_REVIEW';

    public const STATUS_NEEDS_REVIEW = 'NEEDS_REVIEW';

    public const STATUS_BLOCKED = 'BLOCKED';

    public const BATCH_STATUS_PREVIEW_READY = 'PREVIEW_READY';

    public const PREVIEW_VERSION = '1';

    public function __construct(
        private readonly MarisonV2PackageValidator $validator,
        private readonly MarisonV2ProjectResolver $projects,
    ) {}

    public function stage(UploadedFile|string $file, User $actor, ?string $originalFilename = null): MarisonImportBatch
    {
        $validated = $this->validator->validate($file);
        $package = $validated['package'];

        return DB::transaction(function () use ($actor, $package, $validated) {
            $rows = [];
            foreach ($package['transactions'] as $transaction) {
                $rows[] = $this->previewTransaction($package, $transaction);
            }

            $counts = array_fill_keys([
                self::STATUS_READY,
                self::STATUS_ALREADY_IMPORTED,
                self::STATUS_SOURCE_CHANGED_REVIEW,
                self::STATUS_NEEDS_REVIEW,
                self::STATUS_BLOCKED,
            ], 0);
            foreach ($rows as $row) {
                $counts[$row['outcome']]++;
            }

            $previewHash = $this->validator->hash([
                'preview_version' => self::PREVIEW_VERSION,
                'package_hash' => $validated['package_hash'],
                'rows' => array_map(fn (array $row) => [
                    'source_transaction_id' => $row['source_transaction_id'],
                    'payload_hash' => $row['payload_hash'],
                    'branch_code' => $row['branch_code'],
                    'project_mapping_id' => $row['project_mapping_id'],
                    'resolved_project_id' => $row['resolved_project_id'],
                    'outcome' => $row['outcome'],
                    'errors' => $row['errors'],
                ], $rows),
                'unlinked' => array_map(fn (array $record) => $this->unlinkedMetadata($record), $package['unlinked_records']),
            ]);

            $batch = MarisonImportBatch::query()->create([
                'public_id' => (string) str()->uuid(),
                'uploaded_by' => $actor->id,
                'status' => self::BATCH_STATUS_PREVIEW_READY,
                'source_system' => MarisonV2PackageValidator::SOURCE_SYSTEM,
                'source_branch_code' => $package['source']['branch_code'],
                'source_spreadsheet_id' => $package['source']['spreadsheet_id'],
                'source_version' => MarisonV2PackageValidator::SOURCE_VERSION,
                'source_exported_at' => $package['source']['exported_at'],
                'contract_version' => MarisonV2PackageValidator::CONTRACT_VERSION,
                'payload_hash' => $validated['package_hash'],
                'preview_version' => (int) self::PREVIEW_VERSION,
                'preview_hash' => $previewHash,
                'expires_at' => now()->addHour(),
                'counts' => [
                    'manifest' => $package['manifest']['counts'],
                    'outcomes' => $counts,
                    'transactions' => count($rows),
                    'unlinked_records' => count($package['unlinked_records']),
                ],
            ]);

            foreach ($rows as $row) {
                MarisonImportTransaction::query()->create(['batch_id' => $batch->id, ...$row]);
            }
            foreach ($package['unlinked_records'] as $record) {
                MarisonUnlinkedRecord::query()->create([
                    'batch_id' => $batch->id,
                    'source_sheet' => $record['source_sheet'],
                    'source_row' => $record['source_row'] ?? null,
                    'source_id' => $this->sourceId($record['payload']),
                    'reason' => $record['reason'],
                    'payload' => $record['payload'],
                    'payload_hash' => $this->validator->hash($record['payload']),
                ]);
            }

            return $batch->fresh();
        });
    }

    private function previewTransaction(array $package, array $transaction): array
    {
        $resolution = $this->projects->resolve($transaction['branch_code'], $transaction['project_source_id']);
        $payloadHash = $this->validator->hash($transaction);
        $identity = ConsumerLegacyIdentity::query()
            ->where('legacy_source', MarisonV2PackageValidator::SOURCE_SYSTEM)
            ->where('sheet_name', 'trx_penjualan')
            ->where('external_key', $transaction['id_transaksi_v2'])
            ->whereHas('application.branch', fn ($query) => $query->where('code', $transaction['branch_code']))
            ->first();
        $issues = [];

        if ($resolution['error'] !== null) {
            $issues[] = $resolution['error'];
            $outcome = self::STATUS_BLOCKED;
        } elseif ($identity !== null) {
            $outcome = hash_equals((string) $identity->source_payload_hash, $payloadHash)
                ? self::STATUS_ALREADY_IMPORTED
                : self::STATUS_SOURCE_CHANGED_REVIEW;
        } else {
            $reviewReasons = $this->reviewReasons($transaction, $resolution['project']?->id);
            $issues = [...$issues, ...$reviewReasons];
            $outcome = $reviewReasons === [] ? self::STATUS_READY : self::STATUS_NEEDS_REVIEW;
        }

        return [
            'source_system' => MarisonV2PackageValidator::SOURCE_SYSTEM,
            'branch_code' => $transaction['branch_code'],
            'source_transaction_id' => $transaction['id_transaksi_v2'],
            'source_customer_ref' => $transaction['id_konsumen_v2'],
            'source_project_id' => $transaction['project_source_id'],
            'project_mapping_id' => $resolution['mapping']?->id,
            'resolved_project_id' => $resolution['project']?->id,
            'payload_hash' => $payloadHash,
            'outcome' => $outcome,
            'errors' => $issues,
            'payload' => $transaction,
        ];
    }

    private function reviewReasons(array $transaction, ?int $projectId): array
    {
        $reasons = [];
        if ($projectId !== null && filled($transaction['current_kavling'])) {
            $matches = Kavling::query()->where('project_id', $projectId)
                ->where(fn ($query) => $query->where('name', $transaction['current_kavling'])->orWhere('kavling_code', $transaction['current_kavling']))
                ->count();
            if ($matches !== 1) {
                $reasons[] = $matches === 0 ? 'Kavling sumber tidak ditemukan secara tepat di proyek.' : 'Kavling sumber ambigu di proyek.';
            }
        }
        if (mb_strtoupper((string) data_get($transaction, 'lineage.confidence', '')) === 'REVIEW'
            || mb_strtoupper((string) data_get($transaction, 'lineage.lineage_confidence', '')) === 'REVIEW') {
            $reasons[] = 'Lineage sumber memerlukan review.';
        }
        if ($transaction['current_kavling'] === null || trim($transaction['current_kavling']) === '') {
            $reasons[] = 'Kavling sumber belum tersedia.';
        }
        if (data_get($transaction, 'source_state.conflicting') === true
            || data_get($transaction, 'source_state.has_conflict') === true) {
            $reasons[] = 'Status sumber saling bertentangan.';
        }
        if (data_get($transaction, 'lineage.movement_ambiguous') === true) {
            $reasons[] = 'Riwayat perpindahan kavling ambigu.';
        }
        if (data_get($transaction, 'lineage.chronology_anomaly') === true) {
            $reasons[] = 'Kronologi proses sumber perlu diperiksa.';
        }

        return $reasons;
    }

    private function sourceId(array $payload): ?string
    {
        foreach (['id_bank_attempt', 'id_berkas', 'id_kons', 'id_psjb', 'id_ppjb_dev', 'no_sp3k', 'no_ppjb_akad', 'no_bast', 'event_id'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
                return mb_substr(trim((string) $payload[$key]), 0, 150);
            }
        }

        return null;
    }

    private function unlinkedMetadata(array $record): array
    {
        $payload = $record['payload'];

        return [
            'keys' => array_values(array_map('strval', array_keys($payload))),
            'value_types' => array_map(fn ($value) => get_debug_type($value), $payload),
            'field_count' => count($payload),
        ];
    }
}
