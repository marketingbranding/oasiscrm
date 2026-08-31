<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\SalesLeadBridgeSetting;
use App\Models\User;
use App\Services\SalesLeadBridgeService;
use App\Services\SalesLeadLifecycleSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SalesLeadBridgeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_mode_requires_global_flag_and_successful_preflight(): void
    {
        $branch = $this->branch('SET');
        config()->set('services.google_sheets.sales_lead_sync_enabled', false);
        $bridge = Mockery::mock(SalesLeadBridgeService::class);
        $bridge->shouldNotReceive('preflight');
        $this->app->instance(SalesLeadBridgeService::class, $bridge);

        $this->artisan('sales-lead-bridge:set-mode', ['--branch' => $branch->id, '--mode' => 'push_only'])
            ->expectsOutput('Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.')
            ->assertFailed();

        config()->set('services.google_sheets.sales_lead_sync_enabled', true);
        $setting = new SalesLeadBridgeSetting(['branch_id' => $branch->id, 'status' => 'success']);
        $setting->branch_id = $branch->id;
        $bridge = Mockery::mock(SalesLeadBridgeService::class);
        $bridge->shouldReceive('preflight')->once()->withArgs(fn (Branch $item) => $item->is($branch))->andReturnUsing(function () use ($branch): SalesLeadBridgeSetting {
            return SalesLeadBridgeSetting::create(['branch_id' => $branch->id, 'mode' => 'off', 'status' => 'success', 'last_preflight_at' => now(), 'last_preflight_hash' => str_repeat('a', 64)]);
        });
        $this->app->instance(SalesLeadBridgeService::class, $bridge);

        $this->artisan('sales-lead-bridge:set-mode', ['--branch' => $branch->id, '--mode' => 'push_only'])->assertSuccessful();
        $this->assertDatabaseHas('sales_lead_bridge_settings', ['branch_id' => $branch->id, 'mode' => 'push_only', 'status' => 'active']);
    }

    public function test_bridge_sync_command_requires_branch_and_supports_dry_run(): void
    {
        config()->set('services.google_sheets.sales_lead_sync_enabled', true);
        $branch = $this->branch('SYNC');
        $bridge = Mockery::mock(SalesLeadBridgeService::class);
        $bridge->shouldReceive('pull')->once()->withArgs(fn (Branch $item, $actor, bool $dryRun) => $item->is($branch) && $actor === null && $dryRun)->andReturn([
            'ok' => true,
            'status' => 'success',
            'summary' => ['claimed' => 0, 'updated' => 0, 'unchanged' => 1, 'claimable' => 0, 'unresolved' => 0, 'ignored_deleted' => 0, 'tombstones' => 0],
        ]);
        $this->app->instance(SalesLeadBridgeService::class, $bridge);

        $this->artisan('sales-lead-bridge:sync')->assertFailed();
        $this->artisan('sales-lead-bridge:sync', ['--branch' => $branch->id, '--dry-run' => true])->assertSuccessful();
    }

    public function test_scheduler_command_kill_switch_does_not_resolve_lifecycle_service(): void
    {
        config()->set('services.google_sheets.sales_lead_sync_enabled', false);
        $this->app->bind(SalesLeadLifecycleSyncService::class, fn () => throw new \RuntimeException('must not resolve'));

        $this->artisan('sales-lead-lifecycle:sync')
            ->expectsOutput('Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.')
            ->assertSuccessful();
    }

    public function test_manual_route_uses_existing_authorization_and_mode_result(): void
    {
        config()->set('services.google_sheets.sales_lead_sync_enabled', true);
        $branch = $this->branch('ROUTE');
        $manager = User::factory()->create([
            'role_id' => Role::where('slug', 'manager')->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $manager->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => true, 'can_sync' => true]]);
        SalesLeadBridgeSetting::create(['branch_id' => $branch->id, 'mode' => 'push_only', 'status' => 'active']);
        $bridge = Mockery::mock(SalesLeadBridgeService::class);
        $bridge->shouldReceive('pull')->once()->andReturn(['ok' => false, 'status' => 'disabled', 'summary' => []]);
        $this->app->instance(SalesLeadBridgeService::class, $bridge);

        $this->actingAs($manager)->postJson(route('sales-pocketbook.lead-bridge-sync'), ['branch_id' => $branch->id])->assertStatus(422);

        $branch->bridgeSetting()->update(['mode' => 'bidirectional']);
        $branch->unsetRelation('bridgeSetting');
        $bridge = Mockery::mock(SalesLeadBridgeService::class);
        $bridge->shouldReceive('pull')->once()->withArgs(fn (Branch $item, User $actor) => $item->is($branch) && $actor->is($manager))->andReturn([
            'ok' => true,
            'status' => 'success',
            'message' => 'OK',
            'summary' => ['claimed' => 0, 'updated' => 0, 'unchanged' => 1, 'claimable' => 0, 'unresolved' => 0, 'ignored_deleted' => 0, 'tombstones' => 0],
        ]);
        $this->app->instance(SalesLeadBridgeService::class, $bridge);

        $this->actingAs($manager)->postJson(route('sales-pocketbook.lead-bridge-sync'), ['branch_id' => $branch->id])->assertOk();

        $sales = User::factory()->create(['role_id' => Role::where('slug', 'sales')->value('id'), 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $this->actingAs($sales)->postJson(route('sales-pocketbook.lead-bridge-sync'), ['branch_id' => $branch->id])->assertForbidden();
    }

    public function test_changelog_entry_is_unique(): void
    {
        $this->assertSame(1, \DB::table('changelogs')->whereNull('version')->where('title', 'Bridge Lead Buku Saku Sales')->count());
    }

    private function branch(string $code): Branch
    {
        return Branch::create(['name' => 'Branch '.$code, 'code' => $code, 'sheet_id' => 'sheet-'.$code, 'is_active' => true]);
    }
}
