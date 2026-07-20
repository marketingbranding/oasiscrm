<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\User;

class WorkPlannerReminderService
{
    public function forUser(User $user): array
    {
        $base = ContentItem::with('assignees')->visibleTo($user);
        $finished = ['completed', 'done', 'uploaded', 'cancelled'];

        return [
            'overdue' => (clone $base)->whereDate('scheduled_date', '<', today())->whereNotIn('status', $finished)->orderBy('scheduled_date')->take(10)->get(),
            'today' => (clone $base)->whereDate('scheduled_date', today())->whereNotIn('status', $finished)->orderByRaw("COALESCE(start_time, '23:59')")->take(10)->get(),
            'tomorrow' => (clone $base)->whereDate('scheduled_date', today()->addDay())->whereNotIn('status', $finished)->orderBy('scheduled_date')->take(10)->get(),
        ];
    }
}
