<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\SalesLead;
use App\Models\SalesLeadTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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

    public function summary(User $actor, int|CarbonImmutable $yearOrMonth, ?int $month = null, ?int $salesUserId = null): Collection|array
    {
        if ($yearOrMonth instanceof CarbonImmutable) {
            return $this->summaryForSales($actor, $yearOrMonth);
        }

        $sales = $this->visibleSales($actor);
        if ($salesUserId !== null) {
            $sales = $sales->where('id', $salesUserId)->values();
        }
        $ids = $sales->pluck('id');
        $targets = SalesLeadTarget::query()->whereIn('sales_user_id', $ids)
            ->where('period_year', $yearOrMonth)->where('period_month', $month)->get()->keyBy('sales_user_id');
        $actuals = SalesLead::query()->whereIn('sales_user_id', $ids)
            ->whereYear('lead_date', $yearOrMonth)->whereMonth('lead_date', $month)
            ->selectRaw('sales_user_id, COUNT(*) AS total')->groupBy('sales_user_id')->pluck('total', 'sales_user_id');

        return $sales->map(function (User $sales) use ($targets, $actuals): object {
            $target = $targets->get($sales->id);
            $actual = (int) ($actuals[$sales->id] ?? 0);
            $targetValue = $target?->lead_target ?? $target?->target_leads;

            return (object) [
                'sales' => $sales,
                'target' => $targetValue,
                'actual' => $actual,
                'achievement' => $targetValue && $targetValue > 0 ? round(($actual / $targetValue) * 100, 1) : null,
            ];
        });
    }

    public function upsert(User $first, array|CarbonImmutable $second, ?int $third = null, ?User $fourth = null): SalesLeadTarget
    {
        if (is_array($second)) {
            return $this->upsertScoped($first, $second);
        }

        if (! $fourth?->isSuperadmin()) {
            throw new AccessDeniedHttpException('Hanya Superadmin yang dapat mengatur target Lead.');
        }

        $month = $second->startOfMonth();
        $target = SalesLeadTarget::query()
            ->where('sales_user_id', $first->id)
            ->whereDate('target_month', $month->toDateString())
            ->first() ?? new SalesLeadTarget([
                'sales_user_id' => $first->id,
                'target_month' => $month->toDateString(),
            ]);
        $target->fill([
            'target_leads' => $third,
            'period_year' => $month->year,
            'period_month' => $month->month,
            'lead_target' => $third,
            'updated_by' => $fourth->id,
        ]);
        $target->created_by ??= $fourth->id;
        $target->save();

        return $target->refresh();
    }

    private function summaryForSales(User $sales, CarbonImmutable $month): array
    {
        $monthStart = $month->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $target = SalesLeadTarget::query()
            ->where('sales_user_id', $sales->id)
            ->where(function (Builder $query) use ($monthStart): void {
                $query->whereDate('target_month', $monthStart->toDateString())
                    ->orWhere(function (Builder $legacy) use ($monthStart): void {
                        $legacy->where('period_year', $monthStart->year)->where('period_month', $monthStart->month);
                    });
            })
            ->first();
        $targetValue = $target?->target_leads ?? $target?->lead_target;
        $actual = SalesLead::query()
            ->where('sales_user_id', $sales->id)
            ->whereDate('lead_date', '>=', $monthStart->toDateString())
            ->whereDate('lead_date', '<=', $monthEnd->toDateString())
            ->count();

        return [
            'month' => $monthStart,
            'target' => $targetValue === null ? null : (int) $targetValue,
            'actual' => $actual,
            'achievement' => $targetValue === null || (int) $targetValue === 0
                ? null
                : round(($actual / (int) $targetValue) * 100, 1),
        ];
    }

    private function upsertScoped(User $actor, array $data): SalesLeadTarget
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
        $target->fill([
            'lead_target' => (int) $data['lead_target'],
            'target_month' => sprintf('%04d-%02d-01', $data['period_year'], $data['period_month']),
            'target_leads' => (int) $data['lead_target'],
            'updated_by' => $actor->id,
        ]);
        $target->created_by ??= $actor->id;
        $target->save();

        return $target;
    }
}
