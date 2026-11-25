<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Throwable;

class DatabaseBackup extends Command
{
    protected $signature = 'oasis:database-backup';

    protected $description = 'Buat backup terkompresi database OASIS';

    public function handle(DatabaseBackupService $backups): int
    {
        try {
            $result = $backups->backup();
            $this->info("Backup selesai: {$result['path']} ({$result['bytes']} bytes).");

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Backup gagal. Lihat log server.');

            return self::FAILURE;
        }
    }
}
