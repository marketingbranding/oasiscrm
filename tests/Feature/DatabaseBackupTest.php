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

        $service = new DatabaseBackupService(
            app(AppSettingResolver::class),
            static fn (string $path): bool => file_put_contents($path, "CREATE TABLE test (id int);\n") !== false,
        );

        $result = $service->backup();

        $this->assertSame(1, $result['deleted']);
        $this->assertStringEndsWith('.sql.gz', $result['path']);
        Storage::disk('database_backups')->assertExists($result['path']);
        Storage::disk('database_backups')->assertMissing('oasis-old.sql.gz');
        $this->assertStringContainsString('CREATE TABLE test', gzdecode(Storage::disk('database_backups')->get($result['path'])));
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
