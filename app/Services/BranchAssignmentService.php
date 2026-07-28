<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchAssignmentService
{
    public function __construct(private AccountAuditService $audit) {}

    /**
     * @param  array<int, array<string, mixed>|int>  $assignments
     */
    public function assign(User $user, array $assignments, ?int $primaryBranchId, ?User $actor = null): User
    {
        $normalized = $this->normalize($assignments);
        if ($primaryBranchId === null || ! array_key_exists($primaryBranchId, $normalized)) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang utama harus termasuk dalam keanggotaan cabang.',
            ]);
        }

        $activeIds = Branch::query()->whereIn('id', array_keys($normalized))->where('is_active', true)->pluck('id')->all();
        if (count($activeIds) !== count($normalized)) {
            throw ValidationException::withMessages(['branch_ids' => 'Semua cabang yang ditambahkan harus aktif.']);
        }

        return DB::transaction(function () use ($user, $normalized, $primaryBranchId, $actor) {
            $old = $this->snapshot($user);
            $inactiveExisting = $user->branches()
                ->where('branches.is_active', false)
                ->get()
                ->mapWithKeys(fn (Branch $branch) => [$branch->id => $this->pivotValues($branch->pivot->getAttributes())])
                ->all();

            $user->branches()->sync($normalized + $inactiveExisting);
            $user->forceFill(['branch_id' => $primaryBranchId])->save();
            $user->refresh()->load('branches');

            $this->audit->log('branch_assignments_changed', $user, $actor, $old, $this->snapshot($user));

            return $user;
        });
    }

    private function normalize(array $assignments): array
    {
        $normalized = [];
        foreach ($assignments as $key => $value) {
            $branchId = is_array($value) ? (int) ($value['branch_id'] ?? $key) : (int) $value;
            $flags = is_array($value) ? $value : [];
            $normalized[$branchId] = $this->pivotValues($flags);
        }

        return array_filter($normalized, fn (array $value, int $key) => $key > 0, ARRAY_FILTER_USE_BOTH);
    }

    private function pivotValues(array $values): array
    {
        return [
            'membership_role' => $values['membership_role'] ?? null,
            'can_view' => (bool) ($values['can_view'] ?? true),
            'can_edit' => (bool) ($values['can_edit'] ?? false),
            'can_sync' => (bool) ($values['can_sync'] ?? false),
            'can_manage_members' => (bool) ($values['can_manage_members'] ?? false),
        ];
    }

    private function snapshot(User $user): array
    {
        $branches = $user->branches()->get();

        return [
            'primary_branch_id' => $user->branch_id,
            'branches' => $branches->mapWithKeys(fn (Branch $branch) => [
                $branch->id => $this->pivotValues($branch->pivot->getAttributes()),
            ])->all(),
        ];
    }
}
