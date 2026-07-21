<?php

namespace App\Console\Commands;

use App\Models\UserPresence;
use Illuminate\Console\Command;

class CleanupPresence extends Command
{
    protected $signature = 'oasis:presence-cleanup';

    protected $description = 'Delete stale CRM presence rows';

    public function handle(): int
    {
        $deleted = UserPresence::where('last_seen_at', '<', now()->subHours((int) config('presence.cleanup_hours', 24)))->delete();
        $this->info($deleted.' stale presence rows deleted.');

        return self::SUCCESS;
    }
}
