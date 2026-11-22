<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\SalesLeadUnownedQueue;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UserAccountService
{
    public function __construct(
        private readonly AccountAuditService $audit,
        private readonly UserLifecycleService $lifecycle,
    ) {}

    public function recordSuccessfulLogin(User $user, Request $request): void
    {
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => Str::limit((string) $request->ip(), 45, ''),
            'last_login_user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ])->save();

        $this->audit->log('login_success', $user, $user, [], [
            'ip' => $user->last_login_ip,
        ]);
    }

    public function changePassword(User $user, string $password, ?string $currentSessionId, string $event, ?User $actor = null): void
    {
        $user->forceFill([
            'password' => Hash::make($password),
            'password_changed_at' => now(),
            'must_change_password' => false,
            'temporary_password_expires_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        $this->deleteOtherSessions($user, $currentSessionId);
        $this->audit->log($event, $user, $actor ?? $user);
    }

    public function suspend(User $user, User $actor): void
    {
        DB::transaction(function () use ($user, $actor) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->lifecycle->assertCriticalCapabilityContinuity($user);
            $old = $user->account_status->value;
            $user->update([
                'account_status' => AccountStatus::Suspended,
                'suspended_at' => now(),
                'updated_by' => $actor->id,
            ]);
            $this->lifecycle->revokeUserTokens($user);
            $this->audit->log('account_suspended', $user, $actor, ['account_status' => $old], ['account_status' => AccountStatus::Suspended->value]);
        });
    }

    public function deactivate(User $user, User $actor): void
    {
        DB::transaction(function () use ($user, $actor) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->lifecycle->assertCriticalCapabilityContinuity($user);
            $old = $user->account_status->value;
            $this->queueActiveSalesLeads($user);
            $user->update([
                'account_status' => AccountStatus::Inactive,
                'deactivated_at' => now(),
                'updated_by' => $actor->id,
            ]);
            $this->lifecycle->revokeUserTokens($user);
            $this->audit->log('account_deactivated', $user, $actor, ['account_status' => $old], ['account_status' => AccountStatus::Inactive->value]);
        });
    }

    public function activeLeadCount(User $sales): int
    {
        return SalesLead::query()->where('sales_user_id', $sales->id)->activeForLeadViews()->where(fn ($query) => $query->whereNull('lead_stage')->orWhereNotIn('lead_stage', ['utj', 'gugur']))->count();
    }

    private function queueActiveSalesLeads(User $sales): void
    {
        $activeCoordinatorId = SalesCoordinatorSales::query()
            ->where('sales_user_id', $sales->id)
            ->where('is_active', true)
            ->whereNull('ended_at')
            ->orderByDesc('id')
            ->value('coordinator_user_id');
        $lastCoordinatorId = $activeCoordinatorId ?? SalesCoordinatorSales::query()
            ->where('sales_user_id', $sales->id)
            ->orderByDesc('ended_at')
            ->orderByDesc('id')
            ->value('coordinator_user_id');
        $coordinatorId = User::query()
            ->whereKey($lastCoordinatorId)
            ->where('account_status', AccountStatus::Active->value)
            ->where('is_active', true)
            ->value('id');

        SalesLead::query()
            ->where('sales_user_id', $sales->id)
            ->activeForLeadViews()
            ->where(function ($query): void {
                $query->whereNull('lead_stage')->orWhereNotIn('lead_stage', ['utj', 'gugur']);
            })
            ->lockForUpdate()
            ->get()
            ->each(function (SalesLead $lead) use ($sales, $coordinatorId, $lastCoordinatorId): void {
                SalesLeadUnownedQueue::query()->updateOrCreate(
                    ['sales_lead_id' => $lead->id, 'status' => 'open'],
                    [
                        'former_sales_user_id' => $sales->id,
                        'last_active_coordinator_user_id' => $lastCoordinatorId,
                        'queue_coordinator_user_id' => $coordinatorId,
                    ],
                );
            });
    }

    public function reactivate(User $user, User $actor): void
    {
        $old = $user->account_status->value;
        $user->update([
            'account_status' => AccountStatus::Active,
            'suspended_at' => null,
            'deactivated_at' => null,
            'updated_by' => $actor->id,
        ]);
        $this->audit->log('account_reactivated', $user, $actor, ['account_status' => $old], ['account_status' => AccountStatus::Active->value]);
    }

    public function deleteOtherSessions(User $user, ?string $exceptSessionId = null): void
    {
        if (! Schema::hasTable('sessions')) {
            return;
        }

        $query = DB::table('sessions')->where('user_id', $user->id);
        if ($exceptSessionId !== null) {
            $query->where('id', '!=', $exceptSessionId);
        }
        $query->delete();
    }
}
