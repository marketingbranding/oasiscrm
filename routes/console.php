<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('konsumen-progress:sync')->everyTenMinutes()->withoutOverlapping(30)->name('konsumen-progress-sync');
Schedule::command('dana-talangan:sync')->everyTenMinutes()->withoutOverlapping(30)->name('dana-talangan-sync');
Schedule::command('oasis:presence-cleanup')->hourly()->withoutOverlapping(120)->name('presence-cleanup');
Schedule::command('oasis:notifications-cleanup')->weekly()->withoutOverlapping(120)->name('notifications-cleanup');
