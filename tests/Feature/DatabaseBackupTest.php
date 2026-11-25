<?php

namespace Tests\Feature;

use App\Services\AppSettingResolver;
use App\Services\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_br73_backup_writes_compressed_file_to_backup_disk_and_prunes_retention(): void
    {
        Storage::fake('database_backups');
        Storage::disk('database_backups')->put('oasis-old.sql.gz', gzencode('old'));
        touch(Storage::disk('database_backups')->path('oasis-old.sql.gz'), now()->subDays(31)->timestamp);

        $peakBefore = memory_get_peak_usage(true);
        $service = new DatabaseBackupService(
            app(AppSettingResolver::class),
            static function (string $path): bool {
                $handle = fopen($path, 'wb');
                for ($chunk = 0; $chunk < 50; $chunk++) {
                    fwrite($handle, str_repeat('x', 1024 * 1024));
                }
                fclose($handle);

                return true;
            },
        );

        $result = $service->backup();

        $this->assertSame(1, $result['deleted']);
        $this->assertStringEndsWith('.sql.gz', $result['path']);
        Storage::disk('database_backups')->assertExists($result['path']);
        Storage::disk('database_backups')->assertMissing('oasis-old.sql.gz');
        $compressedPath = Storage::disk('database_backups')->path($result['path']);
        $handle = gzopen($compressedPath, 'rb');
        $bytes = 0;
        while (! gzeof($handle)) {
            $chunk = gzread($handle, 1024 * 1024);
            $bytes += strlen($chunk);
        }
        gzclose($handle);

        $this->assertSame(50 * 1024 * 1024, $bytes);
        $this->assertLessThan(20 * 1024 * 1024, memory_get_peak_usage(true) - $peakBefore);
    }

    public function test_br73_backup_failure_does_not_expose_credentials(): void
    {
        Storage::fake('database_backups');
        $service = new DatabaseBackupService(
            app(AppSettingResolver::class),
            static function (string $path): void {
                throw new \RuntimeException('secret-password must not escape');
            },
        );

        $this->expectExceptionMessage('Database backup failed.');
        $service->backup();
    }
}
