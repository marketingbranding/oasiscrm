<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserImportBatch;
use App\Models\UserImportRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportingHierarchyService
{
    private const ROLE_RANKS = [
        'sales' => 10,
        'staff' => 10,
        'sales_coordinator' => 20,
        'supervisor' => 30,
        'admin' => 30,
        'manager' => 40,
        'branch_manager' => 50,
        'pusat' => 90,
        'superadmin' => 100,
    ];

    public function __construct(
        private WorkspaceAccessService $workspaceAccess,
        private AccountAuditService $audit,
    ) {}

    public function roleRank(User|string $userOrRole): int
    {
        $slug = $userOrRole instanceof User ? $userOrRole->role?->slug : $userOrRole;

        return self::ROLE_RANKS[$slug] ?? 0;
    }

    public function assignSupervisor(User $user, User|int|null $supervisor, ?User $actor = null): User
    {
        if (is_int($supervisor)) {
            $supervisor = User::find($supervisor);
            if ($supervisor === null) {
                throw ValidationException::withMessages(['supervisor_user_id' => 'Atasan yang dipilih tidak ditemukan.']);
            }
        }
        if ($supervisor === null) {
            return $this->persist($user, null, $actor);
        }

        if (! $supervisor->is_active || ! $supervisor->isAccountActive()) {
            throw ValidationException::withMessages(['supervisor_user_id' => 'Atasan harus merupakan pengguna aktif.']);
        }
        if ($supervisor->is($user)) {
            throw ValidationException::withMessages(['supervisor_user_id' => 'Pengguna tidak dapat menjadi atasan dirinya sendiri.']);
        }
        if ($this->roleRank($supervisor) < $this->roleRank($user)) {
            throw ValidationException::withMessages(['supervisor_user_id' => 'Atasan harus memiliki tingkat kewenangan yang setara atau lebih tinggi.']);
        }
        $isGlobalSupervisor = $supervisor->isSuperadmin()
            || ($supervisor->hasPrimaryRole('pusat') && $supervisor->canViewAllBranches());
        if (! $isGlobalSupervisor && ! $this->sharesAuthorization($user, $supervisor)) {
            throw ValidationException::withMessages(['supervisor_user_id' => 'Atasan harus berbagi cabang atau proyek yang berwenang dengan pengguna.']);
        }

        $this->assertNoCycle($user, $supervisor);

        return $this->persist($user, $supervisor, $actor);
    }

    public function assignOnboardingSupervisor(User $user, User $supervisor, UserImportBatch $batch, User $actor, int $rowId): User
    {
        $sameBatchUserIds = UserImportRow::query()->where('batch_id', $batch->id)
            ->whereNotNull('created_user_id')->pluck('created_user_id')->map(fn ($id) => (int) $id)->all();
        if (! in_array((int) $user->id, $sameBatchUserIds, true) || ! in_array((int) $supervisor->id, $sameBatchUserIds, true)) {
            throw ValidationException::withMessages(['supervisor_user_id' => 'Atasan onboarding harus berasal dari batch yang sama.']);
        }
        if (! in_array($supervisor->account_status->value, ['pending_invitation', 'invited'], true)) {
            throw ValidationException::withMessages(['supervisor_user_id' => 'Atasan onboarding tidak lagi memenuhi status yang diizinkan.']);
        }
        if ($supervisor->is($user)) {
            throw ValidationException::withMessages(['supervisor_user_id' => 'Pengguna tidak dapat menjadi atasan dirinya sendiri.']);
        }
        if ($this->roleRank($supervisor) < $this->roleRank($user)) {
            throw ValidationException::withMessages(['supervisor_user_id' => 'Atasan harus memiliki tingkat kewenangan yang setara atau lebih tinggi.']);
        }
        $isGlobalSupervisor = $supervisor->isSuperadmin()
            || ($supervisor->hasPrimaryRole('pusat') && $supervisor->canViewAllBranches());
        if (! $isGlobalSupervisor && ! $this->sharesAuthorization($user, $supervisor)) {
            throw ValidationException::withMessages(['supervisor_user_id' => 'Atasan harus berbagi cabang atau proyek yang berwenang dengan pengguna.']);
        }
        $this->assertNoCycle($user, $supervisor);

        $user->forceFill(['supervisor_user_id' => $supervisor->id])->save();
        $this->audit->logBulkUser('user_supervisor_linked_bulk', $user, $actor, $batch, $rowId);

        return $user->refresh();
    }

    /** @return array<int> */
    public function descendantIds(User $supervisor, int $depthLimit = 100): array
    {
        $visited = [(int) $supervisor->id => true];
        $frontier = [(int) $supervisor->id];
        $descendants = [];

        for ($depth = 0; $frontier !== [] && $depth < $depthLimit; $depth++) {
            $next = User::query()->whereIn('supervisor_user_id', $frontier)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $frontier = [];
            foreach ($next as $id) {
                if (isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;
                $descendants[] = $id;
                $frontier[] = $id;
            }
        }

        return $descendants;
    }

    private function assertNoCycle(User $user, User $supervisor): void
    {
        $visited = [];
        $currentId = (int) $supervisor->id;
        while ($currentId > 0) {
            if ($currentId === (int) $user->id || isset($visited[$currentId])) {
                throw ValidationException::withMessages(['supervisor_user_id' => 'Penugasan atasan akan membentuk siklus pelaporan.']);
            }
            $visited[$currentId] = true;
            $currentId = (int) (User::query()->whereKey($currentId)->value('supervisor_user_id') ?? 0);
        }
    }

    private function sharesAuthorization(User $user, User $supervisor): bool
    {
        if (array_intersect(
            $this->workspaceAccess->accessibleBranchIds($user),
            $this->workspaceAccess->accessibleBranchIds($supervisor),
        ) !== []) {
            return true;
        }

        return array_intersect($this->currentProjectIds($user), $this->currentProjectIds($supervisor)) !== [];
    }

    private function currentProjectIds(User $user): array
    {
        $today = today()->toDateString();

        return DB::table('project_user')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('assignment_start_date')->orWhereDate('assignment_start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('assignment_end_date')->orWhereDate('assignment_end_date', '>=', $today))
            ->pluck('project_id')->map(fn ($id) => (int) $id)->all();
    }

    private function persist(User $user, ?User $supervisor, ?User $actor): User
    {
        return DB::transaction(function () use ($user, $supervisor, $actor) {
            $old = ['supervisor_user_id' => $user->supervisor_user_id];
            $user->forceFill(['supervisor_user_id' => $supervisor?->id])->save();
            $this->audit->log('supervisor_assignment_changed', $user, $actor, $old, [
                'supervisor_user_id' => $supervisor?->id,
            ]);

            return $user->refresh();
        });
    }
}
