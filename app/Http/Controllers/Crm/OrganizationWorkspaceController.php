<?php

namespace App\Http\Controllers\Crm;

use App\Exceptions\OrganizationAssignmentConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganizationMoveRequest;
use App\Models\OrganizationAssignment;
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
        $assignments = OrganizationAssignment::query()->current()->whereIn('user_id', $users->pluck('id'))->get();
        $nodes = $this->nodes($users, $assignments);

        return view('crm.organization.index', [
            'nodes' => $nodes,
            'flatNodes' => collect($nodes)->flatMap(fn (array $node) => $this->flatten($node))->values(),
            'branches' => $this->workspace->accessibleBranches($actor),
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

    /** @return array<int, array<string, mixed>> */
    private function nodes(Collection $users, Collection $assignments): array
    {
        $byId = $users->mapWithKeys(fn (User $user) => [$user->id => [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role?->name ?? 'Tanpa peran',
            'branch' => $user->branch?->name,
            'parent_id' => null,
            'assignment_id' => null,
            'assignment_version' => null,
            'direct_reports' => 0,
            'children' => [],
        ]])->all();

        foreach ($assignments as $assignment) {
            if (! isset($byId[$assignment->user_id])) {
                continue;
            }
            $byId[$assignment->user_id]['parent_id'] = $assignment->parent_user_id;
            $byId[$assignment->user_id]['assignment_id'] = $assignment->id;
            $byId[$assignment->user_id]['assignment_version'] = $assignment->lock_version;
        }

        $roots = [];
        foreach ($byId as $id => &$node) {
            if ($node['parent_id'] !== null && isset($byId[$node['parent_id']])) {
                $byId[$node['parent_id']]['children'][] = &$node;
                $byId[$node['parent_id']]['direct_reports']++;
            } else {
                $roots[] = &$node;
            }
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
