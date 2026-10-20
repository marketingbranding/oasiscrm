<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\SalesLead;
use App\Models\SalesLeadTarget;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class SalesLeadTargetService
{
    public function __construct(
        private readonly SalesTeamScopeService $salesTeam,
        private readonly WorkspaceAccessService $workspace,
    ) {}

    public function visibleSales(User $actor): Collection
    {
        $query = User::query()->with('branch:id,name')
            ->where('account_status', AccountStatus::Active->value)
            ->where('is_active', true)
            ->whereHas('role', fn (Builder $role) => $role->where('slug', 'sales'));
        if ($actor->isSales()) {
            $query->whereKey($actor->id);
        } elseif ($actor->hasPrimaryRole('sales_coordinator')) {
            $query->whereIn('id', $this->salesTeam->currentSalesQuery($actor)->pluck('id'));
        } elseif (! $actor->hasPermission('sales_targets.view_all')) {
            $query->whereIn('branch_id', $this->workspace->accessibleBranchIds($actor));
        }

        return $query->orderBy('name')->get();
    }

    public function summary(User $actor, int $year, int $month, ?int $salesUserId = null): Collection
    {
        $sales = $this->visibleSales($actor);
        if ($salesUserId !== null) {
            $sales = $sales->where('id', $salesUserId)->values();
        }
        $ids = $sales->pluck('id');
        $targets = SalesLeadTarget::query()->whereIn('sales_user_id', $ids)
            ->where('period_year', $year)->where('period_month', $month)->get()->keyBy('sales_user_id');
        $actuals = SalesLead::query()->whereIn('sales_user_id', $ids)
            ->whereYear('lead_date', $year)->whereMonth('lead_date', $month)
            ->selectRaw('sales_user_id, COUNT(*) AS total')->groupBy('sales_user_id')->pluck('total', 'sales_user_id');

        return $sales->map(function (User $sales) use ($targets, $actuals) {
            $target = $targets->get($sales->id);
            $actual = (int) ($actuals[$sales->id] ?? 0);

            return (object) [
                'sales' => $sales,
                'target' => $target?->lead_target,
                'actual' => $actual,
                'achievement' => $target && $target->lead_target > 0 ? round(($actual / $target->lead_target) * 100, 1) : null,
            ];
        });
    }

    public function upsert(User $actor, array $data): SalesLeadTarget
    {
        $sales = User::query()->with('role')->find((int) $data['sales_user_id']);
        if (! $sales || ! $sales->isAccountActive() || ! $sales->is_active || ! $sales->isSales()) {
            throw new InvalidArgumentException('Target hanya dapat dibuat untuk Sales aktif.');
        }
        $target = SalesLeadTarget::query()->firstOrNew([
            'sales_user_id' => (int) $data['sales_user_id'],
            'period_year' => (int) $data['period_year'],
            'period_month' => (int) $data['period_month'],
        ]);
        $target->fill(['lead_target' => (int) $data['lead_target'], 'updated_by' => $actor->id]);
        $target->created_by ??= $actor->id;
        $target->save();

        return $target;
    }
}
