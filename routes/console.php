<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('konsumen-progress:sync')->everyTenMinutes()->withoutOverlapping(30)->name('konsumen-progress-sync');
Schedule::command('dana-talangan:sync')->everyTenMinutes()->withoutOverlapping(30)->name('dana-talangan-sync');
Schedule::command('sales-lead-lifecycle:sync')->everyTenMinutes()->withoutOverlapping(30)->name('sales-lead-lifecycle-sync');
Schedule::command('oasis:presence-cleanup')->hourly()->withoutOverlapping(120)->name('presence-cleanup');
Schedule::command('oasis:notifications-cleanup')->weekly()->withoutOverlapping(120)->name('notifications-cleanup');
Schedule::command('oasis:user-import-cleanup')->daily()->withoutOverlapping(120)->name('user-import-cleanup');
