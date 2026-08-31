<?php

namespace App\Services;

use App\Models\DanaTalangan;
use App\Models\LeadMaster;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class DanaTalanganService
{
    public function __construct(private readonly DanaTalanganBridgeModeService $modes) {}

    public function resolveProject(string $projectName, int $branchId): ?LeadMaster
    {
        $projects = LeadMaster::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (LeadMaster $project) => $project->project_name === $projectName || $project->sheet_project_name === $projectName);

        return $projects->count() === 1 ? $projects->first() : null;
    }

    public function create(array $data, User $actor): DanaTalangan
    {
        $record = DB::transaction(function () use ($data, $actor): DanaTalangan {
            $record = DanaTalangan::query()->create($data + [
                'created_by' => $actor->id,
                'sync_status' => $this->modes->isPushEnabled() ? 'pending_create' : 'local',
            ]);
            $this->afterCommitPush($record->id, $actor->id);

            return $record;
        });

        return $record->fresh();
    }

    public function updated(DanaTalangan $record, User $actor): void
    {
        if (! in_array($record->sync_status, ['conflict', 'pending_delete'], true)) {
            $record->update(['sync_status' => ! $this->modes->isPushEnabled() ? 'local' : ($record->last_synced_at ? 'pending_update' : 'pending_create')]);
        }
        $this->afterCommitPush($record->id, $actor->id);
    }

    public function retry(DanaTalangan $record, User $actor): array
    {
        if (! $this->modes->isPushEnabled()) {
            return ['ok' => false, 'status' => 'disabled'];
        }

        return app(DanaTalanganBridgeService::class)->push($record, $actor);
    }

    public function delete(DanaTalangan $record, User $actor): void
    {
        $snapshot = DB::transaction(function () use ($record): array {
            $locked = DanaTalangan::query()->lockForUpdate()->findOrFail($record->id);
            $delivered = $locked->remote_target_spreadsheet_id !== null || $locked->delivery_attempted_at !== null || $locked->last_synced_at !== null;
            if (! $delivered) {
                $locked->delete();

                return ['deleted' => true];
            }
            $locked->update(['sync_status' => 'pending_delete', 'delete_pending_at' => now()]);

            return ['deleted' => false, 'payload_hash' => $this->localPayloadHash($locked)];
        });
        if ($snapshot['deleted']) {
            return;
        }

        try {
            app(DanaTalanganBridgeService::class)->tombstone($record->fresh(), $actor);
        } catch (Throwable $exception) {
            DanaTalangan::query()->whereKey($record->id)->update(['sync_status' => 'pending_delete', 'last_sync_error' => 'Penghapusan remote gagal.']);
            throw new \DomainException('Data remote belum aman dihapus.', previous: $exception);
        }

        DB::transaction(function () use ($record, $snapshot): void {
            $locked = DanaTalangan::query()->lockForUpdate()->findOrFail($record->id);
            if (! hash_equals($snapshot['payload_hash'], $this->localPayloadHash($locked))) {
                $locked->update(['sync_status' => 'pending_delete', 'last_sync_error' => 'Data berubah saat penghapusan remote.']);
                throw new \DomainException('Data berubah saat penghapusan remote.');
            }
            $locked->delete();
        });
    }

    private function localPayloadHash(DanaTalangan $record): string
    {
        return hash('sha256', json_encode([
            'tanggal' => $record->tanggal?->format('Y-m-d'),
            'nama_konsumen' => $record->nama_konsumen,
            'kav' => $record->kav,
            'project_name' => $record->project_name,
            'pinjam_nama' => (bool) $record->pinjam_nama,
            'pekerjaan' => $record->pekerjaan,
            'status_perkawinan' => $record->status_perkawinan,
            'umur' => $record->umur,
            'nama_marketing' => $record->nama_marketing,
            'tgl_komitmen' => $record->tgl_komitmen?->format('Y-m-d'),
            'penyelesaian' => $record->penyelesaian,
            'konfirmasi_keuangan' => (bool) $record->konfirmasi_keuangan,
            'status' => $record->status,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function afterCommitPush(int $recordId, int $actorId): void
    {
        DB::afterCommit(function () use ($recordId, $actorId): void {
            if (! $this->modes->isPushEnabled()) {
                return;
            }
            $record = DanaTalangan::query()->find($recordId);
            $actor = User::query()->find($actorId);
            if ($record !== null) {
                try {
                    app(DanaTalanganBridgeService::class)->push($record, $actor);
                } catch (Throwable $exception) {
                    report($exception);
                    DanaTalangan::query()->whereKey($record->id)->update([
                        'sync_status' => 'sync_failed',
                        'last_sync_error' => 'Sinkronisasi spreadsheet gagal.',
                        'delivery_attempted_at' => now(),
                    ]);
                }
            }
        });
    }
}
