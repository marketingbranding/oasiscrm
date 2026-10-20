<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\SalesLeadBridgeSetting;
use App\Models\SalesLeadStatusHistory;
use App\Models\User;
use App\Services\PhoneNormalizationService;
use App\Services\SalesLeadBridgeModeService;
use App\Services\SalesLeadBridgeService;
use App\Services\SalesLeadLifecycleService;
use App\Services\SalesLeadService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SalesLeadCreateIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google_sheets.sales_lead_sync_enabled', false);
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_repeated_identical_create_reuses_one_lead_and_side_effects(): void
    {
        [$branch, $project, $sales, $coordinator] = $this->context('repeat');
        $data = $this->leadData($branch, $project, $sales, ['operation_uuid' => (string) Str::uuid()]);

        $first = $this->actingAs($coordinator)->post(route('sales-leads.store'), $data);
        $second = $this->actingAs($coordinator)->post(route('sales-leads.store'), $data);

        $first->assertRedirect()->assertSessionHasNoErrors();
        $second->assertRedirect()->assertSessionHasNoErrors();

        $lead = SalesLead::query()->sole();
        $this->assertSame(1, SalesLead::query()->where('branch_id', $branch->id)->count());
        $this->assertSame($data['operation_uuid'], $lead->external_sync_id);
        $this->assertSame(1, $lead->statusHistories()->where('operation_uuid', $data['operation_uuid'])->count());
        $this->assertSame(1, ActivityLog::query()->where('subject_type', SalesLead::class)->where('subject_id', $lead->id)->where('event', 'created')->count());
    }

    public function test_duplicate_identity_collision_is_recovered_without_sql_error(): void
    {
        [$branch, $project, $sales, $coordinator] = $this->context('race');
        $data = $this->leadData($branch, $project, $sales, ['operation_uuid' => (string) Str::uuid()]);
        $injected = false;

        DB::listen(function (QueryExecuted $query) use (&$injected, $data, $coordinator): void {
            if ($injected || ! str_contains($query->sql, 'external_sync_id') || ! str_contains(strtolower($query->sql), 'limit 1')) {
                return;
            }

            $injected = true;
            $lead = SalesLead::create([
                'branch_id' => $data['branch_id'],
                'project_id' => $data['project_id'],
                'sales_user_id' => $data['sales_user_id'],
                'lead_date' => $data['lead_date'],
                'customer_name' => $data['customer_name'],
                'phone' => $data['phone'],
                'normalized_phone' => app(PhoneNormalizationService::class)->normalize($data['phone']),
                'source' => $data['source'],
                'source_name_snapshot' => $data['source'],
                'platform' => $data['platform'],
                'campaign_name' => $data['campaign_name'],
                'current_status' => $data['current_status'],
                'external_sync_id' => $data['operation_uuid'],
                'sync_status' => 'pending_create',
                'created_by' => $coordinator->id,
                'updated_by' => $coordinator->id,
            ]);
            app(SalesLeadLifecycleService::class)->recordStatusHistory(
                $lead,
                $lead->current_status,
                'manual',
                (string) $coordinator->id,
                $coordinator,
                $lead->current_status_changed_at,
                operationUuid: $lead->external_sync_id,
            );
            $lead->logSalesActivity('created', [
                'branch_id' => $lead->branch_id,
                'project_id' => $lead->project_id,
                'sales_user_id' => $lead->sales_user_id,
            ]);
        });

        $result = app(SalesLeadService::class)->create($data, $coordinator);

        $this->assertTrue($injected);
        $this->assertModelExists($result);
        $this->assertSame(1, SalesLead::query()->where('branch_id', $branch->id)->count());
        $this->assertSame(1, SalesLeadStatusHistory::query()->where('operation_uuid', $data['operation_uuid'])->count());
        $this->assertSame(1, ActivityLog::query()->where('subject_type', SalesLead::class)->where('subject_id', $result->id)->where('event', 'created')->count());
    }

    public function test_reused_operation_uuid_with_different_payload_returns_validation_error(): void
    {
        [$branch, $project, $sales, $coordinator] = $this->context('mismatch');
        $data = $this->leadData($branch, $project, $sales, ['operation_uuid' => (string) Str::uuid()]);

        $this->actingAs($coordinator)->post(route('sales-leads.store'), $data)->assertRedirect();
        $response = $this->actingAs($coordinator)->from(route('sales-pocketbook.index'))->post(route('sales-leads.store'), array_merge($data, ['customer_name' => 'Different payload']));

        $response->assertRedirect()->assertSessionHasErrors('operation_uuid');
        $lead = SalesLead::query()->sole();
        $this->assertSame('Local Lead', $lead->customer_name);
    }

    public function test_reused_operation_uuid_for_soft_deleted_lead_returns_validation_error(): void
    {
        [$branch, $project, $sales, $coordinator] = $this->context('deleted');
        $data = $this->leadData($branch, $project, $sales, ['operation_uuid' => (string) Str::uuid()]);

        $this->actingAs($coordinator)->post(route('sales-leads.store'), $data)->assertRedirect();
        $lead = SalesLead::query()->sole();
        $lead->delete();

        $response = $this->actingAs($coordinator)->from(route('sales-pocketbook.index'))->post(route('sales-leads.store'), $data);

        $response->assertRedirect()->assertSessionHasErrors('operation_uuid');
        $this->assertSoftDeleted('sales_leads', ['id' => $lead->id]);
        $this->assertSame(1, SalesLead::withTrashed()->where('branch_id', $branch->id)->count());
    }

    public function test_same_operation_uuid_can_be_used_in_different_branches(): void
    {
        [$branch, $project, $sales, $coordinator] = $this->context('branch-one');
        [$otherBranch, $otherProject, $otherSales, $otherCoordinator] = $this->context('branch-two');
        $operationUuid = (string) Str::uuid();
        $service = app(SalesLeadService::class);

        $first = $service->create($this->leadData($branch, $project, $sales, ['operation_uuid' => $operationUuid]), $coordinator);
        $second = $service->create($this->leadData($otherBranch, $otherProject, $otherSales, ['operation_uuid' => $operationUuid]), $otherCoordinator);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, SalesLead::query()->where('branch_id', $branch->id)->where('external_sync_id', $operationUuid)->count());
        $this->assertSame(1, SalesLead::query()->where('branch_id', $otherBranch->id)->where('external_sync_id', $operationUuid)->count());
    }

    public function test_unrelated_unique_constraint_violation_is_not_swallowed(): void
    {
        [$branch, $project, $sales, $coordinator] = $this->context('unrelated');
        SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => '2026-08-10',
            'customer_name' => 'Existing remote lead',
            'phone' => '081234567890',
            'source' => 'Referral',
            'source_name_snapshot' => 'Referral',
            'platform' => 'WhatsApp',
            'campaign_name' => 'Referral',
            'external_lead_id' => 'REMOTE-1',
            'external_sync_id' => (string) Str::uuid(),
            'sync_status' => 'pending_create',
            'created_by' => $sales->id,
            'updated_by' => $sales->id,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('sales_leads.branch_id, sales_leads.external_lead_id');

        app(SalesLeadService::class)->create($this->leadData($branch, $project, $sales, [
            'operation_uuid' => (string) Str::uuid(),
            'external_lead_id' => 'REMOTE-1',
        ]), $coordinator);
    }

    public function test_push_runs_once_for_repeated_create(): void
    {
        config()->set('services.google_sheets.sales_lead_sync_enabled', true);
        [$branch, $project, $sales, $coordinator] = $this->context('push');
        SalesLeadBridgeSetting::create([
            'branch_id' => $branch->id,
            'mode' => 'push_only',
            'status' => 'active',
            'last_preflight_at' => now(),
            'last_preflight_hash' => hash('sha256', $branch->sheet_id),
            'enabled_at' => now(),
        ]);
        $bridge = Mockery::mock(SalesLeadBridgeService::class);
        $bridge->shouldReceive('push')->once()->andReturn(['ok' => true, 'status' => 'synced']);
        $service = new SalesLeadService(
            app(PhoneNormalizationService::class),
            app(SalesLeadLifecycleService::class),
            app(SalesLeadBridgeModeService::class),
            $bridge,
        );
        $data = $this->leadData($branch, $project, $sales, ['operation_uuid' => (string) Str::uuid()]);

        $service->create($data, $coordinator);
        $service->create($data, $coordinator);

        $this->assertSame(1, SalesLead::query()->count());
    }

    public function test_duplicate_phone_warning_remains_for_a_new_operation(): void
    {
        [$branch, $project, $sales, $coordinator] = $this->context('phone');
        $first = $this->leadData($branch, $project, $sales, ['operation_uuid' => (string) Str::uuid()]);
        $second = $this->leadData($branch, $project, $sales, [
            'operation_uuid' => (string) Str::uuid(),
            'customer_name' => 'Second lead',
        ]);

        $this->actingAs($coordinator)->post(route('sales-leads.store'), $first)->assertRedirect();
        $response = $this->actingAs($coordinator)->post(route('sales-leads.store'), $second);

        $response->assertRedirect()->assertSessionHas('duplicate_warning');
        $this->assertSame(2, SalesLead::query()->where('branch_id', $branch->id)->count());
    }

    public function test_missing_operation_uuid_is_rejected(): void
    {
        [$branch, $project, $sales, $coordinator] = $this->context('missing');
        $data = $this->leadData($branch, $project, $sales);
        unset($data['operation_uuid']);

        $response = $this->actingAs($coordinator)->from(route('sales-pocketbook.index'))->post(route('sales-leads.store'), $data);

        $response->assertRedirect()->assertSessionHasErrors('operation_uuid');
        $this->assertSame(0, SalesLead::query()->count());
    }

    public function test_invalid_operation_uuid_is_rejected(): void
    {
        [$branch, $project, $sales, $coordinator] = $this->context('invalid');
        $data = $this->leadData($branch, $project, $sales, ['operation_uuid' => 'not-a-uuid']);

        $response = $this->actingAs($coordinator)->from(route('sales-pocketbook.index'))->post(route('sales-leads.store'), $data);

        $response->assertRedirect()->assertSessionHasErrors('operation_uuid');
        $this->assertSame(0, SalesLead::query()->count());
    }

    public function test_idempotency_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Perbaikan Idempotensi Pembuatan Lead';
        $migration = require database_path('migrations/2026_10_20_154019_add_sales_lead_create_idempotency_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);

        $this->actingAs($actor)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

    private function context(string $suffix): array
    {
        $branch = Branch::create(['name' => 'Branch '.$suffix, 'code' => 'BR'.strtoupper($suffix), 'sheet_id' => 'sheet-'.$suffix, 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project '.$suffix, 'sheet_project_name' => 'Project '.$suffix, 'is_active' => true]);
        $sales = User::factory()->create([
            'name' => 'Sales '.$suffix,
            'role_id' => Role::where('slug', 'sales')->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $sales->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        $coordinator = User::factory()->create([
            'name' => 'Coordinator '.$suffix,
            'role_id' => Role::where('slug', 'sales_coordinator')->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        SalesCoordinatorSales::create(['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id]);

        return [$branch, $project, $sales, $coordinator];
    }

    private function leadData(Branch $branch, LeadMaster $project, User $sales, array $overrides = []): array
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
        ], $overrides);
    }
}
