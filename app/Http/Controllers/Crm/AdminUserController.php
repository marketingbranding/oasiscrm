<?php

namespace App\Http\Controllers\Crm;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminUserFilterRequest;
use App\Http\Requests\AdminUserStatusRequest;
use App\Http\Requests\AdminUserStoreRequest;
use App\Http\Requests\AdminUserUpdateRequest;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountAuditService;
use App\Services\BranchAssignmentService;
use App\Services\OptimisticLockService;
use App\Services\OrganizationScopeService;
use App\Services\ProjectAssignmentService;
use App\Services\ReportingHierarchyService;
use App\Services\SalesCoordinatorAssignmentService;
use App\Services\UserAccountService;
use App\Services\UserAdministrationService;
use App\Services\UserInvitationService;
use App\Services\UserLifecycleService;
use App\Services\UserProvisioningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly UserAdministrationService $administration,
        private readonly BranchAssignmentService $branches,
        private readonly ProjectAssignmentService $projects,
        private readonly ReportingHierarchyService $hierarchy,
        private readonly SalesCoordinatorAssignmentService $salesCoordinators,
        private readonly UserInvitationService $invitations,
        private readonly UserProvisioningService $provisioning,
        private readonly UserAccountService $accounts,
        private readonly UserLifecycleService $lifecycle,
        private readonly OptimisticLockService $locks,
        private readonly AccountAuditService $audit,
    ) {}

    public function index(AdminUserFilterRequest $request): View
    {
        $filters = $request->validated();
        $users = $this->administration->visibleQuery($request->user())
            ->with(['role', 'roles', 'branch', 'branches', 'assignedProjects.branch', 'supervisor', 'latestInvitation'])
            ->when($filters['search'] ?? null, fn (Builder $q, string $search) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($filters['account_status'] ?? null, fn (Builder $q, string $status) => $q->where('account_status', $status))
            ->when($filters['role_id'] ?? null, fn (Builder $q, $id) => $q->where('role_id', $id))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $id) => $q->where(fn (Builder $inner) => $inner->where('branch_id', $id)->orWhereHas('branches', fn (Builder $b) => $b->whereKey($id))))
            ->when($filters['project_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('assignedProjects', fn (Builder $p) => $p->whereKey($id)))
            ->when($filters['supervisor_user_id'] ?? null, fn (Builder $q, $id) => $q->where('supervisor_user_id', $id))
            ->when($filters['invitation_status'] ?? null, function (Builder $q, string $status) {
                match ($status) {
                    'draft' => $q->where('account_status', AccountStatus::PendingInvitation->value),
                    'usable' => $q->whereHas('latestInvitation', fn (Builder $i) => $i->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())),
                    'expired' => $q->whereHas('latestInvitation', fn (Builder $i) => $i->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '<=', now())),
                    'accepted' => $q->whereHas('latestInvitation', fn (Builder $i) => $i->whereNotNull('accepted_at')),
                    'revoked' => $q->whereHas('latestInvitation', fn (Builder $i) => $i->whereNotNull('revoked_at')),
                };
            })
            ->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')
            ->paginate(20)->withQueryString();

        return view('crm.admin-users.index', array_merge($this->options($request->user()), compact('users', 'filters')));
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('crm.admin-users.create', $this->options(request()->user()));
    }

    public function store(AdminUserStoreRequest $request): RedirectResponse
    {
        $actor = $request->user();
        $data = $request->validated();
        $role = Role::findOrFail($data['role_id']);
        $branchIds = $this->branchIds($data);
        $projectIds = $this->projectIds($data);
        $this->administration->assertCanAssignRole($actor, $role);
        $this->assertAssignmentPermissions($actor, $branchIds, $projectIds, $data['supervisor_user_id'] ?? null);

        $direct = $data['provisioning_mode'] === 'direct';
        abort_if($direct && ! $actor->isSuperadmin(), 403);

        $user = DB::transaction(function () use ($actor, $data, $branchIds, $projectIds, $direct) {
            $attributes = [
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
                'role_id' => $data['role_id'],
            ];
            $user = $direct
                ? $this->provisioning->createDirectlyActivated($attributes, $data['temporary_password'], $actor)
                : $this->invitations->createDraft($attributes, $actor);
            $this->branches->assign($user, $branchIds, (int) $data['branch_id'], $actor);
            $this->projects->assign($user, $projectIds, $this->nullableInt($data['primary_project_id'] ?? null), $actor);
            $this->hierarchy->assignSupervisor($user, $this->nullableInt($data['supervisor_user_id'] ?? null), $actor);
            $this->audit->log('user_created', $user, $actor);
            if ($direct) {
                $this->audit->log('user_directly_activated', $user, $actor, [], [
                    'role_id' => $data['role_id'],
                    'branch_ids' => $branchIds,
                    'project_ids' => $projectIds,
                    'provisioning_mode' => 'direct',
                ]);
            }

            return $user;
        });

        if ($direct) {
            return redirect()->route('admin-users.show', $user)->with('success', 'Akun berhasil dibuat dan diaktifkan. Pengguna wajib mengganti password saat login pertama.');
        }

        if ($data['submit_action'] === 'send' || ($data['send_immediately'] ?? false)) {
            try {
                $this->invitations->send($user, $actor);
            } catch (Throwable $exception) {
                return redirect()->route('admin-users.show', $user)->with('warning', $exception->getMessage());
            }

            return redirect()->route('admin-users.show', $user)->with('success', 'Akun dibuat dan undangan berhasil dikirim.');
        }

        return redirect()->route('admin-users.show', $user)->with('success', 'Draft akun berhasil disimpan.');
    }

    public function show(User $admin_user): View
    {
        $this->authorize('view', $admin_user);
        $admin_user->load(['role', 'roles', 'branch', 'branches', 'assignedProjects.branch', 'supervisor', 'currentCoordinatorSales', 'invitations.inviter', 'activityLogs.causer']);
        $actor = request()->user();
        $deletionBlockers = $actor->hasPermission('users.delete_permanently')
            ? $this->lifecycle->deletionBlockers($admin_user)
            : [];

        return view('crm.admin-users.show', ['user' => $admin_user, 'deletionBlockers' => $deletionBlockers]);
    }

    public function edit(User $admin_user): View
    {
        $this->authorize('update', $admin_user);
        $admin_user->load(['branches', 'assignedProjects', 'supervisor', 'currentCoordinatorSales']);
        $options = $this->options(request()->user());
        $options['coordinatorSales'] = $admin_user->hasPrimaryRole('sales_coordinator')
            ? $this->manageableSales(request()->user())->get()
            : collect();

        return view('crm.admin-users.edit', array_merge($options, ['user' => $admin_user, 'lockToken' => $this->locks->token($admin_user)]));
    }

    public function update(AdminUserUpdateRequest $request, User $admin_user): RedirectResponse|JsonResponse
    {
        $actor = $request->user();
        abort_if($admin_user->account_status === AccountStatus::Anonymized, 403, 'Akun anonim tidak dapat diedit.');
        $data = $request->validated();
        $role = Role::findOrFail($data['role_id']);
        $branchIds = $this->branchIds($data);
        $projectIds = $this->projectIds($data);
        $this->administration->assertCanManage($actor, $admin_user, 'users.update');
        if ($admin_user->role_id !== $role->id) {
            $this->administration->assertCanAssignRole($actor, $role, $admin_user);
            if ($admin_user->isSuperadmin() && ! $role->is_superadmin) {
                $this->administration->assertNotLastActiveSuperadmin($admin_user);
            }
        }
        $this->assertAssignmentPermissions($actor, $branchIds, $projectIds, $data['supervisor_user_id'] ?? null, $admin_user);

        return $this->locks->execute($request, $admin_user, $data['expected_updated_at'], function (User $user) use ($actor, $data, $branchIds, $projectIds) {
            $old = $user->only(['name', 'email', 'phone', 'role_id']);
            $user->fill(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'role_id' => $data['role_id'], 'updated_by' => $actor->id]);
            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }
            $user->save();
            $this->branches->assign($user, $branchIds, (int) $data['branch_id'], $actor);
            $this->projects->assign($user, $projectIds, $this->nullableInt($data['primary_project_id'] ?? null), $actor);
            $this->hierarchy->assignSupervisor($user, $this->nullableInt($data['supervisor_user_id'] ?? null), $actor);
            $this->salesCoordinators->sync($user, (array) ($data['coordinator_sales_ids'] ?? []));
            $this->audit->log('user_updated', $user, $actor, $old, $user->only(['name', 'email', 'phone', 'role_id']));

            return redirect()->route('admin-users.show', $user)->with('success', 'Data pengguna berhasil diperbarui.');
        });
    }

    public function sendInvitation(User $admin_user): RedirectResponse
    {
        return $this->issueInvitation($admin_user, false);
    }

    public function resendInvitation(User $admin_user): RedirectResponse
    {
        return $this->issueInvitation($admin_user, true);
    }

    public function revokeInvitation(User $admin_user): RedirectResponse
    {
        $this->administration->assertCanManage(request()->user(), $admin_user, 'users.invite');
        $invitation = $admin_user->invitations()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->firstOrFail();
        $this->invitations->revoke($invitation, request()->user());

        return back()->with('success', 'Undangan berhasil dicabut.');
    }

    public function suspend(AdminUserStatusRequest $request, User $admin_user): RedirectResponse
    {
        return $this->changeStatus($request, $admin_user, 'suspend');
    }

    public function deactivate(AdminUserStatusRequest $request, User $admin_user): RedirectResponse
    {
        return $this->changeStatus($request, $admin_user, 'deactivate');
    }

    public function reactivate(AdminUserStatusRequest $request, User $admin_user): RedirectResponse
    {
        return $this->changeStatus($request, $admin_user, 'reactivate');
    }

    public function resetAccess(User $admin_user): RedirectResponse
    {
        $actor = request()->user();
        $this->administration->assertCanManage($actor, $admin_user, 'users.reset_password');
        abort_if($admin_user->account_status === AccountStatus::Anonymized, 403, 'Akun anonim tidak dapat direset aksesnya.');
        if (in_array($admin_user->account_status, [AccountStatus::PendingInvitation, AccountStatus::Invited], true)) {
            return $this->issueInvitation($admin_user, true);
        }
        $status = Password::sendResetLink(['email' => $admin_user->email]);
        $this->audit->log('password_reset_requested', $admin_user, $actor);

        return back()->with($status === Password::RESET_LINK_SENT ? 'success' : 'warning', __($status));
    }

    public function anonymize(AdminUserStatusRequest $request, User $admin_user): RedirectResponse
    {
        $actor = $request->user();
        $this->administration->assertCanManage($actor, $admin_user, 'users.anonymize');
        try {
            $this->lifecycle->anonymize($admin_user, $actor, $request->validated('reason'));
        } catch (\DomainException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        return back()->with('success', 'Akun berhasil dianonimkan. Data pribadi dilepas dan email dapat dipakai ulang.');
    }

    public function releaseEmail(AdminUserStatusRequest $request, User $admin_user): RedirectResponse
    {
        $actor = $request->user();
        $this->administration->assertCanManage($actor, $admin_user, 'users.release_email');
        try {
            $this->lifecycle->releaseEmail($admin_user, $actor, $request->validated('reason'));
        } catch (\DomainException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        return back()->with('success', 'Email akun berhasil dilepas untuk dipakai ulang.');
    }

    public function destroy(AdminUserStatusRequest $request, User $admin_user): RedirectResponse
    {
        $actor = $request->user();
        $this->administration->assertCanManage($actor, $admin_user, 'users.delete_permanently');
        $this->administration->assertNotLastActiveSuperadmin($admin_user);
        try {
            $this->lifecycle->permanentlyDeleteDraft($admin_user, $actor, $request->validated('reason'));
        } catch (\DomainException $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        return back()->with('success', 'Draf akun aman dan berhasil dihapus permanen.');
    }

    private function issueInvitation(User $user, bool $resend): RedirectResponse
    {
        $actor = request()->user();
        $this->administration->assertCanManage($actor, $user, 'users.invite');
        try {
            $resend ? $this->invitations->resend($user, $actor) : $this->invitations->send($user, $actor);
        } catch (Throwable $exception) {
            return back()->with('warning', $exception->getMessage());
        }

        return back()->with('success', $resend ? 'Undangan berhasil dikirim ulang.' : 'Undangan berhasil dikirim.');
    }

    private function changeStatus(AdminUserStatusRequest $request, User $user, string $action): RedirectResponse
    {
        $permission = "users.{$action}";
        $this->administration->assertCanManage($request->user(), $user, $permission);
        if ($action !== 'reactivate') {
            $this->administration->assertNotLastActiveSuperadmin($user);
        }
        $allowed = match ($action) {
            'suspend' => $user->account_status === AccountStatus::Active,
            'reactivate' => in_array($user->account_status, [AccountStatus::Suspended, AccountStatus::Inactive], true),
            'deactivate' => $user->account_status !== AccountStatus::Inactive && $user->account_status !== AccountStatus::Anonymized,
        };
        if (! $allowed) {
            return back()->with('warning', 'Perubahan status tersebut tidak berlaku untuk kondisi akun saat ini.');
        }
        try {
            $this->accounts->{$action}($user, $request->user());
        } catch (\DomainException $exception) {
            return back()->with('warning', $exception->getMessage());
        }
        $this->audit->log("account_{$action}_reason", $user, $request->user(), [], ['reason' => $request->validated('reason')]);

        return back()->with('success', 'Status akun berhasil diperbarui.');
    }

    private function options(User $actor): array
    {
        $all = $actor->isSuperadmin() || $actor->hasPrimaryRole('pusat');
        $branchIds = $all ? Branch::where('is_active', true)->pluck('id')->all() : app(OrganizationScopeService::class)->branchIds($actor);
        $branches = Branch::where('is_active', true)->whereIn('id', $branchIds)->forDropdown()->get();
        $projects = LeadMaster::with('branch')->where('is_active', true)->whereIn('branch_id', $branchIds)->orderBy('project_name')->get();
        $supervisors = $this->administration->visibleQuery($actor)->where('account_status', AccountStatus::Active->value)->with('role')->orderBy('name')->get();

        return ['roles' => $this->administration->availableRoles($actor), 'branches' => $branches, 'projects' => $projects, 'projectsByBranch' => $projects->groupBy('branch_id'), 'supervisors' => $supervisors];
    }

    private function manageableSales(User $actor): Builder
    {
        return $this->administration->visibleQuery($actor)
            ->where('account_status', AccountStatus::Active->value)
            ->whereHas('role', fn (Builder $query) => $query->where('slug', 'sales'))
            ->orderBy('name');
    }

    private function branchIds(array $data): array
    {
        return collect([...(array) ($data['branch_ids'] ?? []), $data['branch_id']])->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function projectIds(array $data): array
    {
        return collect([...(array) ($data['assigned_project_ids'] ?? []), $data['primary_project_id'] ?? null])->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function nullableInt(mixed $value): ?int
    {
        return filled($value) ? (int) $value : null;
    }

    private function assertAssignmentPermissions(User $actor, array $branchIds, array $projectIds, mixed $supervisorId, ?User $target = null): void
    {
        if ($target) {
            $this->administration->assertCanManage($actor, $target, 'users.update');
            $currentBranches = $target->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->push((int) $target->branch_id)->filter()->unique()->sort()->values()->all();
            $currentProjects = $target->assignedProjects()->pluck('lead_master.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $currentPrimary = $target->primaryAssignedProject()->value('lead_master.id');
            abort_if($currentBranches !== collect($branchIds)->sort()->values()->all() && ! $actor->hasPermission('users.assign_branches'), 403);
            abort_if(($currentProjects !== collect($projectIds)->sort()->values()->all() || (int) $currentPrimary !== (int) request('primary_project_id')) && ! $actor->hasPermission('users.assign_projects'), 403);
            abort_if((int) $target->supervisor_user_id !== (int) $supervisorId && ! $actor->hasPermission('users.assign_supervisor'), 403);
        } else {
            abort_unless($actor->hasPermission('users.assign_branches'), 403);
            abort_if($projectIds !== [] && ! $actor->hasPermission('users.assign_projects'), 403);
            abort_if(filled($supervisorId) && ! $actor->hasPermission('users.assign_supervisor'), 403);
        }
        $this->administration->assertAssignmentsInScope($actor, $branchIds, $projectIds);
    }
}
