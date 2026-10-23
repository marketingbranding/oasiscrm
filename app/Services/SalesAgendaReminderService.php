<?php

namespace App\Services;

use App\Models\SalesDailyPlanItem;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;

class SalesAgendaReminderService
{
    public function sendAt(CarbonImmutable $now): int
    {
        $items = SalesDailyPlanItem::query()
            ->with('plan')
            ->whereHas('plan', fn ($query) => $query
                ->where('status', 'submitted')
                ->whereDate('work_date', $now->toDateString()))
            ->get();
        $sent = 0;

        foreach ($items as $item) {
            if ($item->status === 'completed') {
                continue;
            }
            $start = CarbonImmutable::parse($item->plan->work_date->toDateString().' '.$item->start_time, $now->getTimezone());
            $end = CarbonImmutable::parse($item->plan->work_date->toDateString().' '.$item->end_time, $now->getTimezone());
            foreach ([
                'upcoming' => [$start->subMinutes(15), 'Agenda dimulai 15 menit lagi', 'Bersiap untuk aktivitas: '.$item->activity.'.'],
                'starting' => [$start, 'Agenda dimulai sekarang', 'Aktivitas dimulai: '.$item->activity.'.'],
                'ending_soon' => [$end->subMinutes(15), 'Agenda segera selesai', 'Sisa waktu 15 menit untuk: '.$item->activity.'.'],
                'overdue' => [$end->addMinutes(15), 'Agenda terlewat', 'Aktivitas '.$item->activity.' belum diselesaikan.'],
            ] as $stage => [$dueAt, $title, $message]) {
                if ($now->lt($dueAt) || $now->gte($dueAt->addMinute())) {
                    continue;
                }

                $notification = UserNotification::firstOrCreate([
                    'user_id' => $item->plan->sales_user_id,
                    'type' => "sales_agenda_reminder.{$stage}",
                    'related_type' => 'sales_daily_plan_item',
                    'related_id' => $item->id,
                ], [
                    'title' => $title,
                    'message' => $message,
                    'action_url' => route('sales-agendas.index', ['date' => $item->plan->work_date->toDateString()]),
                ]);
                $sent += $notification->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $sent;
    }
}
