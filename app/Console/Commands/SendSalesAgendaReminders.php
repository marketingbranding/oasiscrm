<?php

namespace App\Console\Commands;

use App\Services\SalesAgendaReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendSalesAgendaReminders extends Command
{
    protected $signature = 'sales-agenda:send-reminders';

    protected $description = 'Mengirim pengingat in-app untuk aktivitas Daily Plan Sales.';

    public function handle(SalesAgendaReminderService $reminders): int
    {
        $sent = $reminders->sendAt(CarbonImmutable::now(config('app.timezone')));
        $this->info("{$sent} pengingat agenda dikirim.");

        return self::SUCCESS;
    }
}
