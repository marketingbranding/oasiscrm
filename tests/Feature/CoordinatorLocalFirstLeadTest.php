<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\CoordinatorLeadPushService;
use App\Services\OptimisticLockService;
use App\Services\SalesLeadSheetOptionService;
use App\Services\SalesLeadSpreadsheetWriter;
use App\Services\SalesLeadSyncService;
use App\ValueObjects\SalesLeadSpreadsheetWriteResult;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CoordinatorLocalFirstLeadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google_sheets.sales_lead_sync_enabled', false);
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_coordinator_creates_team_lead_locally_without_spreadsheet_write(): void
    {
        [$branch, $project, $sales, $coordinator] = $this->context();
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldNotReceive('append');
        $writer->shouldNotReceive('updateBySyncId');
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);

        $this->actingAs($coordinator)->post(route('sales-leads.store'), $this->leadData($branch, $project, $sales))
            ->assertRedirect();

        $lead = SalesLead::sole();
        $this->assertSame('pending_create', $lead->sync_status);
        $this->assertNull($lead->last_synced_at);
        $this->assertSame($sales->id, $lead->sales_user_id);
    }

    public function test_coordinator_renders_and_updates_current_team_lead_locally(): void
    {
        [$branch, $project, $sales, $coordinator] = $this->context();
        $lead = $this->lead($sales, $project);
        $this->app->instance(SalesLeadSpreadsheetWriter::class, Mockery::mock(SalesLeadSpreadsheetWriter::class));

        $this->actingAs($coordinator)->get(route('sales-leads.edit', $lead))
            ->assertOk()
            ->assertSee('id="edit-lead-source"', false)
            ->assertSee('name="source"', false)
            ->assertSee('id="edit-lead-platform"', false)
            ->assertSee('name="platform"', false)
            ->assertSee('id="edit-lead-campaign_name"', false)
            ->assertSee('name="campaign_name"', false)
            ->assertSee('id="edit-lead-sales"', false)
            ->assertDontSee('id="edit-lead-sales" class="sales-input" name="sales_user_id" x-model="sales" required disabled', false);

        $this->actingAs($coordinator)->put(route('sales-leads.update', $lead), $this->leadData($branch, $project, $sales, [
            'customer_name' => 'Updated Team Lead',
            'source' => 'Referral',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Referral',
            'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sales_leads', [
            'id' => $lead->id,
            'customer_name' => 'Updated Team Lead',
            'source' => 'Referral',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Referral',
            'sync_status' => 'pending_create',
        ]);
    }

    public function test_coordinator_cannot_view_or_update_lead_outside_current_team(): void
    {
        [$branch, $project, , $coordinator] = $this->context();
        $outside = $this->sales($branch, $project, 'Outside Sales');
        $lead = $this->lead($outside, $project);

        $this->actingAs($coordinator)->get(route('sales-leads.edit', $lead))->assertForbidden();
        $this->actingAs($coordinator)->put(route('sales-leads.update', $lead), $this->leadData($branch, $project, $outside, [
            'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
        ]))->assertForbidden();
    }

    public function test_edit_view_keeps_primary_sales_selector_disabled_with_hidden_value(): void
    {
        [$branch, $project, $sales] = $this->context();
        $lead = $this->lead($sales, $project);

        $html = $this->actingAs($sales)->view('crm.sales-pocketbook.edit', [
            'errors' => new ViewErrorBag,
            'lead' => $lead,
            'branches' => collect([$branch]),
            'projects' => collect([$project->setRelation('assignedUsers', collect([$sales]))]),
            'salesUsers' => collect([$sales->setRelation('assignedProjects', collect([$project]))]),
            'sources' => ['Referral'],
            'channels' => ['WhatsApp'],
            'activities' => ['Campaign'],
            'promos' => collect(),
            'optimisticToken' => app(OptimisticLockService::class)->token($lead),
        ]);

        $html->assertSee('id="edit-lead-sales" class="sales-input" name="sales_user_id" x-model="sales" required disabled', false)
            ->assertSee('<input type="hidden" name="sales_user_id" value="'.$sales->id.'">', false);
    }

    public function test_coordinator_cannot_forge_sales_outside_current_team(): void
    {
        [$branch, $project, , $coordinator] = $this->context();
        $outside = $this->sales($branch, $project, 'Outside Sales');
        $this->app->instance(SalesLeadSpreadsheetWriter::class, Mockery::mock(SalesLeadSpreadsheetWriter::class));

        $this->actingAs($coordinator)->from(route('sales-pocketbook.index'))->post(
            route('sales-leads.store'),
            $this->leadData($branch, $project, $outside),
        )->assertRedirect()->assertSessionHasErrors('sales_user_id');

        $this->assertDatabaseCount('sales_leads', 0);
    }

    public function test_primary_sales_cannot_store_edit_update_push_or_export(): void
    {
        [$branch, $project, $sales] = $this->context();
        $lead = $this->lead($sales, $project);
        $this->app->instance(SalesLeadSpreadsheetWriter::class, Mockery::mock(SalesLeadSpreadsheetWriter::class));

        $this->actingAs($sales)->post(route('sales-leads.store'), $this->leadData($branch, $project, $sales))->assertForbidden();
        $this->actingAs($sales)->get(route('sales-leads.edit', $lead))->assertForbidden();
        $this->actingAs($sales)->put(route('sales-leads.update', $lead), $this->leadData($branch, $project, $sales, [
            'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
        ]))->assertForbidden();
        $this->actingAs($sales)->post(route('coordinator-leads.sync'))->assertForbidden();
        $this->actingAs($sales)->get(route('coordinator-leads.export'))->assertForbidden();
    }

    public function test_local_only_hides_coordinator_sync_and_rejects_direct_push_without_service_call(): void
    {
        [, , , $coordinator] = $this->context();
        $push = Mockery::mock(CoordinatorLeadPushService::class);
        $push->shouldNotReceive('push');
        $this->app->instance(CoordinatorLeadPushService::class, $push);

        $this->actingAs($coordinator)->get(route('sales-pocketbook.index'))
            ->assertOk()
            ->assertDontSee('SYNC KE SPREADSHEET');
        $this->actingAs($coordinator)->post(route('coordinator-leads.sync'))
            ->assertStatus(503)
            ->assertSessionHas('error', 'Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.');
    }

    public function test_local_only_rejects_lifecycle_sync_without_service_call(): void
    {
        [$branch, , , $coordinator] = $this->context();
        $manager = $this->user('manager', $branch, 'Manager');
        $manager->branches()->updateExistingPivot($branch->id, ['can_view' => true, 'can_edit' => true, 'can_sync' => true]);
        $sync = Mockery::mock(SalesLeadSyncService::class);
        $sync->shouldNotReceive('sync');
        $this->app->instance(SalesLeadSyncService::class, $sync);

        $this->actingAs($manager)->postJson(route('sales-pocketbook.lifecycle-sync'), ['branch_id' => $branch->id])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Sinkronisasi Google Sheets Lead Sales sedang dinonaktifkan.');
    }

    public function test_enabled_mode_shows_coordinator_sync_and_calls_push_service(): void
    {
        config()->set('services.google_sheets.sales_lead_sync_enabled', true);
        [, , , $coordinator] = $this->context();
        $push = Mockery::mock(CoordinatorLeadPushService::class);
        $push->shouldReceive('push')->once()->withArgs(fn (User $user) => $user->is($coordinator))
            ->andReturn(['processed' => 1, 'synced' => 1, 'failed' => 0]);
        $this->app->instance(CoordinatorLeadPushService::class, $push);

        $this->actingAs($coordinator)->get(route('sales-pocketbook.index'))
            ->assertOk()
            ->assertSee('SYNC KE SPREADSHEET');
        $this->actingAs($coordinator)->post(route('coordinator-leads.sync'))
            ->assertRedirect()
            ->assertSessionHas('success', '1 lead tersinkron.');
    }

    public function test_synced_lead_update_stays_local_then_push_updates_same_external_identity(): void
    {
        [$branch, $project, $sales, $coordinator] = $this->context();
        $syncId = (string) Str::uuid();
        $lead = $this->lead($sales, $project, [
            'external_sync_id' => $syncId,
            'sync_status' => 'synced',
            'last_synced_at' => now()->subMinute(),
        ]);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldNotReceive('append');
        $writer->shouldReceive('updateBySyncId')->once()->withArgs(fn (SalesLead $item, string $sheet, string $externalSyncId) => $item->is($lead) && $sheet === 'lead' && $externalSyncId === $syncId
        )->andReturn($this->writeResult($branch, $syncId, 'REMOTE-1'));
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);

        $this->actingAs($coordinator)->put(route('sales-leads.update', $lead), $this->leadData($branch, $project, $sales, [
            'customer_name' => 'Edited Local Lead',
            'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
        ]))->assertRedirect();

        $this->assertSame('pending_update', $lead->fresh()->sync_status);
        $this->assertSame($syncId, $lead->fresh()->external_sync_id);
        $this->assertTrue($lead->last_synced_at->equalTo($lead->fresh()->last_synced_at));

        config()->set('services.google_sheets.sales_lead_sync_enabled', true);
        $result = app(CoordinatorLeadPushService::class)->push($coordinator);

        $this->assertSame(['processed' => 1, 'synced' => 1, 'failed' => 0], $result);
        $this->assertSame('synced', $lead->fresh()->sync_status);
        $this->assertSame($syncId, $lead->fresh()->external_sync_id);
    }

    public function test_push_uses_canonical_oasis_sales_name_and_keeps_sync_identity(): void
    {
        config()->set('services.google_sheets.sales_lead_sync_enabled', true);
        [$branch, $project, $sales, $coordinator] = $this->context();
        $sales->update(['name' => 'Canonical OASIS Sales']);
        $lead = $this->lead($sales, $project);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->once()->withArgs(fn (SalesLead $item, string $sheet, array $fields, string $syncId) => $item->is($lead)
            && $sheet === 'lead'
            && $fields['sales_pic'] === 'Canonical OASIS Sales'
            && $syncId === $lead->external_sync_id
        )->andReturn($this->writeResult($branch, $lead->external_sync_id, 'REMOTE-NAME'));
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);

        $result = app(CoordinatorLeadPushService::class)->push($coordinator);

        $this->assertSame(['processed' => 1, 'synced' => 1, 'failed' => 0], $result);
        $this->assertSame($lead->external_sync_id, $lead->fresh()->external_sync_id);
    }

    public function test_push_failure_preserves_lead_retry_succeeds_and_synced_second_push_is_noop(): void
    {
        config()->set('services.google_sheets.sales_lead_sync_enabled', true);
        [$branch, $project, $sales, $coordinator] = $this->context();
        $lead = $this->lead($sales, $project);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->once()->andThrow(new RuntimeException('remote unavailable'));
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);

        $failed = app(CoordinatorLeadPushService::class)->push($coordinator);

        $this->assertSame(['processed' => 1, 'synced' => 0, 'failed' => 1], $failed);
        $this->assertDatabaseHas('sales_leads', ['id' => $lead->id, 'sync_status' => 'sync_failed', 'last_sync_error' => 'remote unavailable']);

        $retryWriter = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $retryWriter->shouldReceive('append')->once()->andReturn($this->writeResult($branch, $lead->external_sync_id, 'REMOTE-2'));
        $retryWriter->shouldNotReceive('updateBySyncId');
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $retryWriter);
        $this->app->forgetInstance(CoordinatorLeadPushService::class);

        $retried = app(CoordinatorLeadPushService::class)->push($coordinator);
        $noop = app(CoordinatorLeadPushService::class)->push($coordinator);

        $this->assertSame(['processed' => 1, 'synced' => 1, 'failed' => 0], $retried);
        $this->assertSame(['processed' => 0, 'synced' => 0, 'failed' => 0], $noop);
        $this->assertSame('synced', $lead->fresh()->sync_status);
        $this->assertNull($lead->fresh()->last_sync_error);
    }

    public function test_unsynced_lead_can_move_branch_without_changing_external_identity(): void
    {
        foreach (['pending_create', 'sync_failed'] as $syncStatus) {
            [$branch, $project, $sales, $coordinator] = $this->context(strtoupper(substr($syncStatus, 0, 2)));
            $otherBranch = $this->branch('Move '.$syncStatus, 'M'.substr($syncStatus, 0, 2));
            $otherProject = $this->project($otherBranch, 'Moved Project '.$syncStatus);
            $otherSales = $this->sales($otherBranch, $otherProject, 'Moved Sales '.$syncStatus);
            $coordinator->branches()->attach($otherBranch->id, ['can_view' => true, 'can_edit' => true]);
            SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $otherSales->id]);
            $lead = $this->lead($sales, $project, ['sync_status' => $syncStatus, 'last_synced_at' => null]);
            $syncId = $lead->external_sync_id;

            $this->actingAs($coordinator)->put(route('sales-leads.update', $lead), $this->leadData($otherBranch, $otherProject, $otherSales, [
                'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
            ]))->assertRedirect();

            $this->assertSame($otherBranch->id, $lead->fresh()->branch_id);
            $this->assertSame($otherProject->id, $lead->fresh()->project_id);
            $this->assertSame($syncId, $lead->fresh()->external_sync_id);
        }
    }

    public function test_delivered_lead_cannot_move_branch_when_synced_or_pending_update(): void
    {
        foreach (['synced', 'pending_update'] as $syncStatus) {
            [$branch, $project, $sales, $coordinator] = $this->context(strtoupper(substr($syncStatus, 0, 2)));
            $otherBranch = $this->branch('Locked '.$syncStatus, 'L'.substr($syncStatus, 0, 2));
            $otherProject = $this->project($otherBranch, 'Locked Project '.$syncStatus);
            $otherSales = $this->sales($otherBranch, $otherProject, 'Locked Sales '.$syncStatus);
            $coordinator->branches()->attach($otherBranch->id, ['can_view' => true, 'can_edit' => true]);
            SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $otherSales->id]);
            $lead = $this->lead($sales, $project, ['sync_status' => $syncStatus, 'last_synced_at' => now()->subDay()]);

            $this->actingAs($coordinator)->from(route('sales-leads.edit', $lead))->put(route('sales-leads.update', $lead), $this->leadData($otherBranch, $otherProject, $otherSales, [
                'expected_updated_at' => app(OptimisticLockService::class)->token($lead),
            ]))->assertRedirect(route('sales-leads.edit', $lead))->assertSessionHasErrors('branch_id');

            $this->assertSame($branch->id, $lead->fresh()->branch_id);
            $this->assertSame($project->id, $lead->fresh()->project_id);
        }
    }

    public function test_push_is_isolated_to_accessible_branch(): void
    {
        config()->set('services.google_sheets.sales_lead_sync_enabled', true);
        [$branch, $project, $sales, $coordinator] = $this->context();
        $accessible = $this->lead($sales, $project);
        $otherBranch = $this->branch('Other', 'OTH');
        $otherProject = $this->project($otherBranch, 'Other Project');
        $otherSales = $this->sales($otherBranch, $otherProject, 'Other Sales');
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $otherSales->id]);
        $isolated = $this->lead($otherSales, $otherProject);
        $writer = Mockery::mock(SalesLeadSpreadsheetWriter::class);
        $writer->shouldReceive('append')->once()->withArgs(fn (SalesLead $item) => $item->is($accessible))
            ->andReturn($this->writeResult($branch, $accessible->external_sync_id, 'REMOTE-3'));
        $this->app->instance(SalesLeadSpreadsheetWriter::class, $writer);

        $result = app(CoordinatorLeadPushService::class)->push($coordinator);

        $this->assertSame(1, $result['processed']);
        $this->assertSame('synced', $accessible->fresh()->sync_status);
        $this->assertSame('pending_create', $isolated->fresh()->sync_status);
    }

    private function context(string $suffix = ''): array
    {
        $options = Mockery::mock(SalesLeadSheetOptionService::class);
        $options->shouldReceive('forBranch')->andReturnUsing(fn (Branch $branch) => [
            'promo' => [],
            'source' => ['Referral'],
            'channel' => ['WhatsApp'],
            'activity' => ['Campaign'],
            'project' => [$branch->name.' Project'],
            'sales' => ['Team Sales', 'Outside Sales', 'Other Sales'],
            'status' => ['Tidak Merespon'],
        ]);
        $options->shouldReceive('exactOption')->andReturnUsing(function (array $values, ?string $value): ?string {
            foreach ($values as $option) {
                if (mb_strtolower(trim($option)) === mb_strtolower(trim((string) $value))) {
                    return $option;
                }
            }

            return null;
        });
        $this->app->instance(SalesLeadSheetOptionService::class, $options);

        $branch = $this->branch('Solo'.$suffix, 'SLO'.$suffix);
        $project = $this->project($branch, 'Solo Project');
        $sales = $this->sales($branch, $project, 'Team Sales');
        $coordinator = $this->user('sales_coordinator', $branch, 'Coordinator');
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id]);

        return [$branch, $project, $sales, $coordinator];
    }

    private function branch(string $name, string $code): Branch
    {
        return Branch::create(['name' => $name, 'code' => $code, 'sheet_id' => 'sheet-'.$code, 'is_active' => true]);
    }

    private function project(Branch $branch, string $name): LeadMaster
    {
        return LeadMaster::create(['branch_id' => $branch->id, 'project_name' => $name, 'sheet_project_name' => $name, 'is_active' => true]);
    }

    private function sales(Branch $branch, LeadMaster $project, string $name): User
    {
        $sales = $this->user('sales', $branch, $name);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);

        return $sales;
    }

    private function user(string $role, Branch $branch, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
    }

    private function lead(User $sales, LeadMaster $project, array $attributes = []): SalesLead
    {
        return SalesLead::create(array_merge([
            'branch_id' => $project->branch_id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-08-10',
            'customer_name' => 'Local Lead',
            'phone' => '081234567890',
            'source' => 'Referral',
            'source_name_snapshot' => 'Referral',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Referral',
            'external_sync_id' => (string) Str::uuid(),
            'sync_status' => 'pending_create',
            'created_by' => $sales->id,
            'updated_by' => $sales->id,
        ], $attributes));
    }

    private function leadData(Branch $branch, LeadMaster $project, User $sales, array $attributes = []): array
    {
        return array_merge([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-08-10',
            'customer_name' => 'Local Lead',
            'phone' => '081234567890',
            'source' => 'Referral',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Referral',
            'current_status' => 'no_response',
            'operation_uuid' => (string) Str::uuid(),
        ], $attributes);
    }

    private function writeResult(Branch $branch, string $syncId, string $externalLeadId): SalesLeadSpreadsheetWriteResult
    {
        return new SalesLeadSpreadsheetWriteResult($branch->sheet_id, 'lead', 2, $syncId, ['id_lead' => $externalLeadId]);
    }
}
