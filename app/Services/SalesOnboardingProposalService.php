<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesOnboardingProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOnboardingProposalService
{
    public function __construct(
        private readonly WorkspaceAccessService $workspace,
        private readonly UserAdministrationService $administration,
        private readonly UserProvisioningService $provisioning,
        private readonly BranchAssignmentService $branches,
        private readonly ProjectAssignmentService $projects,
        private readonly SalesCoordinatorAssignmentService $coordinators,
        private readonly AccountAuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): SalesOnboardingProposal
    {
        abort_unless($actor->hasPrimaryRole('admin') && $actor->hasPermission('users.propose_sales'), 403);

        $role = Role::query()->whereKey((int) $data['role_id'])->where('is_active', true)->first();
        $branch = Branch::query()->whereKey((int) $data['branch_id'])->where('is_active', true)->first();
        $project = LeadMaster::query()->whereKey((int) $data['project_id'])->where('is_active', true)->first();
        $coordinator = User::query()->with('role')->whereKey((int) $data['coordinator_user_id'])->first();

        abort_unless($role?->slug === 'sales', 422, 'Proposal onboarding hanya dapat dibuat untuk primary role Sales.');
        abort_unless($branch !== null && $this->workspace->canViewBranch($actor, $branch), 403);
        abort_unless($project !== null
            && (int) $project->branch_id === (int) $branch->id
            && $this->workspace->accessibleProjectsQuery($actor)->whereKey($project->id)->exists(), 403);
        abort_unless($this->coordinatorIsInScope($actor, $coordinator, $branch), 403);

        return DB::transaction(function () use ($actor, $data, $branch, $project, $coordinator, $role): SalesOnboardingProposal {
            $proposal = SalesOnboardingProposal::create([
                'name' => $data['name'],
                'email' => mb_strtolower(trim((string) $data['email'])),
                'phone' => $data['phone'] ?? null,
                'role_id' => $role->id,
                'branch_id' => $branch->id,
                'project_id' => $project->id,
                'coordinator_user_id' => $coordinator->id,
                'submitted_by' => $actor->id,
                'submitted_scope' => [
                    'branch_id' => $branch->id,
                    'project_id' => $project->id,
                    'coordinator_user_id' => $coordinator->id,
                ],
                'submitted_at' => now(),
            ]);

            $this->logProposal($proposal, 'sales_onboarding_proposed', $actor, [
                'status' => SalesOnboardingProposal::STATUS_PENDING,
                'branch_id' => $branch->id,
                'project_id' => $project->id,
                'coordinator_user_id' => $coordinator->id,
            ]);

            return $proposal;
        });
    }

    public function approve(SalesOnboardingProposal $proposal, User $actor, ?string $decisionToken = null): User
    {
        abort_unless($actor->isSuperadmin() && $actor->hasPermission('users.approve_sales'), 403);

        return DB::transaction(function () use ($proposal, $actor, $decisionToken): User {
            $proposal = SalesOnboardingProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $this->assertPendingDecision($proposal, $decisionToken);
            if (User::query()->where('email', $proposal->email)->exists()) {
                throw ValidationException::withMessages(['proposal' => 'Email proposal sudah digunakan oleh akun lain.']);
            }

            $sales = $this->provisioning->createDirectlyActivated([
                'name' => $proposal->name,
                'email' => $proposal->email,
                'phone' => $proposal->phone,
                'role_id' => $proposal->role_id,
                'branch_id' => $proposal->branch_id,
            ], 'Marison123', $actor);
            $this->branches->assign($sales, [$proposal->branch_id], $proposal->branch_id, $actor);
            $this->projects->assign($sales, [$proposal->project_id], $proposal->project_id, $actor);
            $coordinator = User::query()->findOrFail($proposal->coordinator_user_id);
            if (! $this->coordinatorIsCurrent($coordinator, Branch::query()->findOrFail($proposal->branch_id))) {
                throw ValidationException::withMessages(['proposal' => 'Koordinator proposal tidak lagi aktif pada KC/KCP yang dipilih.']);
            }
            $this->coordinators->assign($sales, $coordinator);

            $proposal->forceFill([
                'status' => SalesOnboardingProposal::STATUS_APPROVED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_token' => str()->uuid()->toString(),
            ])->save();

            $this->audit->log('user_created', $sales, $actor);
            $this->audit->log('user_directly_activated', $sales, $actor, [], [
                'role_id' => $sales->role_id,
                'branch_ids' => [$proposal->branch_id],
                'project_ids' => [$proposal->project_id],
                'coordinator_user_id' => $proposal->coordinator_user_id,
                'provisioning_mode' => 'sales_proposal',
            ]);
            $this->logProposal($proposal, 'sales_onboarding_approved', $actor, [
                'status' => SalesOnboardingProposal::STATUS_APPROVED,
                'user_id' => $sales->id,
            ]);

            return $sales;
        });
    }

    public function reject(SalesOnboardingProposal $proposal, User $actor, ?string $decisionToken = null, ?string $reason = null): SalesOnboardingProposal
    {
        abort_unless($actor->isSuperadmin() && $actor->hasPermission('users.approve_sales'), 403);

        return DB::transaction(function () use ($proposal, $actor, $decisionToken, $reason): SalesOnboardingProposal {
            $proposal = SalesOnboardingProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $this->assertPendingDecision($proposal, $decisionToken);
            $proposal->forceFill([
                'status' => SalesOnboardingProposal::STATUS_REJECTED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_reason' => $reason,
                'decision_token' => str()->uuid()->toString(),
            ])->save();
            $this->logProposal($proposal, 'sales_onboarding_rejected', $actor, [
                'status' => SalesOnboardingProposal::STATUS_REJECTED,
            ]);

            return $proposal;
        });
    }

    private function coordinatorIsInScope(User $actor, ?User $coordinator, Branch $branch): bool
    {
        return $this->coordinatorIsCurrent($coordinator, $branch)
            && $this->administration->visibleQuery($actor)->whereKey($coordinator)->exists();
    }

    private function coordinatorIsCurrent(?User $coordinator, Branch $branch): bool
    {
        return $coordinator !== null
            && $coordinator->isAccountActive()
            && $coordinator->hasPrimaryRole('sales_coordinator')
            && (int) $coordinator->branch_id === (int) $branch->id
            && $this->workspace->canViewBranch($coordinator, $branch);
    }

    private function assertPendingDecision(SalesOnboardingProposal $proposal, ?string $decisionToken): void
    {
        abort_if($proposal->status !== SalesOnboardingProposal::STATUS_PENDING, 409, 'Proposal sudah diputuskan.');
        if ($decisionToken !== null && ! hash_equals($proposal->decision_token, $decisionToken)) {
            abort(409, 'Proposal telah berubah. Muat ulang daftar proposal.');
        }
    }

    /** @param array<string, mixed> $properties */
    private function logProposal(SalesOnboardingProposal $proposal, string $event, User $actor, array $properties): void
    {
        ActivityLog::create([
            'causer_id' => $actor->id,
            'subject_type' => SalesOnboardingProposal::class,
            'subject_id' => $proposal->id,
            'event' => $event,
            'description' => "Proposal onboarding Sales {$proposal->email}: {$event}",
            'properties' => $properties,
        ]);
    }
}
