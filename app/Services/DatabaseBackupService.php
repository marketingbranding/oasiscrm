<?php

namespace App\Services;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class DatabaseBackupService
{
    public function __construct(
        private readonly AppSettingResolver $settings,
        private readonly ?Closure $dumper = null,
    ) {}

    /**
     * @return array{path:string,bytes:int,deleted:int,duration_ms:int}
     */
    public function backup(): array
    {
        $startedAt = microtime(true);
        $disk = Storage::disk('database_backups');
        $filename = 'oasis-'.now()->format('Ymd-His').'-'.str()->random(8).'.sql.gz';
        $temporaryPath = tempnam(sys_get_temp_dir(), 'oasis-backup-');

        if ($temporaryPath === false) {
            throw new RuntimeException('Backup temporary file unavailable.');
        }

        try {
            $this->dump($temporaryPath);
            $compressed = gzencode((string) file_get_contents($temporaryPath), 9);
            if ($compressed === false) {
                throw new RuntimeException('Backup compression failed.');
            }

            $disk->put($filename, $compressed);
            $deleted = $this->prune($disk);
            $bytes = strlen($compressed);

            Log::info('Database backup completed', [
                'path' => $filename,
                'bytes' => $bytes,
                'deleted' => $deleted,
            ]);

            return [
                'path' => $filename,
                'bytes' => $bytes,
                'deleted' => $deleted,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (Throwable $exception) {
            Log::error('Database backup failed', [
                'exception' => $exception::class,
            ]);

            throw new RuntimeException('Database backup failed.', previous: $exception);
        } finally {
            @unlink($temporaryPath);
        }
    }

    private function dump(string $outputPath): void
    {
        if ($this->dumper !== null) {
            ($this->dumper)($outputPath);

            return;
        }

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $host = (string) config("database.connections.{$connection}.host");
        $port = (string) config("database.connections.{$connection}.port");
        $username = (string) config("database.connections.{$connection}.username");
        $password = (string) config("database.connections.{$connection}.password");

        if ($connection !== 'mysql') {
            throw new RuntimeException('Database backup requires MySQL.');
        }

        $process = new Process([
            (string) env('OASIS_MYSQLDUMP_BINARY', 'mysqldump'),
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--host='.$host,
            '--port='.$port,
            '--user='.$username,
            $database,
        ], null, ['MYSQL_PWD' => $password]);
        $process->setTimeout((int) env('OASIS_BACKUP_TIMEOUT', 600));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Database dumper returned failure.');
        }

        file_put_contents($outputPath, $process->getOutput(), LOCK_EX);
    }

    private function prune(Filesystem $disk): int
    {
        $cutoff = now()->subDays(max(1, $this->settings->int('backup.database_retention_days')));
        $deleted = 0;

        foreach ($disk->files() as $path) {
            if (! str_starts_with(basename($path), 'oasis-') || ! str_ends_with($path, '.sql.gz')) {
                continue;
            }

            $lastModified = $disk->lastModified($path);
            if ($lastModified < $cutoff->timestamp && $disk->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
