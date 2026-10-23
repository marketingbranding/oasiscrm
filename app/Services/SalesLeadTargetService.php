<?php

namespace App\Services;

use App\Models\SalesLead;
use App\Models\SalesLeadTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class SalesLeadTargetService
{
    public function summary(User $sales, CarbonImmutable $month): array
    {
        $monthStart = $month->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $target = SalesLeadTarget::query()
            ->where('sales_user_id', $sales->id)
            ->whereDate('target_month', $monthStart->toDateString())
            ->value('target_leads');
        $actual = SalesLead::query()
            ->where('sales_user_id', $sales->id)
            ->whereDate('lead_date', '>=', $monthStart->toDateString())
            ->whereDate('lead_date', '<=', $monthEnd->toDateString())
            ->count();

        return [
            'month' => $monthStart,
            'target' => $target === null ? null : (int) $target,
            'actual' => $actual,
            'achievement' => $target === null || (int) $target === 0
                ? null
                : round(($actual / (int) $target) * 100, 1),
        ];
    }

    public function upsert(User $sales, CarbonImmutable $month, int $targetLeads, ?User $actor = null): SalesLeadTarget
    {
        if (! $actor?->isSuperadmin()) {
            throw new AccessDeniedHttpException('Hanya Superadmin yang dapat mengatur target Lead.');
        }
        $monthStart = $month->startOfMonth();
        $target = SalesLeadTarget::query()
            ->where('sales_user_id', $sales->id)
            ->whereDate('target_month', $monthStart->toDateString())
            ->first() ?? new SalesLeadTarget([
                'sales_user_id' => $sales->id,
                'target_month' => $monthStart->toDateString(),
            ]);
        $target->target_leads = $targetLeads;
        $target->updated_by = $actor?->id;
        $target->created_by ??= $actor?->id;
        $target->save();

        return $target->refresh();
    }
}
