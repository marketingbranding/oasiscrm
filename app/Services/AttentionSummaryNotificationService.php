<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AttentionSummaryNotificationService
{
    public const TYPE = 'sales_attention_summary';

    public function __construct(
        private readonly LeadAttentionService $leadAttention,
        private readonly SalesAgendaSubmissionAttentionService $submissionAttention,
        private readonly CoordinatorLeadTeamService $teams,
        private readonly SalesWorkCalendarService $calendar,
        private readonly AppSettingResolver $settings,
        private readonly ModuleMaintenanceService $maintenance,
    ) {}

    private function isPastCutoff(CarbonImmutable $date): bool
    {
        $cutoff = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $date->toDateString().' '.$this->settings->string('sales_monitoring.daily_plan_cutoff_time'),
            $date->getTimezone(),
        );

        return $date->gte($cutoff);
    }

    public function sendForDate(CarbonImmutable $date): int
    {
        if ($date->isWeekend() || ! $this->isPastCutoff($date) || $this->maintenance->isUnderMaintenance('sales_pocketbook')) {
            return 0;
        }

        $sent = 0;
        User::query()
            ->where('is_active', true)
            ->where('account_status', 'active')
            ->whereHas('role', fn ($query) => $query->where('slug', 'sales_coordinator'))
            ->get()
            ->filter(fn (User $coordinator): bool => $coordinator->hasPermission('sales_pocketbook.view_team'))
            ->each(function (User $coordinator) use ($date, &$sent): void {
                if ($this->calendar->isFreeDay($date, (int) $coordinator->branch_id)) {
                    return;
                }

                $sales = $this->teams->currentSalesQuery($coordinator)->get();
                $leadCount = $this->leadAttention->attentionQuery($coordinator, $date->endOfDay())->count();
                $missingSubmissionCount = $this->submissionAttention
                    ->missingForMany($sales, $date->endOfDay())
                    ->count();
                $unownedQueueCount = DB::table('sales_lead_unowned_queues')
                    ->join('sales_leads', 'sales_leads.id', '=', 'sales_lead_unowned_queues.sales_lead_id')
                    ->where('sales_lead_unowned_queues.queue_coordinator_user_id', $coordinator->id)
                    ->where('sales_lead_unowned_queues.status', 'open')
                    ->whereIn('sales_leads.branch_id', $sales->pluck('branch_id'))
                    ->whereNull('sales_leads.deleted_at')
                    ->count();

                if ($leadCount < 1 && $missingSubmissionCount < 1 && $unownedQueueCount < 1) {
                    return;
                }

                $relatedId = (int) $date->format('Ymd');
                $created = DB::transaction(function () use ($coordinator, $date, $relatedId, $leadCount, $missingSubmissionCount, $unownedQueueCount): bool {
                    $notification = UserNotification::query()->firstOrCreate(
                        ['dedupe_key' => self::TYPE.':'.$coordinator->id.':'.$relatedId],
                        [
                            'user_id' => $coordinator->id,
                            'type' => self::TYPE,
                            'related_type' => 'attention_summary_date',
                            'related_id' => $relatedId,
                            'title' => 'Ringkasan Perlu Perhatian',
                            'message' => "{$leadCount} Lead perlu perhatian; {$missingSubmissionCount} Sales belum mengirim Rencana Harian; {$unownedQueueCount} Lead di antrean tanpa pemilik aktif.",
                            'action_url' => route('sales-pocketbook.index', ['tab' => 'lead', 'focus' => 'attention']),
                            'data' => ['date' => $date->toDateString(), 'lead_count' => $leadCount, 'missing_submission_count' => $missingSubmissionCount, 'unowned_queue_count' => $unownedQueueCount],
                        ],
                    );

                    return $notification->wasRecentlyCreated;
                });

                $sent += $created ? 1 : 0;
            });

        return $sent;
    }
}
