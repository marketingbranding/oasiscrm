<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exceptions\OrganizationAssignmentConflictException;
use App\Models\OrganizationAssignment;
use App\Models\OrganizationUnit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrganizationGraphService
{
    public const REPORTS_TO = OrganizationAssignment::REPORTS_TO;

    public const SALES_TEAM_MEMBER_OF = OrganizationAssignment::SALES_TEAM_MEMBER_OF;

    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly AccountAuditService $audit,
    ) {}

    public function currentParent(User $user): ?User
    {
        return $this->currentAssignment($user)?->parent()->first();
    }

    public function currentAssignment(User $user): ?OrganizationAssignment
    {
        return OrganizationAssignment::query()
            ->where('user_id', $user->id)
            ->current()
            ->with(['parent.role', 'user.role'])
            ->first();
    }

    public function currentSalesTeamAssignment(User $sales): ?OrganizationAssignment
    {
        $assignments = $this->currentSalesTeamAssignmentsForUser($sales);

        return $assignments->count() === 1 ? $assignments->first() : null;
    }

    /** @return Collection<int, OrganizationAssignment> */
    public function currentSalesTeamAssignments(User $coordinator, bool $includeHistorical = false): Collection
    {
        $query = OrganizationAssignment::query()
            ->salesTeamMemberOf()
            ->where('parent_user_id', $coordinator->id)
            ->with(['parent.role', 'parent', 'user.role', 'user']);

        if ($includeHistorical) {
            $query->whereDate('started_at', '<=', today());
        } else {
            $query->currentSalesTeam();
        }

        $assignments = $query->get();
        if ($includeHistorical) {
            return $assignments;
        }

        $ambiguousUserIds = OrganizationAssignment::query()
            ->currentSalesTeam()
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $assignments
            ->groupBy('user_id')
            ->filter(fn (Collection $rows): bool => $rows->count() === 1
                && ! in_array((int) $rows->first()->user_id, $ambiguousUserIds, true))
            ->flatten(1)
            ->filter(fn (OrganizationAssignment $assignment): bool => $this->isValidCurrentSalesTeamAssignment($assignment))
            ->values();
    }

    /** @return Collection<int, OrganizationAssignment> */
    private function currentSalesTeamAssignmentsForUser(User $sales): Collection
    {
        $assignments = OrganizationAssignment::query()
            ->where('user_id', $sales->id)
            ->currentSalesTeam()
            ->with(['parent.role', 'parent', 'user.role', 'user'])
            ->get();

        return $assignments->count() === 1
            ? $assignments->filter(fn (OrganizationAssignment $assignment): bool => $this->isValidCurrentSalesTeamAssignment($assignment))
            : collect();
    }

    private function isValidCurrentSalesTeamAssignment(OrganizationAssignment $assignment): bool
    {
        $sales = $assignment->user;
        $coordinator = $assignment->parent;

        return $sales instanceof User
            && $coordinator instanceof User
            && $sales->hasPrimaryRole('sales')
            && $coordinator->hasPrimaryRole('sales_coordinator')
            && $sales->isAccountActive()
            && $coordinator->isAccountActive()
            && $sales->is_active
            && $coordinator->is_active
            && $sales->branch_id !== null
            && $sales->branch_id === $coordinator->branch_id
            && ($assignment->branch_id === null || (int) $assignment->branch_id === (int) $sales->branch_id);
    }

    public function currentSalesTeamCoordinator(User $sales): ?User
    {
        return $this->currentSalesTeamAssignment($sales)?->parent()->first();
    }

    /** @return array<int> */
    public function salesTeamMemberIds(User $coordinator, bool $includeHistorical = false): array
    {
        $ids = $this->currentSalesTeamAssignments($coordinator, $includeHistorical)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $ids;
    }

    public function validateSalesTeamMember(User $sales, User $coordinator, bool $allowPendingSales = false): void
    {
        if ($allowPendingSales && in_array($sales->account_status?->value, [AccountStatus::PendingInvitation->value, AccountStatus::Invited->value], true)) {
            if ($sales->is($coordinator)) {
                throw ValidationException::withMessages(['parent_user_id' => 'Pengguna tidak dapat menjadi Coordinator dirinya sendiri.']);
            }
            if (! $coordinator->isAccountActive() || ! $coordinator->is_active) {
                throw ValidationException::withMessages(['parent_user_id' => 'Coordinator harus merupakan pengguna aktif.']);
            }
        } else {
            $this->assertTargetAndParent($sales, $coordinator);
        }

        if (! $sales->hasPrimaryRole('sales')) {
            throw ValidationException::withMessages(['user' => 'Anggota Sales harus memiliki primary role sales.']);
        }
        if (! $coordinator->hasPrimaryRole('sales_coordinator')) {
            throw ValidationException::withMessages(['parent_user_id' => 'Target harus memiliki primary role sales_coordinator.']);
        }
        if (blank($sales->branch_id) || blank($coordinator->branch_id) || (int) $sales->branch_id !== (int) $coordinator->branch_id) {
            throw ValidationException::withMessages(['parent_user_id' => 'Sales dan Koordinator harus berada pada cabang utama yang sama.']);
        }
    }

    public function assignSalesTeamMember(
        User $sales,
        User $coordinator,
        ?User $actor = null,
        ?CarbonInterface $effectiveDate = null,
        ?string $source = null,
        ?string $reason = null,
    ): ?OrganizationAssignment {
        $effectiveDate = CarbonImmutable::instance($effectiveDate ?? today())->startOfDay();
        if (! $effectiveDate->isToday()) {
            throw ValidationException::withMessages(['effective_date' => 'Perubahan struktur harus menggunakan tanggal hari ini.']);
        }

        $allowPendingSales = $source === 'legacy_sales_coordinator';
        $this->validateSalesTeamMember($sales, $coordinator, $allowPendingSales);

        return DB::transaction(function () use ($actor, $allowPendingSales, $coordinator, $effectiveDate, $reason, $sales, $source): ?OrganizationAssignment {
            $lockedSales = User::query()->with('role')->lockForUpdate()->findOrFail($sales->id);
            $lockedCoordinator = User::query()->with('role')->lockForUpdate()->findOrFail($coordinator->id);
            $this->validateSalesTeamMember($lockedSales, $lockedCoordinator, $allowPendingSales);

            $currentAssignments = OrganizationAssignment::query()
                ->where('user_id', $lockedSales->id)
                ->salesTeamMemberOf()
                ->where('is_active', true)
                ->where('is_primary', true)
                ->lockForUpdate()
                ->get();
            if ($currentAssignments->count() > 1) {
                throw ValidationException::withMessages(['organization' => 'Terdapat lebih dari satu assignment Sales-team aktif.']);
            }
            $previous = $currentAssignments->first();
            $same = $currentAssignments->firstWhere('parent_user_id', $lockedCoordinator->id);

            $currentAssignments->reject(fn (OrganizationAssignment $assignment): bool => $same?->is($assignment) ?? false)
                ->each(fn (OrganizationAssignment $assignment) => $assignment->forceFill([
                    'is_active' => false,
                    'ended_at' => $effectiveDate->toDateString(),
                    'changed_by' => $actor?->id,
                    'reason' => $reason ?? $assignment->reason,
                    'lock_version' => $assignment->lock_version + 1,
                ])->save());

            if ($same !== null) {
                return $same;
            }

            $assignment = OrganizationAssignment::create([
                'user_id' => $lockedSales->id,
                'parent_user_id' => $lockedCoordinator->id,
                'organization_unit_id' => $lockedSales->organization_unit_id,
                'relationship_type' => self::SALES_TEAM_MEMBER_OF,
                'branch_id' => $lockedSales->branch_id,
                'project_id' => null,
                'started_at' => $effectiveDate->toDateString(),
                'is_active' => true,
                'is_primary' => true,
                'source' => $source ?? 'organization_sales_team',
                'changed_by' => $actor?->id,
                'reason' => $reason,
                'lock_version' => 1,
            ]);

            $this->audit->log('organization_sales_team_assignment_changed', $lockedSales, $actor, [
                'coordinator_id' => $previous?->parent_user_id,
            ], [
                'coordinator_id' => $assignment->parent_user_id,
                'effective_date' => $effectiveDate->toDateString(),
                'source' => $source ?? 'organization_sales_team',
                'reason' => $reason,
                'branch_id' => $lockedSales->branch_id,
            ]);

            return $assignment->load(['parent.role', 'user.role']);
        }, attempts: 3);
    }

    public function closeSalesTeamMember(User $sales, User $coordinator, ?string $reason = null, ?User $actor = null): void
    {
        DB::transaction(function () use ($actor, $coordinator, $reason, $sales): void {
            $assignment = OrganizationAssignment::query()
                ->where('user_id', $sales->id)
                ->where('parent_user_id', $coordinator->id)
                ->salesTeamMemberOf()
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                return;
            }

            $assignment->forceFill([
                'is_active' => false,
                'ended_at' => today()->toDateString(),
                'changed_by' => $actor?->id,
                'lock_version' => $assignment->lock_version + 1,
                'reason' => $reason ?? $assignment->reason,
            ])->save();
            $this->audit->log('organization_sales_team_assignment_closed', $sales, $actor, [
                'coordinator_id' => $coordinator->id,
            ], [
                'coordinator_id' => $coordinator->id,
                'reason' => $reason,
            ]);
        }, attempts: 3);
    }

    public function directChildren(User $parent): Collection
    {
        return User::query()
            ->with('role')
            ->whereIn('id', OrganizationAssignment::query()
                ->current()
                ->where('parent_user_id', $parent->id)
                ->pluck('user_id'))
            ->where('is_active', true)
            ->get();
    }

    /** @return array<int> */
    public function descendantIds(User $root): array
    {
        $assignments = OrganizationAssignment::query()
            ->current()
            ->get(['user_id', 'parent_user_id']);
        $childrenByParent = $assignments->groupBy('parent_user_id');
        $visited = [(int) $root->id => true];
        $frontier = [(int) $root->id];
        $descendants = [];

        while ($frontier !== []) {
            $next = [];
            foreach ($frontier as $parentId) {
                foreach ($childrenByParent->get($parentId, collect()) as $assignment) {
                    $childId = (int) $assignment->user_id;
                    if (isset($visited[$childId])) {
                        continue;
                    }

                    $visited[$childId] = true;
                    $descendants[] = $childId;
                    $next[] = $childId;
                }
            }
            $frontier = $next;
        }

        return User::query()->whereIn('id', $descendants)->where('is_active', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return Collection<int, User> */
    public function descendants(User $root): Collection
    {
        return User::query()->with('role')->whereIn('id', $this->descendantIds($root))->get();
    }

    /** @return Collection<int, User> */
    public function ancestors(User $user): Collection
    {
        $parentByUser = OrganizationAssignment::query()->current()->pluck('parent_user_id', 'user_id')->all();
        $ids = [];
        $currentId = (int) $user->id;
        $visited = [];
        while (($parentId = $parentByUser[$currentId] ?? null) !== null) {
            $parentId = (int) $parentId;
            if (isset($visited[$parentId])) {
                break;
            }
            $visited[$parentId] = true;
            $ids[] = $parentId;
            $currentId = $parentId;
        }

        return User::query()->with('role')->whereIn('id', $ids)->get()
            ->sortBy(fn (User $ancestor) => array_search($ancestor->id, $ids, true))->values();
    }

    /** @return Collection<int, OrganizationAssignment> */
    public function historicalAssignments(User $user): Collection
    {
        return OrganizationAssignment::query()
            ->where('user_id', $user->id)
            ->where('relationship_type', self::REPORTS_TO)
            ->with(['parent.role', 'changedBy'])
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @return array<int> */
    public function resolveTeamIds(User $user): array
    {
        return $this->descendantIds($user);
    }

    public function canMove(User $actor, User $user, User|int|null $newParent): bool
    {
        try {
            $this->assertCanMove($actor, $user, $this->resolveUser($newParent));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function validateParent(User $user, ?User $parent): void
    {
        $this->assertTargetAndParent($user, $parent);
        if ($parent !== null) {
            $this->assertRoleRelationship($parent, $user);
            $this->assertSharedWorkspace($user, $parent);
            $this->assertNoCycle($user, $parent);
        }
    }

    public function move(
        User $actor,
        User $user,
        User|int|null $newParent,
        ?CarbonInterface $effectiveDate = null,
        ?int $expectedAssignmentId = null,
        ?int $expectedVersion = null,
        ?string $reason = null,
    ): ?OrganizationAssignment {
        return $this->assign(
            $user,
            $newParent,
            $actor,
            $effectiveDate,
            $expectedAssignmentId,
            $expectedVersion,
            'organization_workspace',
            $reason,
        );
    }

    public function moveToUnit(
        User $actor,
        User $user,
        int $organizationUnitId,
        ?int $expectedOrganizationUnitId = null,
        ?string $reason = null,
        bool $enforceExpectedOrganizationUnit = false,
    ): User {
        $unit = OrganizationUnit::query()->with('branch')->where('is_active', true)->findOrFail($organizationUnitId);
        $this->assertCanMoveToUnit($actor, $user, $unit);

        return DB::transaction(function () use ($actor, $expectedOrganizationUnitId, $reason, $unit, $user, $enforceExpectedOrganizationUnit): User {
            $lockedUser = User::query()->with(['branches', 'organizationUnit'])->lockForUpdate()->findOrFail($user->id);
            if (($enforceExpectedOrganizationUnit || $expectedOrganizationUnitId !== null)
                && $lockedUser->organization_unit_id !== $expectedOrganizationUnitId) {
                throw new OrganizationAssignmentConflictException($this->currentAssignment($lockedUser));
            }

            if ($lockedUser->organization_unit_id === $unit->id) {
                return $lockedUser;
            }

            $old = [
                'organization_unit_id' => $lockedUser->organization_unit_id,
                'branch_id' => $lockedUser->branch_id,
                'branch_ids' => $lockedUser->branches->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ];

            if ($unit->branch_id !== null) {
                $branchIds = $lockedUser->branches->pluck('id')->map(fn ($id) => (int) $id)->push($unit->branch_id)->unique()->all();
                app(BranchAssignmentService::class)->assign($lockedUser, $branchIds, (int) $unit->branch_id, $actor);
                $lockedUser->refresh();
            }

            $current = OrganizationAssignment::query()->where('user_id', $lockedUser->id)->current()->lockForUpdate()->first();
            if ($current !== null) {
                $current->forceFill([
                    'organization_unit_id' => $unit->id,
                    'branch_id' => $unit->branch_id,
                    'lock_version' => $current->lock_version + 1,
                ])->save();
            }

            $lockedUser->forceFill(['organization_unit_id' => $unit->id])->saveQuietly();
            $this->audit->log('organization_unit_changed', $lockedUser, $actor, $old, [
                'organization_unit_id' => $unit->id,
                'branch_id' => $lockedUser->branch_id,
                'reason' => $reason,
            ]);

            return $lockedUser->fresh(['organizationUnit.branch']);
        }, attempts: 3);
    }

    public function removeFromStructure(
        User $actor,
        User $user,
        ?int $expectedOrganizationUnitId = null,
        ?int $expectedAssignmentId = null,
        ?int $expectedVersion = null,
        ?string $reason = null,
        bool $enforceExpectedOrganizationUnit = false,
    ): User {
        $this->assertCanRemoveFromStructure($actor, $user);

        return DB::transaction(function () use ($actor, $expectedAssignmentId, $expectedOrganizationUnitId, $expectedVersion, $reason, $user, $enforceExpectedOrganizationUnit): User {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $current = OrganizationAssignment::query()->where('user_id', $lockedUser->id)->current()->lockForUpdate()->first();

            if (($enforceExpectedOrganizationUnit || $expectedOrganizationUnitId !== null)
                && $lockedUser->organization_unit_id !== $expectedOrganizationUnitId) {
                throw new OrganizationAssignmentConflictException($current);
            }
            if ($expectedAssignmentId !== null && $current?->id !== $expectedAssignmentId) {
                throw new OrganizationAssignmentConflictException($current);
            }
            if ($expectedVersion !== null && $current?->lock_version !== $expectedVersion) {
                throw new OrganizationAssignmentConflictException($current);
            }

            $old = [
                'organization_unit_id' => $lockedUser->organization_unit_id,
                'supervisor_user_id' => $lockedUser->supervisor_user_id,
                'assignment_id' => $current?->id,
            ];

            if ($current !== null) {
                $current->forceFill([
                    'is_active' => false,
                    'ended_at' => today(),
                    'lock_version' => $current->lock_version + 1,
                ])->save();
            }

            $lockedUser->forceFill([
                'organization_unit_id' => null,
                'supervisor_user_id' => null,
            ])->saveQuietly();

            $this->audit->log('organization_user_removed', $lockedUser, $actor, $old, [
                'reason' => $reason,
            ]);

            return $lockedUser->fresh(['organizationUnit', 'branch']);
        }, attempts: 3);
    }

    public function assign(
        User $user,
        User|int|null $newParent,
        ?User $actor = null,
        ?CarbonInterface $effectiveDate = null,
        ?int $expectedAssignmentId = null,
        ?int $expectedVersion = null,
        string $source = 'organization_workspace',
        ?string $reason = null,
    ): ?OrganizationAssignment {
        $parent = $this->resolveUser($newParent);
        if (is_int($newParent) && $parent === null) {
            throw ValidationException::withMessages(['parent_user_id' => 'Atasan baru tidak ditemukan.']);
        }
        $effectiveDate = CarbonImmutable::instance($effectiveDate ?? today())->startOfDay();

        if (! $effectiveDate->isToday()) {
            throw ValidationException::withMessages(['effective_date' => 'Perubahan struktur harus menggunakan tanggal hari ini.']);
        }

        if ($actor !== null) {
            $this->assertCanMove($actor, $user, $parent, $this->allowsInactiveLegacyTarget($user, $source), $source === 'legacy_reporting_hierarchy');
        } else {
            $this->assertTargetAndParent($user, $parent, $this->allowsInactiveLegacyTarget($user, $source));
        }

        return DB::transaction(function () use ($actor, $effectiveDate, $expectedAssignmentId, $expectedVersion, $parent, $reason, $source, $user): ?OrganizationAssignment {
            $lockedUsers = User::query()->whereIn('id', collect([$user->id, $parent?->id])->filter()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lockedUser = $lockedUsers->get($user->id);
            $lockedParent = $parent ? $lockedUsers->get($parent->id) : null;
            $this->assertTargetAndParent($lockedUser, $lockedParent, $this->allowsInactiveLegacyTarget($lockedUser, $source));
            $this->assertNoCycle($lockedUser, $lockedParent);
            if ($lockedParent !== null) {
                $this->assertRoleRelationship($lockedParent, $lockedUser);
                $this->assertSharedWorkspace($lockedUser, $lockedParent);
            }

            $currentAssignments = OrganizationAssignment::query()
                ->where('user_id', $lockedUser->id)
                ->where('relationship_type', self::REPORTS_TO)
                ->where('is_active', true)
                ->where('is_primary', true)
                ->lockForUpdate()
                ->get();
            if ($currentAssignments->count() > 1) {
                throw ValidationException::withMessages(['organization' => 'Pengguna memiliki lebih dari satu assignment organisasi aktif.']);
            }

            $current = $currentAssignments->first();
            if (($expectedAssignmentId !== null && $current?->id !== $expectedAssignmentId)
                || ($expectedVersion !== null && $current?->lock_version !== $expectedVersion)) {
                throw new OrganizationAssignmentConflictException($current?->load('parent'));
            }

            if ($current?->parent_user_id === $lockedParent?->id) {
                return $current;
            }

            if ($current !== null) {
                $current->forceFill([
                    'is_active' => false,
                    'ended_at' => $effectiveDate->toDateString(),
                    'lock_version' => $current->lock_version + 1,
                ])->save();
            }

            $assignment = $lockedParent === null ? null : OrganizationAssignment::create([
                'user_id' => $lockedUser->id,
                'parent_user_id' => $lockedParent->id,
                'organization_unit_id' => $lockedUser->organization_unit_id,
                'relationship_type' => self::REPORTS_TO,
                'branch_id' => $lockedUser->branch_id,
                'started_at' => $effectiveDate->toDateString(),
                'is_active' => true,
                'is_primary' => true,
                'source' => $source,
                'changed_by' => $actor?->id,
                'reason' => $reason,
                'lock_version' => 1,
            ]);

            $lockedUser->forceFill(['supervisor_user_id' => $lockedParent?->id])->saveQuietly();
            $this->audit->log('organization_assignment_changed', $lockedUser, $actor, [
                'old_parent_id' => $current?->parent_user_id,
                'old_assignment_id' => $current?->id,
            ], [
                'new_parent_id' => $assignment?->parent_user_id,
                'new_assignment_id' => $assignment?->id,
                'effective_date' => $effectiveDate->toDateString(),
                'source' => $source,
                'reason' => $reason,
                'branch_id' => $lockedUser->branch_id,
            ]);
            if ($source === 'legacy_reporting_hierarchy') {
                $this->audit->log('supervisor_assignment_changed', $lockedUser, $actor, [
                    'supervisor_user_id' => $current?->parent_user_id,
                ], [
                    'supervisor_user_id' => $assignment?->parent_user_id,
                ]);
            }

            return $assignment?->load(['parent.role', 'user.role']);
        }, attempts: 3);
    }

    public function closeAssignment(OrganizationAssignment $assignment, ?User $actor = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($assignment, $reason): void {
            $locked = OrganizationAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                return;
            }
            $locked->forceFill([
                'is_active' => false,
                'ended_at' => today()->toDateString(),
                'lock_version' => $locked->lock_version + 1,
                'reason' => $reason ?? $locked->reason,
            ])->save();
        }, attempts: 3);
    }

    private function assertCanMove(User $actor, User $user, ?User $parent, bool $allowInactiveTarget = false, bool $allowLegacyPermission = false): void
    {
        abort_unless($actor->hasPermission('organization.move_user') || ($allowLegacyPermission && $actor->hasPermission('users.assign_supervisor')), 403);
        $this->assertTargetAndParent($user, $parent, $allowInactiveTarget);
        abort_unless($this->canManageInScope($actor, $user), 403);
        if ($parent !== null) {
            abort_unless($this->canManageInScope($actor, $parent), 403);
            $this->assertRoleRelationship($parent, $user);
            $this->assertSharedWorkspace($user, $parent);
        }
    }

    private function assertCanMoveToUnit(User $actor, User $user, OrganizationUnit $unit): void
    {
        abort_unless($actor->hasPermission('organization.move_user'), 403);
        abort_if($actor->is($user), 403, 'Anda tidak dapat memindahkan akun sendiri.');
        abort_unless($this->canManageInScope($actor, $user), 403);
        abort_if(! $user->isAccountActive() || ! $user->is_active, 422, 'Pengguna yang dipindahkan harus aktif.');

        if ($unit->unit_type === 'central') {
            abort_unless($actor->isSuperadmin() || $actor->hasPrimaryRole('pusat'), 403);

            return;
        }

        abort_unless($unit->branch_id !== null && in_array($unit->branch_id, $this->workspaceAccess->accessibleBranchIds($actor), true), 403);
    }

    private function assertCanRemoveFromStructure(User $actor, User $user): void
    {
        abort_unless($actor->hasPermission('organization.move_user'), 403);
        abort_if($actor->is($user), 403, 'Anda tidak dapat mengubah struktur akun sendiri.');
        abort_unless($this->canManageInScope($actor, $user), 403);
        abort_if(! $user->isAccountActive() || ! $user->is_active, 422, 'Pengguna yang dikeluarkan harus aktif.');
    }

    private function assertTargetAndParent(?User $user, ?User $parent, bool $allowInactiveTarget = false): void
    {
        if ($user === null || (! $allowInactiveTarget && (! $user->isAccountActive() || ! $user->is_active))) {
            throw ValidationException::withMessages(['user' => 'Pengguna yang dipindahkan harus aktif.']);
        }
        if ($parent === null) {
            return;
        }
        if (! $allowInactiveTarget && (! $parent->isAccountActive() || ! $parent->is_active)) {
            throw ValidationException::withMessages(['parent_user_id' => 'Atasan baru harus merupakan pengguna aktif.']);
        }
        if ($user->is($parent)) {
            throw ValidationException::withMessages(['parent_user_id' => 'Pengguna tidak dapat menjadi atasan dirinya sendiri.']);
        }
    }

    private function assertRoleRelationship(User $parent, User $user): void
    {
        $parentLevel = (int) ($parent->role?->authority_level ?? 0);
        $childLevel = (int) ($user->role?->authority_level ?? 0);
        if ($parentLevel < 1 || $childLevel < 1 || $parentLevel < $childLevel) {
            throw ValidationException::withMessages(['parent_user_id' => 'Tingkat kewenangan atasan harus lebih tinggi atau sama dengan bawahan.']);
        }
    }

    private function assertSharedWorkspace(User $user, User $parent): void
    {
        $userUnit = $this->organizationUnit($user);
        $parentUnit = $this->organizationUnit($parent);

        if ($userUnit !== null && $parentUnit !== null) {
            if ($userUnit->is($parentUnit) || $parentUnit->unit_type === 'central') {
                return;
            }

            throw ValidationException::withMessages(['parent_user_id' => 'Atasan dan pengguna harus berada dalam tree organisasi yang sama.']);
        }

        if (array_intersect($this->workspaceAccess->accessibleBranchIds($user), $this->workspaceAccess->accessibleBranchIds($parent)) !== []) {
            return;
        }

        if (array_intersect($this->currentProjectIds($user), $this->currentProjectIds($parent)) !== []) {
            return;
        }

        throw ValidationException::withMessages(['parent_user_id' => 'Atasan dan pengguna harus memiliki cabang atau proyek kerja yang sama.']);
    }

    private function organizationUnit(User $user): ?OrganizationUnit
    {
        if ($user->relationLoaded('organizationUnit')) {
            return $user->organizationUnit;
        }

        if ($user->organization_unit_id !== null) {
            return OrganizationUnit::query()->find($user->organization_unit_id);
        }

        return $user->branch_id === null
            ? OrganizationUnit::query()->where('code', 'pusat')->first()
            : OrganizationUnit::query()->where('branch_id', $user->branch_id)->first();
    }

    private function canManageInScope(User $actor, User $target): bool
    {
        return array_intersect(
            $this->workspaceAccess->accessibleBranchIds($actor),
            $this->workspaceAccess->accessibleBranchIds($target),
        ) !== [];
    }

    private function assertNoCycle(User $user, ?User $parent): void
    {
        if ($parent === null) {
            return;
        }

        $parentByUser = OrganizationAssignment::query()->current()->pluck('parent_user_id', 'user_id')->all();
        $visited = [];
        $currentId = (int) $parent->id;
        while ($currentId > 0) {
            if ($currentId === (int) $user->id || isset($visited[$currentId])) {
                throw ValidationException::withMessages(['parent_user_id' => 'Penugasan parent akan membentuk siklus organisasi.']);
            }
            $visited[$currentId] = true;
            $currentId = (int) ($parentByUser[$currentId] ?? 0);
        }
    }

    private function resolveUser(User|int|null $user): ?User
    {
        return match (true) {
            $user instanceof User => $user,
            is_int($user) => User::query()->find($user),
            default => null,
        };
    }

    private function allowsInactiveLegacyTarget(User $user, string $source): bool
    {
        return $source === 'legacy_reporting_hierarchy'
            && in_array($user->account_status?->value, [AccountStatus::PendingInvitation->value, AccountStatus::Invited->value], true);
    }

    /** @return array<int> */
    private function currentProjectIds(User $user): array
    {
        return DB::table('project_user')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('assignment_start_date')->orWhereDate('assignment_start_date', '<=', today()))
            ->where(fn ($query) => $query->whereNull('assignment_end_date')->orWhereDate('assignment_end_date', '>=', today()))
            ->pluck('project_id')->map(fn ($id) => (int) $id)->all();
    }
}
