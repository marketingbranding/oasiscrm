<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\ProjectTransferBatch;
use App\Models\Role;
use App\Models\User;
use App\Services\ProjectTransferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProjectTransferServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_rejects_non_superadmin_even_when_called_outside_http(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $actor = $this->user('sales', $source);

        $this->expectException(AuthorizationException::class);
        app(ProjectTransferService::class)->execute($actor, $source, $target, [$project->id], []);
    }

    public function test_pending_create_leads_move_in_one_transaction(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $sales = $this->user('sales', $source);
        DB::table('project_user')->insert(['project_id' => $project->id, 'user_id' => $sales->id, 'is_active' => true, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_leads')->insert([
            'branch_id' => $source->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => 'UAT-Lead',
            'sync_status' => 'pending_create',
            'external_sync_id' => 'UAT-sync-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preview = app(ProjectTransferService::class)->preview($this->user('superadmin', $source), $source, $target, [$project->id], [$sales->id => 'pindah']);
        $this->assertSame([], $preview['blockers']);
        $this->assertSame(1, $preview['impact']['sales_leads']);

        $actor = $this->user('superadmin', $source);
        $result = app(ProjectTransferService::class)->execute($actor, $source, $target, [$project->id], [$sales->id => 'pindah']);

        $this->assertSame('cache_failed', $result['batch']->status);
        $this->assertSame($target->id, $project->fresh()->branch_id);
        $this->assertSame($target->id, DB::table('sales_leads')->where('project_id', $project->id)->value('branch_id'));
        $this->assertSame($target->id, DB::table('users')->where('id', $sales->id)->value('branch_id'));
    }

    public function test_project_membership_mapping_preserves_keduanya_and_archives_tetap_assignment(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $pindah = $this->user('sales', $source);
        $tetap = $this->user('sales', $source);
        $keduanya = $this->user('sales', $source);

        foreach ([$pindah, $tetap, $keduanya] as $user) {
            DB::table('branch_user')->where('user_id', $user->id)->where('branch_id', $source->id)->update([
                'can_view' => true,
                'can_edit' => true,
                'can_sync' => true,
                'can_manage_members' => false,
            ]);
            DB::table('project_user')->insert([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'is_active' => true,
                'is_primary' => true,
                'assignment_start_date' => today()->subMonth()->toDateString(),
                'assignment_end_date' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $actor = $this->user('superadmin', $source);
        app(ProjectTransferService::class)->execute(
            $actor,
            $source,
            $target,
            [$project->id],
            [$pindah->id => 'pindah', $tetap->id => 'tetap', $keduanya->id => 'keduanya'],
        );

        $this->assertTrue((bool) DB::table('project_user')->where(['project_id' => $project->id, 'user_id' => $pindah->id])->value('is_active'));
        $this->assertDatabaseHas('project_user', ['project_id' => $project->id, 'user_id' => $tetap->id, 'is_active' => false]);
        $this->assertSame(today()->toDateString(), DB::table('project_user')->where('project_id', $project->id)->where('user_id', $tetap->id)->value('assignment_end_date'));
        $this->assertTrue((bool) DB::table('project_user')->where(['project_id' => $project->id, 'user_id' => $keduanya->id])->value('is_active'));
        $this->assertDatabaseHas('users', ['id' => $pindah->id, 'branch_id' => $target->id]);
        $this->assertDatabaseHas('users', ['id' => $tetap->id, 'branch_id' => $source->id]);
        $this->assertDatabaseHas('users', ['id' => $keduanya->id, 'branch_id' => $source->id]);
        $this->assertSame(1, DB::table('branch_user')->where('user_id', $keduanya->id)->where('branch_id', $source->id)->where('is_current', true)->count());
        $this->assertSame(1, DB::table('branch_user')->where('user_id', $keduanya->id)->where('branch_id', $target->id)->where('is_current', true)->count());
        $this->assertSame($keduanya->role_id, User::query()->findOrFail($keduanya->id)->role_id);
    }

    public function test_transfer_routes_reject_non_superadmin_and_impersonation(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $staff = $this->user('sales', $source);

        $this->actingAs($staff)->get(route('projects.transfer.create'))->assertForbidden();

        $superadmin = $this->user('superadmin', $source);
        $this->actingAs($superadmin)->withSession(['impersonating_user_id' => $staff->id])->get(route('projects.transfer.create'))->assertForbidden();
    }

    public function test_rollback_rejects_non_superadmin_even_when_called_outside_http(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $superadmin = $this->user('superadmin', $source);
        $result = app(ProjectTransferService::class)->execute($superadmin, $source, $target, [$project->id], []);

        $this->expectException(AuthorizationException::class);
        app(ProjectTransferService::class)->rollback($this->user('sales', $source), $result['batch']);
    }

    public function test_rollback_restores_unchanged_transfer_rows(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $actor = $this->user('superadmin', $source);
        $result = app(ProjectTransferService::class)->execute($actor, $source, $target, [$project->id], []);

        app(ProjectTransferService::class)->rollback($actor, $result['batch']);

        $this->assertDatabaseHas('lead_master', ['id' => $project->id, 'project_name' => 'UAT-Tegal', 'branch_id' => $source->id]);
        $this->assertDatabaseHas('project_transfer_batches', ['id' => $result['batch']->id, 'status' => 'rollback_cache_failed']);
    }

    public function test_rollback_rejects_changed_row_without_overwriting_it(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $actor = $this->user('superadmin', $source);

        $result = app(ProjectTransferService::class)->execute($actor, $source, $target, [$project->id], []);
        $changedAt = now()->addMinute();
        DB::table('lead_master')->where('id', $project->id)->update(['project_name' => 'Changed after transfer', 'updated_at' => $changedAt]);

        try {
            app(ProjectTransferService::class)->rollback($actor, $result['batch']);
            $this->fail('Rollback harus menolak row yang berubah.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('lead_master', json_encode($exception->errors()));
        }

        $this->assertDatabaseHas('lead_master', ['id' => $project->id, 'project_name' => 'Changed after transfer', 'branch_id' => $target->id]);
        $this->assertDatabaseHas('project_transfer_batches', ['id' => $result['batch']->id, 'status' => 'cache_failed']);
    }

    public function test_rollback_rejects_rolled_back_batch_without_restoring_rows(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $batch = ProjectTransferBatch::query()->create([
            'source_branch_id' => $source->id,
            'target_branch_id' => $target->id,
            'created_by' => $this->user('superadmin', $source)->id,
            'project_ids' => [999],
            'user_mapping' => [],
            'status' => 'rolled_back',
        ]);

        $this->expectException(ValidationException::class);
        app(ProjectTransferService::class)->rollback($this->user('superadmin', $source), $batch);
    }

    public function test_committed_batch_remains_rollback_recoverable_after_cache_interrupt(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $actor = $this->user('superadmin', $source);
        $batch = ProjectTransferBatch::query()->create([
            'source_branch_id' => $source->id,
            'target_branch_id' => $target->id,
            'created_by' => $actor->id,
            'project_ids' => [$project->id],
            'user_mapping' => [],
            'status' => 'committed',
        ]);

        app(ProjectTransferService::class)->rollback($actor, $batch);

        $this->assertDatabaseHas('project_transfer_batches', ['id' => $batch->id, 'status' => 'rollback_cache_failed']);
    }

    public function test_lead_child_with_foreign_branch_blocks_project_transfer(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $sales = $this->user('sales', $source);
        $leadId = DB::table('sales_leads')->insertGetId([
            'branch_id' => $source->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => 'UAT-Lead',
            'sync_status' => 'pending_create',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sales_lead_status_histories')->insert([
            'sales_lead_id' => $leadId,
            'branch_id' => $target->id,
            'status' => 'baru',
            'source' => 'test',
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(ProjectTransferService::class)->execute($this->user('superadmin', $source), $source, $target, [$project->id], [$sales->id => 'tetap']);
    }

    public function test_pending_create_is_not_blocked_when_no_delivery_attempt_exists(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $sales = $this->user('sales', $source);
        DB::table('project_user')->insert(['project_id' => $project->id, 'user_id' => $sales->id, 'is_active' => true, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sales_leads')->insert([
            'branch_id' => $source->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => 'UAT-Lead',
            'sync_status' => 'pending_create',
            'external_sync_id' => 'UAT-sync-pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preview = app(ProjectTransferService::class)->preview($this->user('superadmin', $source), $source, $target, [$project->id], [$sales->id => 'pindah']);

        $this->assertSame([], $preview['blockers']);
    }

    public function test_remote_target_without_sync_timestamp_does_not_block_transfer(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $sales = $this->user('sales', $source);
        DB::table('sales_leads')->insert([
            'branch_id' => $source->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => 'UAT-Lead',
            'sync_status' => 'pending_create',
            'remote_target_branch_id' => $target->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $preview = app(ProjectTransferService::class)->preview($this->user('superadmin', $source), $source, $target, [$project->id], [$sales->id => 'tetap']);

        $this->assertNotContains('Lead proyek sudah terkirim ke spreadsheet.', $preview['blockers']);
    }

    public function test_locked_recheck_rejects_lead_sent_after_preview(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $sales = $this->user('sales', $source);
        $leadId = DB::table('sales_leads')->insertGetId([
            'branch_id' => $source->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => 'UAT-Lead',
            'sync_status' => 'pending_create',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = app(ProjectTransferService::class);
        $preview = $service->preview($this->user('superadmin', $source), $source, $target, [$project->id], [$sales->id => 'tetap']);
        $this->assertSame([], $preview['blockers']);
        DB::table('sales_leads')->where('id', $leadId)->update(['last_synced_at' => now(), 'sync_status' => 'synced']);

        $this->expectException(ValidationException::class);
        $service->execute($this->user('superadmin', $source), $source, $target, [$project->id], [$sales->id => 'tetap']);
    }

    public function test_reconciliation_child_moves_with_lead_branch(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $sales = $this->user('sales', $source);
        DB::table('project_user')->insert(['project_id' => $project->id, 'user_id' => $sales->id, 'is_active' => true, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $leadId = DB::table('sales_leads')->insertGetId([
            'branch_id' => $source->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => 'UAT-Lead',
            'sync_status' => 'pending_create',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reconciliationId = DB::table('sales_lead_lifecycle_reconciliation_items')->insertGetId([
            'branch_id' => $source->id,
            'sales_lead_id' => $leadId,
            'entity_type' => 'lead',
            'identity_key' => 'UAT-Lead',
            'issue_code' => 'test_issue',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(ProjectTransferService::class)->execute($this->user('superadmin', $source), $source, $target, [$project->id], [$sales->id => 'tetap']);

        $this->assertDatabaseHas('sales_lead_lifecycle_reconciliation_items', ['id' => $reconciliationId, 'branch_id' => $target->id]);
    }

    public function test_sent_lead_blocks_transfer(): void
    {
        $source = $this->branch('Batang', 'BAT', 'sheet-batang');
        $target = $this->branch('Tegal', 'TEG', 'sheet-tegal');
        $project = LeadMaster::query()->create(['branch_id' => $source->id, 'project_name' => 'UAT-Tegal', 'is_active' => true]);
        $sales = $this->user('sales', $source);
        DB::table('sales_leads')->insert([
            'branch_id' => $source->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => 'UAT-Lead',
            'sync_status' => 'synced',
            'external_sync_id' => 'UAT-sync-1',
            'last_synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $actor = $this->user('superadmin', $source);
        $this->expectException(ValidationException::class);
        app(ProjectTransferService::class)->execute($actor, $source, $target, [$project->id], [$sales->id => 'tetap']);
    }

    private function branch(string $name, string $code, string $sheetId): Branch
    {
        return Branch::query()->create(['name' => $name, 'code' => $code, 'sheet_id' => $sheetId, 'is_active' => true]);
    }

    private function user(string $role, Branch $branch): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
    }
}
