<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolePermissionUpdateRequest;
use App\Http\Requests\RoleStoreRequest;
use App\Http\Requests\RoleUpdateRequest;
use App\Models\ActivityLog;
use App\Models\OrganizationAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountAuditService;
use App\Services\SystemAdministrationAuthorizationService;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoleAdministrationController extends Controller
{
    public function __construct(private readonly SystemAdministrationAuthorizationService $systemAdministration) {}

    public function index(): View
    {
        $this->authorizeSystemAdministration();
        $roles = Role::query()->with('permissions')->withCount('users')->orderBy('authority_level')->orderBy('name')->get();
        $permissions = Permission::query()->orderBy('group_name')->orderBy('name')->get()->groupBy('group_name');

        return view('crm.roles.index', [
            'roles' => $roles,
            'permissions' => $permissions,
            'permissionGroupDescriptions' => PermissionCatalog::groupDescriptions(),
        ]);
    }

    public function store(RoleStoreRequest $request): RedirectResponse
    {
        $this->authorizeSystemAdministration();
        $data = $request->validated();
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['name']);
        $role = Role::create([...$data, 'is_superadmin' => false, 'is_active' => true]);
        $this->audit('role_created', $role, $request->user(), [], $role->only(['name', 'slug', 'authority_level']));

        return back()->with('success', 'Peran baru berhasil dibuat.');
    }

    public function update(RoleUpdateRequest $request, Role $role): RedirectResponse
    {
        $this->authorizeSystemAdministration();
        $data = $request->validated();
        if ($role->is_superadmin && ! $data['is_active']) {
            throw ValidationException::withMessages(['is_active' => 'Superadmin tidak dapat dinonaktifkan.']);
        }
        if (! $data['is_active'] && $role->users()->exists()) {
            throw ValidationException::withMessages(['is_active' => 'Peran dengan pengguna aktif tidak dapat dinonaktifkan.']);
        }
        if ($role->is_superadmin && (int) $data['authority_level'] !== 100) {
            throw ValidationException::withMessages(['authority_level' => 'Superadmin harus tetap berada pada tingkat kewenangan 100.']);
        }
        if ((int) $data['authority_level'] !== (int) $role->authority_level) {
            $this->assertExistingAssignmentsRemainValid($role, (int) $data['authority_level']);
        }

        $old = $role->only(['name', 'description', 'authority_level', 'is_active']);
        $role->update($data);
        $this->audit('role_updated', $role, $request->user(), $old, $role->only(array_keys($old)));

        return back()->with('success', 'Konfigurasi peran berhasil diperbarui.');
    }

    public function updatePermissions(RolePermissionUpdateRequest $request, Role $role): RedirectResponse
    {
        $this->authorizeSystemAdministration();
        if ($role->is_superadmin) {
            throw ValidationException::withMessages(['role' => 'Permission Superadmin mengikuti registry wildcard dan tidak diedit melalui matrix.']);
        }

        $old = $role->permissions()->pluck('slug')->all();
        $role->permissions()->sync($request->validated('permission_ids', []));
        $new = $role->fresh()->permissions()->pluck('slug')->all();
        app(AccountAuditService::class)->logRolePermissionsChanged($role->fresh(), $request->user(), $old, $new);

        return back()->with('success', 'Pemetaan permission peran berhasil diperbarui.');
    }

    private function audit(string $event, Role $role, User $actor, array $old, array $new): void
    {
        ActivityLog::create([
            'causer_id' => $actor->id,
            'subject_type' => Role::class,
            'subject_id' => $role->id,
            'event' => $event,
            'description' => "Peran {$role->name} diperbarui",
            'properties' => ['old' => $old, 'new' => $new],
        ]);
    }

    private function authorizeSystemAdministration(): void
    {
        $actor = Auth::user();
        abort_unless($actor instanceof User, 403);
        $this->systemAdministration->assertCanManage($actor, SystemAdministrationAuthorizationService::ROLES);
    }

    private function uniqueSlug(string $value): string
    {
        $base = Str::slug($value, '_') ?: 'role';
        $slug = $base;
        $suffix = 2;

        while (Role::query()->where('slug', $slug)->exists()) {
            $slug = $base.'_'.$suffix++;
        }

        return $slug;
    }

    private function assertExistingAssignmentsRemainValid(Role $role, int $newLevel): void
    {
        $assignments = OrganizationAssignment::query()
            ->current()
            ->with(['parent.role', 'user.role'])
            ->where(function ($query) use ($role) {
                $query->whereHas('parent', fn ($parent) => $parent->where('role_id', $role->id))
                    ->orWhereHas('user', fn ($user) => $user->where('role_id', $role->id));
            })
            ->get();

        foreach ($assignments as $assignment) {
            $parentLevel = $assignment->parent?->role_id === $role->id
                ? $newLevel
                : (int) ($assignment->parent?->role?->authority_level ?? 0);
            $childLevel = $assignment->user?->role_id === $role->id
                ? $newLevel
                : (int) ($assignment->user?->role?->authority_level ?? 0);

            if ($parentLevel < $childLevel) {
                throw ValidationException::withMessages([
                    'authority_level' => 'Tingkat baru akan membuat hubungan organisasi aktif menjadi tidak valid.',
                ]);
            }
        }
    }
}
