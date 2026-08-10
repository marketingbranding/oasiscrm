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
use App\ValueObjects\SalesLeadSpreadsheetWriteResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CoordinatorLocalFirstLeadTest extends TestCase
{
    use RefreshDatabase;

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

        $result = app(CoordinatorLeadPushService::class)->push($coordinator);

        $this->assertSame(['processed' => 1, 'synced' => 1, 'failed' => 0], $result);
        $this->assertSame('synced', $lead->fresh()->sync_status);
        $this->assertSame($syncId, $lead->fresh()->external_sync_id);
    }

    public function test_push_failure_preserves_lead_retry_succeeds_and_synced_second_push_is_noop(): void
    {
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

    public function test_push_is_isolated_to_accessible_branch(): void
    {
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

    private function context(): array
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

        $branch = $this->branch('Solo', 'SLO');
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
            'campaign_name' => 'Campaign',
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
            'campaign_name' => 'Campaign',
            'operation_uuid' => (string) Str::uuid(),
        ], $attributes);
    }

    private function writeResult(Branch $branch, string $syncId, string $externalLeadId): SalesLeadSpreadsheetWriteResult
    {
        return new SalesLeadSpreadsheetWriteResult($branch->sheet_id, 'lead', 2, $syncId, ['id_lead' => $externalLeadId]);
    }
}
