<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Models\UserImportBatch;
use App\Models\UserImportRow;
use Illuminate\Validation\ValidationException;

class ReportingHierarchyService
{
    public function __construct(private readonly OrganizationGraphService $graph) {}

    public function roleRank(User|string $userOrRole): int
    {
        $role = $userOrRole instanceof User
            ? $userOrRole->role
            : Role::query()->where('slug', $userOrRole)->first();

        return (int) ($role?->authority_level ?? 0);
    }

    public function assignSupervisor(User $user, User|int|null $supervisor, ?User $actor = null): User
    {
        try {
            $this->graph->assign($user, $supervisor, $actor, source: 'legacy_reporting_hierarchy');
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'supervisor_user_id' => collect($exception->errors())->flatten()->first() ?? 'Penugasan atasan tidak valid.',
            ]);
        }

        return $user->refresh();
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

        try {
            $this->graph->assign($user, $supervisor, $actor, source: 'legacy_reporting_hierarchy');
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'supervisor_user_id' => collect($exception->errors())->flatten()->first() ?? 'Penugasan atasan onboarding tidak valid.',
            ]);
        }

        app(AccountAuditService::class)->logBulkUser('user_supervisor_linked_bulk', $user, $actor, $batch, $rowId);

        return $user->refresh();
    }

    /** @return array<int> */
    public function descendantIds(User $supervisor, int $depthLimit = 100): array
    {
        $legacy = $this->legacyDescendantIds($supervisor, $depthLimit);
        if (config('organization.graph_mode') === 'shadow') {
            $canonical = $this->graph->descendantIds($supervisor);
            if ($this->normalized($legacy) !== $this->normalized($canonical)) {
                logger()->warning('organization_graph_shadow_mismatch', [
                    'user_id' => $supervisor->id,
                    'legacy_ids' => $legacy,
                    'canonical_ids' => $canonical,
                ]);
            }

            return $legacy;
        }
        if (config('organization.graph_mode') !== 'canonical') {
            return $legacy;
        }

        return $this->graph->descendantIds($supervisor);
    }

    /** @return array<int> */
    private function legacyDescendantIds(User $supervisor, int $depthLimit): array
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

    /** @param array<int> $ids */
    private function normalized(array $ids): array
    {
        sort($ids);

        return array_values(array_unique($ids));
    }
}
