<?php

namespace App\Console\Commands;

use App\Models\ContentItem;
use App\Services\CollaborationNotificationService;
use App\Services\SalesAgendaReminderService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendSalesAgendaReminders extends Command
{
    protected $signature = 'oasis:sales-agenda-reminders';

    protected $description = 'Mengirim pengingat Agenda Daily Plan Buku Saku Sales.';

    public function __construct(private readonly CollaborationNotificationService $notifications)
    {
        parent::__construct();
    }

    public function handle(SalesAgendaReminderService $dailyPlanReminders): int
    {
        $now = now();
        $sent = 0;
        ContentItem::query()->whereNotNull('daily_plan_id')
            ->where('item_type', 'agenda')
            ->where('agenda_type', ContentItem::SALES_AGENDA_TYPE)
            ->whereNotIn('status', ['done', 'cancelled', 'rescheduled'])
            ->whereDate('scheduled_date', $now->toDateString())
            ->whereNotNull('start_time')->whereNotNull('end_time')
            ->whereHas('dailyPlan', fn ($query) => $query->where('status', 'submitted'))
            ->with(['owner', 'dailyPlan'])
            ->each(function (ContentItem $agenda) use ($now, &$sent): void {
                $events = $this->events($agenda, $now);
                foreach ($events as [$type, $target]) {
                    if ($type === 'sales_agenda_overdue' && $agenda->status === 'done') {
                        continue;
                    }
                    if (! $this->withinWindow($now, $target)) {
                        continue;
                    }
                    $occurrence = implode('|', [
                        $agenda->scheduled_date->toDateString(),
                        $agenda->start_time,
                        $agenda->end_time,
                        (int) $agenda->duration_minutes,
                    ]);
                    $sent += $this->deliver($agenda, $type, $occurrence);
                }
            });

        $sent += $dailyPlanReminders->sendAt(CarbonImmutable::now(config('app.timezone')));
        $this->info("{$sent} pengingat dikirim.");

        return self::SUCCESS;
    }

    /** @return array<int, array{0:string,1:CarbonImmutable}> */
    private function events(ContentItem $agenda, CarbonInterface $now): array
    {
        $start = CarbonImmutable::parse($agenda->scheduled_date->toDateString().' '.$agenda->start_time, $now->timezone);
        $end = CarbonImmutable::parse($agenda->scheduled_date->toDateString().' '.$agenda->end_time, $now->timezone);

        return [
            ['sales_agenda_upcoming', $start->subMinutes(15)],
            ['sales_agenda_starting', $start],
            ['sales_agenda_ending_soon', $end->subMinutes(15)],
            ['sales_agenda_overdue', $end->addMinutes(15)],
        ];
    }

    private function withinWindow(CarbonInterface $now, CarbonImmutable $target): bool
    {
        return $now->greaterThanOrEqualTo($target) && $now->lessThanOrEqualTo($target->addMinutes(2));
    }

    private function deliver(ContentItem $agenda, string $type, string $occurrence): int
    {
        $recipient = $agenda->owner;
        if (! $recipient || ! $recipient->isAccountActive() || ! $recipient->is_active) {
            return 0;
        }

        return DB::transaction(function () use ($agenda, $type, $occurrence, $recipient): int {
            $claimed = DB::table('sales_agenda_notification_deliveries')->insertOrIgnore([
                'content_item_id' => $agenda->id,
                'recipient_id' => $recipient->id,
                'notification_type' => $type,
                'occurrence_key' => $occurrence,
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($claimed !== 1) {
                return 0;
            }

            $labels = [
                'sales_agenda_upcoming' => ['Agenda 15 menit lagi', 'Agenda "'.$agenda->title.'" dimulai 15 menit lagi.'],
                'sales_agenda_starting' => ['Agenda dimulai', 'Agenda "'.$agenda->title.'" dimulai sekarang.'],
                'sales_agenda_ending_soon' => ['Agenda segera berakhir', 'Agenda "'.$agenda->title.'" berakhir 15 menit lagi.'],
                'sales_agenda_overdue' => ['Agenda melewati waktu', 'Agenda "'.$agenda->title.'" belum selesai dan melewati waktu 15 menit.'],
            ];
            $this->notifications->create($recipient, $type, $labels[$type][0], $labels[$type][1], route('sales-daily-plans.index', ['date' => $agenda->scheduled_date->toDateString()]), $agenda->dailyPlan);

            return 1;
        });
    }
}
