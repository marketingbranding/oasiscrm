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
            $compressedPath = $this->compress($temporaryPath);
            $stream = fopen($compressedPath, 'rb');
            if ($stream === false || ! $disk->writeStream($filename, $stream)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                throw new RuntimeException('Backup upload failed.');
            }
            fclose($stream);
            $deleted = $this->prune($disk);
            $bytes = $disk->size($filename);

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
            if (isset($compressedPath)) {
                @unlink($compressedPath);
            }
        }
    }

    private function compress(string $inputPath): string
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'oasis-backup-gzip-');
        if ($outputPath === false) {
            throw new RuntimeException('Backup compression file unavailable.');
        }

        $input = fopen($inputPath, 'rb');
        $output = gzopen($outputPath, 'wb9');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                gzclose($output);
            }
            @unlink($outputPath);
            throw new RuntimeException('Backup compression failed.');
        }

        while (! feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            if ($chunk === false) {
                fclose($input);
                gzclose($output);
                @unlink($outputPath);
                throw new RuntimeException('Backup read failed.');
            }
            if ($chunk !== '') {
                gzwrite($output, $chunk);
            }
        }

        fclose($input);
        gzclose($output);

        return $outputPath;
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
            '--result-file='.$outputPath,
            $database,
        ], null, ['MYSQL_PWD' => $password]);
        $process->setTimeout((int) env('OASIS_BACKUP_TIMEOUT', 600));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Database dumper returned failure.');
        }

        if (! is_file($outputPath)) {
            throw new RuntimeException('Database dumper did not create result file.');
        }
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
