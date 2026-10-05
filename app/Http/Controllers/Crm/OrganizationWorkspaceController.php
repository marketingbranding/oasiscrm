<?php

namespace App\Http\Controllers\Crm;

use App\Exceptions\OrganizationAssignmentConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationMoveRequest;
use App\Http\Requests\OrganizationRemoveRequest;
use App\Http\Requests\OrganizationUnitMoveRequest;
use App\Models\OrganizationAssignment;
use App\Models\OrganizationUnit;
use App\Models\User;
use App\Services\OrganizationGraphService;
use App\Services\OrganizationScopeService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class OrganizationWorkspaceController extends Controller
{
    public function __construct(
        private readonly OrganizationGraphService $graph,
        private readonly OrganizationScopeService $scope,
        private readonly WorkspaceAccessService $workspace,
    ) {}

    public function index(Request $request): View
    {
        $actor = $request->user();
        $branchIds = $this->workspace->accessibleBranchIds($actor);
        $selectedBranchId = $request->integer('branch_id') ?: null;
        abort_if($selectedBranchId !== null && ! in_array($selectedBranchId, $branchIds, true), 403);

        $users = $this->scope->visibleUsersQuery($actor, 'work_planner', 'view')
            ->with(['role', 'branch', 'assignedProjects.branch'])
            ->when($selectedBranchId, fn ($query) => $query->where(function ($nested) use ($selectedBranchId) {
                $nested->where('branch_id', $selectedBranchId)
                    ->orWhereHas('branches', fn ($branches) => $branches->whereKey($selectedBranchId));
            }))
            ->orderBy('name')
            ->get();
        $units = OrganizationUnit::query()
            ->with('branch')
            ->where('is_active', true)
            ->where(function ($query) use ($branchIds, $selectedBranchId) {
                $query->where('unit_type', 'central')->orWhereIn('branch_id', $selectedBranchId === null ? $branchIds : [$selectedBranchId]);
            })
            ->orderByRaw("CASE WHEN unit_type = 'central' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();
        $assignments = OrganizationAssignment::query()->current()->whereIn('user_id', $users->pluck('id'))->get();
        $nodes = $this->nodes($units, $users, $assignments);

        return view('crm.organization.index', [
            'nodes' => $nodes,
            'flatNodes' => collect($nodes)->flatMap(fn (array $node) => $this->flatten($node))->values(),
            'branches' => $this->workspace->accessibleBranches($actor),
            'canMove' => $actor->hasPermission('organization.move_user'),
            'unitMoveUrl' => route('organization.move-unit', ['user' => '__USER__']),
            'removeStructureUrl' => route('organization.remove-structure', ['user' => '__USER__']),
            'movableUnitIds' => $units->filter(fn (OrganizationUnit $unit) => $unit->unit_type === 'central'
                ? $actor->isSuperadmin() || $actor->hasPrimaryRole('pusat')
                : $unit->branch_id !== null && in_array($unit->branch_id, $branchIds, true))->pluck('id')->map(fn ($id) => (int) $id)->values(),
            'selectedBranchId' => $selectedBranchId,
        ]);
    }

    public function move(OrganizationMoveRequest $request, User $user): JsonResponse
    {
        try {
            $assignment = $this->graph->move(
                $request->user(),
                $user,
                $request->integer('parent_user_id') ?: null,
                expectedAssignmentId: $request->integer('expected_assignment_id') ?: null,
                expectedVersion: $request->integer('expected_version') ?: null,
                reason: $request->validated('reason'),
            );
        } catch (OrganizationAssignmentConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Struktur organisasi berhasil diperbarui.',
            'assignment' => $assignment?->load('parent:id,name'),
        ]);
    }

    public function moveToUnit(OrganizationUnitMoveRequest $request, User $user): JsonResponse
    {
        try {
            $result = $this->graph->moveToUnit(
                $request->user(),
                $user,
                $request->integer('organization_unit_id'),
                $request->integer('expected_organization_unit_id') ?: null,
                $request->validated('reason'),
            );
        } catch (OrganizationAssignmentConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Pengguna berhasil dipindahkan ke unit organisasi.',
            'user' => $result->load(['organizationUnit.branch']),
        ]);
    }

    public function removeFromStructure(OrganizationRemoveRequest $request, User $user): JsonResponse
    {
        try {
            $result = $this->graph->removeFromStructure(
                $request->user(),
                $user,
                $request->integer('expected_organization_unit_id') ?: null,
                $request->integer('expected_assignment_id') ?: null,
                $request->integer('expected_version') ?: null,
                $request->validated('reason'),
            );
        } catch (OrganizationAssignmentConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'message' => 'Pengguna dikeluarkan dari struktur organisasi. Akun dan akses operasional tetap dipertahankan.',
            'user' => $result,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function nodes(Collection $units, Collection $users, Collection $assignments): array
    {
        $byId = $units->mapWithKeys(fn (OrganizationUnit $unit) => ["unit:{$unit->id}" => [
            'id' => "unit:{$unit->id}",
            'unit_id' => $unit->id,
            'name' => $unit->name,
            'role' => $unit->unit_type === 'central' ? 'Pusat organisasi' : 'Tree cabang',
            'branch' => $unit->branch?->name,
            'kind' => 'unit',
            'parent_id' => null,
            'tree_parent_id' => $unit->parent_id === null ? null : "unit:{$unit->parent_id}",
            'assignment_id' => null,
            'assignment_version' => null,
            'direct_reports' => 0,
            'member_count' => 0,
            'children' => [],
        ]])->all();
        $hasUnassigned = false;

        foreach ($users as $user) {
            $assignment = $assignments->firstWhere('user_id', $user->id);
            $unitId = $user->organization_unit_id
                ? $user->organization_unit_id
                : null;
            if ($unitId === null) {
                $hasUnassigned = true;
                $unitNodeId = 'unit:unassigned';
            } else {
                $unitNodeId = "unit:{$unitId}";
            }
            $parentUserId = $assignment?->parent_user_id;
            $treeParentId = $parentUserId !== null && isset($byId[$parentUserId])
                ? $parentUserId
                : $unitNodeId;

            $byId[$user->id] = [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role?->name ?? 'Tanpa peran',
                'branch' => $user->branch?->name,
                'kind' => 'user',
                'unit_id' => $unitNodeId,
                'parent_id' => $parentUserId,
                'tree_parent_id' => $treeParentId,
                'assignment_id' => $assignment?->id,
                'assignment_version' => $assignment?->lock_version,
                'direct_reports' => 0,
                'member_count' => 0,
                'children' => [],
            ];
        }

        if ($hasUnassigned) {
            $byId['unit:unassigned'] = [
                'id' => 'unit:unassigned',
                'unit_id' => null,
                'name' => 'Belum ditempatkan',
                'role' => 'Perlu penempatan',
                'branch' => null,
                'kind' => 'unit',
                'parent_id' => null,
                'tree_parent_id' => null,
                'assignment_id' => null,
                'assignment_version' => null,
                'direct_reports' => 0,
                'children' => [],
            ];
        }

        $roots = [];
        foreach ($byId as $id => &$node) {
            $parentId = $node['tree_parent_id'];
            if ($parentId !== null && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$node;
                if ($node['kind'] === 'user' && $byId[$parentId]['kind'] === 'user') {
                    $byId[$parentId]['direct_reports']++;
                }

                continue;
            }
            if ($node['kind'] === 'unit' && $node['tree_parent_id'] === null) {
                $roots[] = &$node;
            }
        }
        unset($node);

        foreach ($byId as $id => &$node) {
            if ($node['kind'] !== 'unit') {
                continue;
            }

            $node['member_count'] = collect($byId)
                ->where('kind', 'user')
                ->where('unit_id', $id)
                ->count();
        }
        unset($node);

        return array_values($roots);
    }

    /** @return array<int, array<string, mixed>> */
    private function flatten(array $node): array
    {
        $children = $node['children'];
        unset($node['children']);

        return [$node, ...collect($children)->flatMap(fn (array $child) => $this->flatten($child))->all()];
    }
}
