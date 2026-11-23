<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchMigrationState;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BranchMigrationStateService
{
    public function recordFor(Branch|int $branch): BranchMigrationState
    {
        $branchId = $branch instanceof Branch ? $branch->getKey() : $branch;

        return BranchMigrationState::query()->firstOrNew(
            ['branch_id' => $branchId],
            ['state' => BranchMigrationState::STATE_SHEET],
        );
    }

    public function stateFor(Branch|int $branch): string
    {
        return $this->recordFor($branch)->state;
    }

    public function isOasisFull(Branch|int $branch): bool
    {
        return $this->stateFor($branch) === BranchMigrationState::STATE_OASIS_FULL;
    }

    public function allowsOasisConsumerInput(Branch|int $branch): bool
    {
        return in_array($this->stateFor($branch), [BranchMigrationState::STATE_PARALEL, BranchMigrationState::STATE_OASIS_FULL], true);
    }

    public function allowsSheetWrite(Branch|int $branch): bool
    {
        return in_array($this->stateFor($branch), [BranchMigrationState::STATE_SHEET, BranchMigrationState::STATE_MIGRASI], true);
    }

    public function allowsSheetSync(Branch|int $branch): bool
    {
        return ! $this->isOasisFull($branch);
    }

    public function assertOasisConsumerInputAllowed(Branch|int $branch): void
    {
        if ($this->allowsOasisConsumerInput($branch)) {
            return;
        }

        throw new AuthorizationException('Cabang ini masih memakai spreadsheet untuk proses penjualan.');
    }

    public function assertSheetWriteAllowed(Branch|int $branch): void
    {
        if ($this->allowsSheetWrite($branch)) {
            return;
        }

        throw new AuthorizationException('Input cabang ini sudah diarahkan ke OASIS; perubahan spreadsheet dikunci.');
    }

    public function assertWritable(Branch|int $branch): void
    {
        $this->assertSheetWriteAllowed($branch);
    }

    public function logSkip(Branch $branch, string $syncType): void
    {
        $key = 'branch-migration-skip:'.$branch->id.':'.$syncType.':'.now()->toDateString();
        if (Cache::add($key, true, now()->endOfDay())) {
            Log::info('Sinkronisasi cabang dilewati karena status oasis_penuh.', [
                'branch_id' => $branch->id,
                'branch_name' => $branch->name,
                'sync_type' => $syncType,
                'state' => BranchMigrationState::STATE_OASIS_FULL,
                'reason' => 'oasis_penuh',
            ]);
        }
    }

    public function transition(User $actor, Branch $branch, string $expectedState, string $targetState, string $reason): BranchMigrationState
    {
        if (! $actor->isSuperadmin() || ! $actor->isAccountActive() || ! $actor->is_active) {
            throw new AuthorizationException('Hanya Superadmin aktif yang dapat mengubah status migrasi cabang.');
        }

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Alasan wajib diisi dan maksimal 1000 karakter.']);
        }
        if (! in_array($expectedState, BranchMigrationState::STATES, true) || ! in_array($targetState, BranchMigrationState::STATES, true)) {
            throw ValidationException::withMessages(['state' => 'Status migrasi tidak valid.']);
        }

        return DB::transaction(function () use ($actor, $branch, $expectedState, $targetState, $reason): BranchMigrationState {
            Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $state = BranchMigrationState::query()->where('branch_id', $branch->id)->lockForUpdate()->first();
            if ($state === null) {
                $state = BranchMigrationState::query()->create(['branch_id' => $branch->id, 'state' => BranchMigrationState::STATE_SHEET]);
            }
            if ($state->state !== $expectedState) {
                throw ValidationException::withMessages(['state' => 'Status cabang sudah berubah. Muat ulang halaman.']);
            }
            $fromIndex = array_search($state->state, BranchMigrationState::STATES, true);
            $targetIndex = array_search($targetState, BranchMigrationState::STATES, true);
            if ($targetIndex === $fromIndex || abs($targetIndex - $fromIndex) !== 1) {
                throw ValidationException::withMessages(['state' => 'Perubahan status hanya boleh satu tahap maju atau mundur.']);
            }

            $changedAt = now();
            $fromState = $state->state;
            $state->update(['state' => $targetState, 'changed_by' => $actor->id, 'changed_at' => $changedAt, 'notes' => $reason]);
            ActivityLog::query()->create([
                'causer_id' => $actor->id,
                'subject_type' => Branch::class,
                'subject_id' => $branch->id,
                'event' => 'branch_migration_state_changed',
                'description' => 'Status migrasi cabang diubah.',
                'properties' => [
                    'branch_id' => $branch->id,
                    'from_state' => $fromState,
                    'to_state' => $targetState,
                    'reason' => $reason,
                    'actor_id' => $actor->id,
                    'changed_at' => $changedAt->toIso8601String(),
                ],
            ]);

            return $state->fresh();
        });
    }
}
