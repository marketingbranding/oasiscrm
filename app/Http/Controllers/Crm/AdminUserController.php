<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\CollaborationNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminUserController extends Controller
{
    public function __construct(private readonly CollaborationNotificationService $notifications) {}

    public function index()
    {
        $users = User::with(['role', 'branch', 'branches'])
            ->get();

        return view('crm.admin-users.index', compact('users'));
    }

    public function create()
    {
        $roles = Role::all();
        $branches = Branch::where('is_active', true)->forDropdown()->get();

        return view('crm.admin-users.create', compact('roles', 'branches'));
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
            'phone' => 'nullable|string|max:20',
        ]);

        $data['password'] = Hash::make($data['password']);
        $data['is_active'] = true;
        $data['email_verified_at'] = now();

        $branchIds = $this->membershipBranchIds($data);
        $permissions = $data['membership_permissions'] ?? [];
        unset($data['branch_ids'], $data['membership_permissions']);

        DB::transaction(function () use ($data, $branchIds, $permissions) {
            $user = User::create($data);
            $user->branches()->sync($this->membershipPayload($branchIds, $permissions));
            $this->logMembershipChange($user, 'user_created', [], $branchIds);
        });

        return redirect()->route('admin-users.index')->with('success', 'User berhasil ditambahkan.');
    }

    public function edit(User $user)
    {
        $user->load('branches');
        $roles = Role::all();
        $branches = Branch::where('is_active', true)->forDropdown()->get();

        return view('crm.admin-users.edit', compact('user', 'roles', 'branches'));
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
        unset($data['branch_ids'], $data['membership_permissions']);

        $oldBranchIds = $user->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
        DB::transaction(function () use ($user, $data, $branchIds, $permissions, $oldBranchIds) {
            $user->update($data);
            $user->branches()->sync($this->membershipPayload($branchIds, $permissions));
            $this->logMembershipChange($user, 'membership_updated', $oldBranchIds, $branchIds);
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

    private function logMembershipChange(User $user, string $event, array $oldBranchIds, array $newBranchIds): void
    {
        ActivityLog::create([
            'causer_id' => Auth::id(),
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event' => $event,
            'description' => 'Akses cabang user diperbarui',
            'properties' => ['old_branch_ids' => $oldBranchIds, 'new_branch_ids' => $newBranchIds],
        ]);
    }
}
