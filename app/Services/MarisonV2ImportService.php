<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ConsumerLegacyIdentity;
use App\Models\MarisonImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MarisonV2ImportService
{
    public function __construct(private readonly MarisonV2TransactionImporter $transactions) {}

    public function confirm(MarisonImportBatch $batch, User $actor, string $previewHash, int $previewVersion): array
    {
        $result = DB::transaction(function () use ($batch, $actor, $previewHash, $previewVersion): array {
            $locked = MarisonImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            Branch::query()->where('code', $locked->source_branch_code)->lockForUpdate()->firstOrFail();
            if ($locked->status !== MarisonV2ImportPreviewService::BATCH_STATUS_PREVIEW_READY || $locked->expires_at?->isPast()) {
                throw new ConflictHttpException('Preview impor tidak lagi dapat dikonfirmasi.');
            }
            if (! hash_equals((string) $locked->preview_hash, $previewHash) || $locked->preview_version !== $previewVersion) {
                throw new ConflictHttpException('Preview telah berubah. Muat ulang sebelum konfirmasi.');
            }
            $rows = $locked->transactions()->with(['batch', 'projectMapping.project', 'resolvedProject'])->lockForUpdate()->orderBy('id')->get();
            if ($rows->contains(fn ($row) => $row->outcome === MarisonV2ImportPreviewService::STATUS_BLOCKED)) {
                throw new ConflictHttpException('Paket memiliki transaksi BLOCKED dan tidak dapat dikonfirmasi.');
            }
            $created = $already = $review = 0;
            foreach ($rows as $row) {
                if ($row->projectMapping !== null && (int) $row->projectMapping->oasis_project_id !== (int) $row->resolved_project_id) {
                    throw new ConflictHttpException('Pemetaan proyek berubah setelah preview.');
                }
                if ($row->outcome === MarisonV2ImportPreviewService::STATUS_ALREADY_IMPORTED) {
                    $already++;

                    continue;
                }
                if ($row->outcome !== MarisonV2ImportPreviewService::STATUS_READY) {
                    $review++;

                    continue;
                }
                $existing = ConsumerLegacyIdentity::query()
                    ->where('legacy_source', MarisonV2PackageValidator::SOURCE_SYSTEM)
                    ->where('sheet_name', 'trx_penjualan')
                    ->where('external_key', $row->source_transaction_id)
                    ->whereHas('application', fn ($query) => $query->where('branch_id', $row->resolvedProject->branch_id))
                    ->lockForUpdate()->first();
                if ($existing !== null) {
                    if (! hash_equals((string) $existing->source_payload_hash, $row->payload_hash)) {
                        throw new ConflictHttpException('Sumber transaksi berubah setelah preview.');
                    }
                    $already++;

                    continue;
                }
                $this->transactions->import($row);
                $created++;
            }
            $locked->update(['status' => 'COMPLETED', 'confirmed_at' => now(), 'counts' => array_merge($locked->counts ?? [], ['confirmed' => compact('created', 'already', 'review')])]);
            ActivityLog::query()->create([
                'causer_id' => $actor->id, 'subject_type' => MarisonImportBatch::class, 'subject_id' => $locked->id,
                'event' => 'marison_import_confirmed', 'description' => 'Paket migrasi Marison V2 dikonfirmasi.',
                'properties' => ['batch_id' => $locked->public_id, 'branch_code' => $locked->source_branch_code, 'payload_hash' => $locked->payload_hash, 'created' => $created, 'already' => $already, 'review' => $review],
            ]);

            return ['batch' => $locked, 'created' => $created, 'already' => $already, 'review' => $review];
        });

        return $result;
    }
}
