<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\SalesLead;
use App\Models\User;
use Carbon\CarbonImmutable;

class SalesDailyReminderService
{
    public const KEY = 'sales_daily_pocketbook_reminder';

    public function __construct(private readonly WorkspaceAccessService $workspaceAccess) {}

    public function state(User $user): array
    {
        $today = CarbonImmutable::now(config('app.timezone'))->toDateString();
        if (! $user->isSales()) {
            return $this->emptyState($today);
        }

        $hasAssignedProject = $this->workspaceAccess->accessibleProjectsQuery($user)->exists();
        $todayLeadCount = SalesLead::query()->visibleTo($user)
            ->where('sales_user_id', $user->id)
            ->whereDate('lead_date', $today)
            ->count();
        $agendaQuery = ContentItem::query()
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->where('owner_user_id', $user->id);
        $todayAgendaCount = (clone $agendaQuery)
            ->whereDate('scheduled_date', $today)
            ->whereNotIn('status', ['cancelled', 'rescheduled'])
            ->count();
        $missingAgendaResultCount = (clone $agendaQuery)
            ->where('status', 'done')
            ->whereRaw("TRIM(COALESCE(activity_result, '')) = ''")
            ->count();
        $dismissed = $user->dailyReminderDismissals()
            ->where('reminder_key', self::KEY)
            ->whereDate('dismissed_for_date', $today)
            ->exists();
        $needsReminder = $todayLeadCount === 0 || $todayAgendaCount === 0 || $missingAgendaResultCount > 0;

        return [
            'shouldShow' => $needsReminder && ! $dismissed,
            'today' => $today,
            'todayLeadCount' => $todayLeadCount,
            'todayAgendaCount' => $todayAgendaCount,
            'missingAgendaResultCount' => $missingAgendaResultCount,
            'hasAssignedProject' => $hasAssignedProject,
        ];
    }

    private function emptyState(string $today): array
    {
        return [
            'shouldShow' => false,
            'today' => $today,
            'todayLeadCount' => 0,
            'todayAgendaCount' => 0,
            'missingAgendaResultCount' => 0,
            'hasAssignedProject' => false,
        ];
    }
}
