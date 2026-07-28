<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\CollaborationNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function __construct(private readonly CollaborationNotificationService $notifications) {}

    public function index()
    {
        $users = User::with(['role', 'branch', 'branches', 'assignedProjects'])
            ->get();

        return view('crm.admin-users.index', compact('users'));
    }

    public function create()
    {
        $roles = Role::all();
        $branches = Branch::where('is_active', true)->forDropdown()->get();
        $projectsByBranch = $this->projectsByBranch();

        return view('crm.admin-users.create', compact('roles', 'branches', 'projectsByBranch'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role_id' => 'required|exists:roles,id',
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('is_active', true)],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')->where('is_active', true)],
            'membership_permissions' => ['nullable', 'array'],
            'membership_permissions.*.can_edit' => ['nullable', 'boolean'],
            'membership_permissions.*.can_sync' => ['nullable', 'boolean'],
            'membership_permissions.*.can_manage_members' => ['nullable', 'boolean'],
            'assigned_project_ids' => ['nullable', 'array'],
            'assigned_project_ids.*' => ['integer', 'distinct', Rule::exists('lead_master', 'id')->where('is_active', true)],
            'primary_project_id' => ['nullable', 'integer', Rule::exists('lead_master', 'id')->where('is_active', true)],
            'phone' => 'nullable|string|max:20',
        ]);

        $data['password'] = Hash::make($data['password']);
        $data['is_active'] = true;
        $data['email_verified_at'] = now();

        $branchIds = $this->membershipBranchIds($data);
        $permissions = $data['membership_permissions'] ?? [];
        [$projectIds, $primaryProjectId] = $this->validateProjectAssignments($data, $branchIds);
        unset($data['branch_ids'], $data['membership_permissions'], $data['assigned_project_ids'], $data['primary_project_id']);

        DB::transaction(function () use ($data, $branchIds, $permissions, $projectIds, $primaryProjectId) {
            $user = User::create($data);
            $user->branches()->sync($this->membershipPayload($branchIds, $permissions));
            $user->assignedProjects()->sync($this->projectAssignmentPayload($projectIds, $primaryProjectId));
            $this->logMembershipChange($user, 'user_created', [], $branchIds, [], $projectIds, null, $primaryProjectId);
        });

        return redirect()->route('admin-users.index')->with('success', 'User berhasil ditambahkan.');
    }

    public function edit(User $user)
    {
        $user->load(['branches', 'assignedProjects']);
        $roles = Role::all();
        $branches = Branch::where('is_active', true)->forDropdown()->get();
        $projectsByBranch = $this->projectsByBranch();

        return view('crm.admin-users.edit', compact('user', 'roles', 'branches', 'projectsByBranch'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$user->id,
            'password' => 'nullable|string|min:8|confirmed',
            'role_id' => 'required|exists:roles,id',
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('is_active', true)],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')->where('is_active', true)],
            'membership_permissions' => ['nullable', 'array'],
            'membership_permissions.*.can_edit' => ['nullable', 'boolean'],
            'membership_permissions.*.can_sync' => ['nullable', 'boolean'],
            'membership_permissions.*.can_manage_members' => ['nullable', 'boolean'],
            'assigned_project_ids' => ['nullable', 'array'],
            'assigned_project_ids.*' => ['integer', 'distinct', Rule::exists('lead_master', 'id')->where('is_active', true)],
            'primary_project_id' => ['nullable', 'integer', Rule::exists('lead_master', 'id')->where('is_active', true)],
            'phone' => 'nullable|string|max:20',
            'is_active' => 'required|boolean',
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $branchIds = $this->membershipBranchIds($data);
        $permissions = $data['membership_permissions'] ?? [];
        [$projectIds, $primaryProjectId] = $this->validateProjectAssignments($data, $branchIds);
        unset($data['branch_ids'], $data['membership_permissions'], $data['assigned_project_ids'], $data['primary_project_id']);

        $oldBranchIds = $user->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
        $oldProjects = $user->assignedProjects()->get();
        $oldProjectIds = $oldProjects->pluck('id')->map(fn ($id) => (int) $id)->all();
        $oldPrimaryProjectId = $oldProjects->first(fn (LeadMaster $project) => (bool) $project->pivot->is_primary)?->id;
        DB::transaction(function () use ($user, $data, $branchIds, $permissions, $oldBranchIds, $projectIds, $primaryProjectId, $oldProjectIds, $oldPrimaryProjectId) {
            $user->update($data);
            $user->branches()->sync($this->membershipPayload($branchIds, $permissions));
            $user->assignedProjects()->sync($this->projectAssignmentPayload($projectIds, $primaryProjectId));
            $this->logMembershipChange($user, 'membership_updated', $oldBranchIds, $branchIds, $oldProjectIds, $projectIds, $oldPrimaryProjectId, $primaryProjectId);
        });
        $this->notifications->membershipChanged($user, 'Akses cabang Anda diperbarui oleh '.Auth::user()->name.'.');

        return redirect()->route('admin-users.index')->with('success', 'User berhasil diperbarui.');
    }

    public function destroy(User $user)
    {
        if ($user->id === Auth::id()) {
            return back()->with('error', 'Tidak dapat menghapus akun sendiri.');
        }

        if ($user->isSuperadmin()) {
            $superadminCount = User::where('role_id', $user->role_id)->count();
            if ($superadminCount <= 1) {
                return back()->with('error', 'Tidak dapat menghapus Super Admin terakhir.');
            }
        }

        if ($user->createdExpenses()->exists()) {
            return back()->with('warning', 'User tidak dapat dihapus karena memiliki riwayat pengeluaran. Nonaktifkan akun agar riwayat tetap terjaga.');
        }

        $user->delete();

        return redirect()->route('admin-users.index')->with('success', 'User berhasil dihapus.');
    }

    private function membershipBranchIds(array $data): array
    {
        $branchIds = array_map('intval', $data['branch_ids'] ?? []);
        if (filled($data['branch_id'] ?? null)) {
            $branchIds[] = (int) $data['branch_id'];
        }

        return array_values(array_unique($branchIds));
    }

    private function membershipPayload(array $branchIds, array $permissions): array
    {
        return collect($branchIds)->mapWithKeys(function (int $branchId) use ($permissions) {
            $branchPermissions = $permissions[$branchId] ?? [];

            return [$branchId => [
                'can_view' => true,
                'can_edit' => (bool) ($branchPermissions['can_edit'] ?? false),
                'can_sync' => (bool) ($branchPermissions['can_sync'] ?? false),
                'can_manage_members' => (bool) ($branchPermissions['can_manage_members'] ?? false),
            ]];
        })->all();
    }

    private function validateProjectAssignments(array $data, array $branchIds): array
    {
        $role = Role::findOrFail($data['role_id']);
        $projectIds = array_values(array_unique(array_map('intval', $data['assigned_project_ids'] ?? [])));
        $primaryProjectId = filled($data['primary_project_id'] ?? null) ? (int) $data['primary_project_id'] : null;

        if ($role->slug !== 'sales') {
            if ($projectIds !== [] || $primaryProjectId !== null) {
                throw ValidationException::withMessages([
                    'assigned_project_ids' => 'Penugasan proyek hanya dapat diberikan kepada user Sales.',
                ]);
            }

            return [[], null];
        }

        if ($projectIds === []) {
            throw ValidationException::withMessages([
                'assigned_project_ids' => 'User Sales harus memiliki minimal satu proyek aktif.',
            ]);
        }

        if ($primaryProjectId !== null && ! in_array($primaryProjectId, $projectIds, true)) {
            throw ValidationException::withMessages([
                'primary_project_id' => 'Proyek utama harus termasuk dalam proyek yang ditugaskan.',
            ]);
        }

        $accessibleProjectCount = LeadMaster::query()
            ->whereIn('id', $projectIds)
            ->where('is_active', true)
            ->whereIn('branch_id', $branchIds)
            ->count();

        if ($accessibleProjectCount !== count($projectIds)) {
            throw ValidationException::withMessages([
                'assigned_project_ids' => 'Semua proyek harus aktif dan berada pada cabang yang dapat diakses user.',
            ]);
        }

        return [$projectIds, $primaryProjectId];
    }

    private function projectAssignmentPayload(array $projectIds, ?int $primaryProjectId): array
    {
        return collect($projectIds)->mapWithKeys(fn (int $projectId) => [
            $projectId => ['is_primary' => $projectId === $primaryProjectId],
        ])->all();
    }

    private function projectsByBranch(): Collection
    {
        return LeadMaster::query()
            ->with('branch')
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true))
            ->orderBy('project_name')
            ->get()
            ->groupBy('branch_id');
    }

    private function logMembershipChange(
        User $user,
        string $event,
        array $oldBranchIds,
        array $newBranchIds,
        array $oldProjectIds,
        array $newProjectIds,
        ?int $oldPrimaryProjectId,
        ?int $newPrimaryProjectId,
    ): void {
        ActivityLog::create([
            'causer_id' => Auth::id(),
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event' => $event,
            'description' => 'Akses cabang dan proyek user diperbarui',
            'properties' => [
                'old_branch_ids' => $oldBranchIds,
                'new_branch_ids' => $newBranchIds,
                'old_project_ids' => $oldProjectIds,
                'new_project_ids' => $newProjectIds,
                'old_primary_project_id' => $oldPrimaryProjectId,
                'new_primary_project_id' => $newPrimaryProjectId,
            ],
        ]);
    }
}
