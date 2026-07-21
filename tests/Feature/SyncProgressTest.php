<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DatabaseSheetSyncStatus;
use App\Models\KonsumenProgressSyncStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\DatabaseSheetSyncService;
use App\Services\GoogleSheetsApiService;
use App\Services\KonsumenProgressSyncService;
use App\Services\SyncLockService;
use App\Services\SyncResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SyncProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_database_sync_returns_standard_409_contract(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $service = Mockery::mock(DatabaseSheetSyncService::class);
        $service->shouldReceive('syncBranch')->once()->andReturn([
            'ok' => false, 'status' => 'syncing', 'code' => 'sync_already_running',
            'message' => 'Sinkronisasi untuk scope ini sedang berjalan.', 'summary' => [], 'retryable' => true,
        ]);
        $this->app->instance(DatabaseSheetSyncService::class, $service);

        $this->actingAs($user)->postJson(route('database.sync'), ['branch_id' => $branch->id])
            ->assertConflict()
            ->assertJsonPath('status', 'syncing')
            ->assertJsonPath('code', 'sync_already_running')
            ->assertJsonPath('scope.id', $branch->id);
    }

    public function test_sync_lock_is_released_after_success_and_failure(): void
    {
        $locks = app(SyncLockService::class);
        $this->assertTrue($locks->run('test-success', fn () => ['ok' => true])['ok']);
        $lock = Cache::lock('oasis:sync:test-success', 60);
        $this->assertTrue($lock->get());
        $lock->release();

        try {
            $locks->run('test-failure', fn () => throw new RuntimeException('failed'));
        } catch (RuntimeException) {
            // Expected failure must still release the lock.
        }
        $lock = Cache::lock('oasis:sync:test-failure', 60);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_success_response_uses_standard_contract_and_notifies_initiator(): void
    {
        [$branch, $user] = $this->branchAndUser();
        DatabaseSheetSyncStatus::create([
            'branch_id' => $branch->id, 'status' => 'success', 'summary' => ['Leads' => 12],
            'started_at' => now()->subSeconds(2), 'finished_at' => now(), 'last_successful_at' => now(),
            'duration_ms' => 2000, 'initiated_by' => $user->id,
        ]);
        $service = Mockery::mock(DatabaseSheetSyncService::class);
        $service->shouldReceive('syncBranch')->once()->andReturn(['ok' => true, 'summary' => ['Leads' => 12]]);
        $this->app->instance(DatabaseSheetSyncService::class, $service);

        $this->actingAs($user)->postJson(route('database.sync'), ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('summary.checked', 12)
            ->assertJsonPath('scope.name', $branch->name)
            ->assertJsonStructure(['started_at', 'finished_at', 'duration_ms', 'last_successful_sync_at', 'status_url']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $user->id, 'type' => 'sync_completed']);
    }

    public function test_failed_attempt_preserves_last_success_and_hides_raw_provider_message(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $lastSuccess = now()->subHour()->startOfSecond();
        DatabaseSheetSyncStatus::create([
            'branch_id' => $branch->id, 'status' => 'failed', 'message' => 'RAW GOOGLE BODY secret-token',
            'summary' => [], 'started_at' => now()->subSeconds(2), 'finished_at' => now(),
            'last_successful_at' => $lastSuccess, 'duration_ms' => 2000, 'initiated_by' => $user->id,
        ]);
        $service = Mockery::mock(DatabaseSheetSyncService::class);
        $service->shouldReceive('syncBranch')->once()->andReturn([
            'ok' => false, 'message' => 'RAW GOOGLE BODY secret-token', 'summary' => [],
        ]);
        $this->app->instance(DatabaseSheetSyncService::class, $service);

        $response = $this->actingAs($user)->postJson(route('database.sync'), ['branch_id' => $branch->id])
            ->assertUnprocessable()->assertJsonPath('status', 'failed')->assertJsonPath('retryable', true);
        $this->assertSame($lastSuccess->toIso8601String(), $response->json('last_successful_sync_at'));
        $this->assertStringNotContainsString('secret-token', $response->getContent());
    }

    public function test_failed_konsumen_sync_records_attempt_duration(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('batchGet')->once()->andThrow(new RuntimeException('Google unavailable'));

        $result = (new KonsumenProgressSyncService($google))->syncBranch($branch, $user->id);

        $this->assertFalse($result['ok']);
        $status = KonsumenProgressSyncStatus::where('branch_id', $branch->id)->firstOrFail();
        $this->assertSame('failed', $status->status);
        $this->assertNotNull($status->duration_ms);
        $this->assertSame($user->id, $status->initiated_by);
    }

    public function test_partial_and_stalled_states_are_not_reported_as_success(): void
    {
        $responses = app(SyncResponseService::class);
        $idle = $responses->make('database', ['type' => 'branch', 'id' => 1, 'name' => 'Solo'], null);
        $this->assertSame('idle', $idle['status']);
        $this->assertSame('sync_idle', $idle['code']);

        $partial = $responses->make('dana-talangan', ['type' => 'global', 'id' => null, 'name' => 'Global'], null, [
            'ok' => true, 'outcome' => 'warning', 'summary' => ['updated' => 2, 'push_failed' => 1],
        ]);
        $this->assertFalse($partial['ok']);
        $this->assertSame('partial_success', $partial['status']);

        $status = new DatabaseSheetSyncStatus(['status' => 'running', 'started_at' => now()->subMinutes(20)]);
        $timedOut = $responses->make('database', ['type' => 'branch', 'id' => 1, 'name' => 'Solo'], $status);
        $this->assertSame('timed_out', $timedOut['status']);
        $this->assertNull($timedOut['error_code']);
    }

    public function test_status_endpoint_rechecks_sync_authorization(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $user->branches()->updateExistingPivot($branch->id, ['can_sync' => false]);
        $this->actingAs($user)->getJson(route('database.sync-status', ['branch_id' => $branch->id]))->assertForbidden();
    }

    public function test_loading_component_contains_truthful_states_and_manual_retry_contract(): void
    {
        $js = file_get_contents(resource_path('js/crm-sync.js'));
        $component = file_get_contents(resource_path('views/components/crm/sync-control.blade.php'));
        $database = file_get_contents(resource_path('views/crm/database/index.blade.php'));

        foreach (['idle', 'loading', 'syncing', 'success', 'partial_success', 'failed', 'timed_out', 'empty'] as $state) {
            $this->assertStringContainsString($state, $js);
        }
        $this->assertStringContainsString('aria-live', $component);
        $this->assertStringContainsString('Berjalan', $component);
        $this->assertStringContainsString('Coba Lagi', $component);
        $this->assertStringContainsString('Periksa Status', $component);
        $this->assertStringNotContainsString('setInterval(() => this.submit', $js);
        $this->assertStringContainsString('!isLoaded(name) && !loadErrors[name]', $database);
        $this->assertStringContainsString('Data gagal dimuat.', $database);
    }

    private function branchAndUser(): array
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $branch = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'sheet_id' => 'sheet-id', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $user->branches()->updateExistingPivot($branch->id, ['can_sync' => true]);

        return [$branch, $user];
    }
}
