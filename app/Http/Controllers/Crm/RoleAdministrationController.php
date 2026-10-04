<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\RolePermissionUpdateRequest;
use App\Http\Requests\RoleReportingRulesUpdateRequest;
use App\Http\Requests\RoleStoreRequest;
use App\Http\Requests\RoleUpdateRequest;
use App\Models\ActivityLog;
use App\Models\OrganizationAssignment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleReportingRule;
use App\Models\User;
use App\Services\AccountAuditService;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoleAdministrationController extends Controller
{
    public function index(): View
    {
        $roles = Role::query()->with('permissions')->withCount('users')->orderBy('authority_level')->orderBy('name')->get();
        $permissions = Permission::query()->orderBy('group_name')->orderBy('name')->get()->groupBy('group_name');
        $rules = RoleReportingRule::query()->get()->keyBy(fn (RoleReportingRule $rule) => "{$rule->parent_role_id}:{$rule->child_role_id}");

        return view('crm.roles.index', [
            'roles' => $roles,
            'permissions' => $permissions,
            'permissionGroupDescriptions' => PermissionCatalog::groupDescriptions(),
            'rules' => $rules,
        ]);
    }

    public function store(RoleStoreRequest $request): RedirectResponse
    {
        $role = Role::create([...$request->validated(), 'is_superadmin' => false, 'is_active' => true]);
        $this->audit('role_created', $role, $request->user(), [], $role->only(['name', 'slug', 'authority_level']));

        return back()->with('success', 'Peran baru berhasil dibuat.');
    }

    public function update(RoleUpdateRequest $request, Role $role): RedirectResponse
    {
        $data = $request->validated();
        if ($role->is_superadmin && ! $data['is_active']) {
            throw ValidationException::withMessages(['is_active' => 'Superadmin tidak dapat dinonaktifkan.']);
        }
        if (! $data['is_active'] && $role->users()->exists()) {
            throw ValidationException::withMessages(['is_active' => 'Peran dengan pengguna aktif tidak dapat dinonaktifkan.']);
        }

        $old = $role->only(['name', 'description', 'authority_level', 'is_active']);
        $role->update($data);
        $this->audit('role_updated', $role, $request->user(), $old, $role->only(array_keys($old)));

        return back()->with('success', 'Konfigurasi peran berhasil diperbarui.');
    }

    public function updatePermissions(RolePermissionUpdateRequest $request, Role $role): RedirectResponse
    {
        if ($role->is_superadmin) {
            throw ValidationException::withMessages(['role' => 'Permission Superadmin mengikuti registry wildcard dan tidak diedit melalui matrix.']);
        }

        $old = $role->permissions()->pluck('slug')->all();
        $role->permissions()->sync($request->validated('permission_ids', []));
        $new = $role->fresh()->permissions()->pluck('slug')->all();
        app(AccountAuditService::class)->logRolePermissionsChanged($role->fresh(), $request->user(), $old, $new);

        return back()->with('success', 'Pemetaan permission peran berhasil diperbarui.');
    }

    public function updateReportingRules(RoleReportingRulesUpdateRequest $request): RedirectResponse
    {
        foreach ($request->validated('rules') as $ruleData) {
            $parentRoleId = (int) $ruleData['parent_role_id'];
            $childRoleId = (int) $ruleData['child_role_id'];
            $isAllowed = (bool) $ruleData['is_allowed'];
            if ($parentRoleId === $childRoleId) {
                continue;
            }
            if (! $isAllowed && OrganizationAssignment::query()->current()
                ->whereHas('parent', fn ($query) => $query->where('role_id', $parentRoleId))
                ->whereHas('user', fn ($query) => $query->where('role_id', $childRoleId))
                ->exists()) {
                throw ValidationException::withMessages(['rules' => 'Aturan tidak dapat dinonaktifkan karena masih dipakai assignment aktif.']);
            }

            RoleReportingRule::updateOrCreate(
                ['parent_role_id' => $parentRoleId, 'child_role_id' => $childRoleId],
                ['is_allowed' => $isAllowed],
            );
        }

        ActivityLog::create([
            'causer_id' => $request->user()->id,
            'subject_type' => RoleReportingRule::class,
            'subject_id' => 0,
            'event' => 'role_reporting_rules_changed',
            'description' => 'Aturan relasi reporting role diperbarui',
            'properties' => ['rules' => $request->validated('rules')],
        ]);

        return back()->with('success', 'Aturan relasi reporting berhasil diperbarui.');
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
}
