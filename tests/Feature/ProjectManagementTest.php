<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\OptimisticLockService;
use App\Services\ProjectAdministrationService;
use App\Services\SalesLeadSheetOptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_access_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Pengaturan Proyek dan Akses Lebih Aman';
        $migration = require database_path('migrations/2026_08_28_000005_add_project_access_hardening_changelog.php');

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->actingAs($this->user('superadmin'))->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

    public function test_only_primary_superadmin_can_update_or_archive_project(): void
    {
        $project = $this->project($this->branch('Solo'));
        $manager = $this->user('manager');
        $manager->roles()->attach(Role::query()->where('slug', 'superadmin')->firstOrFail());

        $this->actingAs($manager)->put(route('projects.update', $project), $this->updateData($project, ['project_name' => 'Tidak Sah']))
            ->assertForbidden();
        $this->actingAs($manager)->delete(route('projects.destroy', $project), ['expected_updated_at' => app(OptimisticLockService::class)->token($project)])
            ->assertForbidden();

        $this->assertSame('Proyek', $project->fresh()->project_name);
        $this->assertTrue($project->fresh()->is_active);
    }

    public function test_update_ignores_is_active_field(): void
    {
        $actor = $this->user('superadmin');
        $project = $this->project($this->branch('Solo'));

        $this->actingAs($actor)->put(route('projects.update', $project), $this->updateData($project, [
            'project_name' => 'Nama Baru',
            'is_active' => '0',
        ]))->assertRedirect();

        $project->refresh();
        $this->assertSame('Nama Baru', $project->project_name);
        $this->assertTrue($project->is_active);
    }

    public function test_inactive_project_can_be_renamed_but_stays_inactive(): void
    {
        $actor = $this->user('superadmin');
        $project = $this->project($this->branch('Solo'));
        $project->forceFill(['is_active' => false])->save();

        $this->actingAs($actor)->put(route('projects.update', $project), $this->updateData($project, [
            'project_name' => 'Nama Baru',
            'is_active' => '1',
        ]))->assertRedirect();

        $project->refresh();
        $this->assertSame('Nama Baru', $project->project_name);
        $this->assertFalse($project->is_active);
    }

    public function test_rename_normalizes_name_and_preserves_identity_branch_sheet_alias_and_relations(): void
    {
        $actor = $this->user('superadmin');
        $branch = $this->branch('Solo');
        $project = $this->project($branch, 'Proyek Lama', 'ALIAS-SHEET');
        $kavling = Kavling::query()->create(['project_id' => $project->id, 'kavling_code' => 'A-1', 'name' => 'A-1']);
        $member = $this->user('sales');
        $project->assignedUsers()->attach($member->id, ['is_primary' => true, 'is_active' => true]);

        $this->actingAs($actor)->put(route('projects.update', $project), $this->updateData($project, [
            'project_name' => "  Proyek\t Baru  ",
        ]))->assertRedirect();

        $project->refresh();
        $this->assertSame('Proyek Baru', $project->project_name);
        $this->assertSame($branch->id, $project->branch_id);
        $this->assertSame('ALIAS-SHEET', $project->sheet_project_name);
        $this->assertDatabaseHas('kavlings', ['id' => $kavling->id, 'project_id' => $project->id]);
        $this->assertDatabaseHas('project_user', ['project_id' => $project->id, 'user_id' => $member->id]);

        $log = ActivityLog::query()->where('event', 'project_renamed')->sole();
        $this->assertSame('Proyek Lama', $log->properties['old_name']);
        $this->assertSame('Proyek Baru', $log->properties['new_name']);
        $this->assertTrue($log->subject->is($project));
        $this->assertTrue($log->causer->is($actor));
    }

    public function test_canonical_rename_with_blank_alias_keeps_old_name_without_remote_call(): void
    {
        $actor = $this->user('superadmin');
        $project = $this->project($this->branch('Solo'), 'Proyek Lama');

        $options = Mockery::mock(SalesLeadSheetOptionService::class);
        $options->shouldReceive('forBranch')->never();
        $this->app->instance(SalesLeadSheetOptionService::class, $options);

        $this->actingAs($actor)->put(route('projects.update', $project), $this->updateData($project, [
            'project_name' => 'Proyek Baru',
            'sheet_project_name' => '',
        ]))->assertRedirect();

        $project->refresh();
        $this->assertSame('Proyek Baru', $project->project_name);
        $this->assertSame('Proyek Lama', $project->sheet_project_name);
    }

    public function test_canonical_rename_with_workbook_preflights_exact_option_and_blocks_on_google_failure(): void
    {
        $actor = $this->user('superadmin');
        $project = $this->project($this->branch('Solo', true, 'sheet-123'), 'Proyek Lama');

        $options = Mockery::mock(SalesLeadSheetOptionService::class);
        $options->shouldReceive('forBranch')->once()->andThrow(new \RuntimeException('Google unavailable'));
        $this->app->instance(SalesLeadSheetOptionService::class, $options);

        $this->actingAs($actor)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->updateData($project, [
                'project_name' => 'Proyek Baru',
                'sheet_project_name' => '',
            ]))
            ->assertSessionHasErrors('sheet_project_name');

        $project->refresh();
        $this->assertSame('Proyek Lama', $project->project_name);
        $this->assertNull($project->sheet_project_name);
        $this->assertSame(0, ActivityLog::query()->where('event', 'project_renamed')->count());
    }

    public function test_canonical_rename_with_workbook_blocks_when_old_name_not_remote_option(): void
    {
        $actor = $this->user('superadmin');
        $project = $this->project($this->branch('Solo', true, 'sheet-123'), 'Proyek Lama');

        $options = Mockery::mock(SalesLeadSheetOptionService::class)->makePartial();
        $options->shouldReceive('forBranch')->once()->andReturn(['project' => ['Lain'], 'promo' => [], 'source' => [], 'channel' => [], 'activity' => [], 'sales' => [], 'status' => []]);
        $this->app->instance(SalesLeadSheetOptionService::class, $options);

        $this->actingAs($actor)->put(route('projects.update', $project), $this->updateData($project, [
            'project_name' => 'Proyek Baru',
            'sheet_project_name' => '',
        ]))->assertSessionHasErrors('sheet_project_name');

        $project->refresh();
        $this->assertSame('Proyek Lama', $project->project_name);
    }

    public function test_canonical_rename_with_workbook_preserves_old_name_after_exact_verification(): void
    {
        $actor = $this->user('superadmin');
        $project = $this->project($this->branch('Solo', true, 'sheet-123'), 'Proyek Lama');

        $options = Mockery::mock(SalesLeadSheetOptionService::class)->makePartial();
        $options->shouldReceive('forBranch')->once()->andReturn(['project' => ['Proyek Lama', 'Lain'], 'promo' => [], 'source' => [], 'channel' => [], 'activity' => [], 'sales' => [], 'status' => []]);
        $this->app->instance(SalesLeadSheetOptionService::class, $options);

        $response = $this->actingAs($actor)->put(route('projects.update', $project), $this->updateData($project, [
            'project_name' => 'Proyek Baru',
            'sheet_project_name' => '',
        ]));
        $response->assertRedirect();
        $project->refresh();
        $this->assertSame('Proyek Baru', $project->project_name);
        $this->assertSame('Proyek Lama', $project->sheet_project_name);
    }

    public function test_branch_move_is_rejected_with_indonesian_dependency_summary(): void
    {
        $actor = $this->user('superadmin');
        $source = $this->branch('Solo');
        $destination = $this->branch('Madiun');
        $project = $this->project($source);
        Kavling::query()->create(['project_id' => $project->id, 'kavling_code' => 'A-1', 'name' => 'A-1']);

        $this->actingAs($actor)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->updateData($project, ['branch_id' => $destination->id]))
            ->assertRedirect(route('projects.edit', $project))
            ->assertSessionHasErrors('branch_id');

        $this->assertStringContainsString('dependensi', session('errors')->first('branch_id'));
        $this->assertStringContainsString('kavling', session('errors')->first('branch_id'));
        $this->assertSame($source->id, $project->fresh()->branch_id);
    }

    public function test_branch_move_blocked_by_legacy_content_item_name_reference(): void
    {
        $actor = $this->user('superadmin');
        $source = $this->branch('Solo');
        $destination = $this->branch('Madiun');
        $project = $this->project($source, 'Proyek Teks');
        DB::table('content_items')->insert([
            'branch_id' => $source->id,
            'title' => 'Agenda lama',
            'project_name' => '  proyek   teks ',
            'sales_project_id' => null,
            'scheduled_date' => now()->toDateString(),
            'status' => 'planned',
            'item_type' => 'agenda',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)->put(route('projects.update', $project), $this->updateData($project, ['branch_id' => $destination->id]))
            ->assertSessionHasErrors('branch_id');

        $this->assertStringContainsString('nama proyek teks', session('errors')->first('branch_id'));
        $this->assertSame($source->id, $project->fresh()->branch_id);
    }

    public function test_branch_move_blocked_by_legacy_dana_talangan_name_reference(): void
    {
        $actor = $this->user('superadmin');
        $source = $this->branch('Solo');
        $destination = $this->branch('Madiun');
        $project = $this->project($source, 'Proyek Teks');
        DB::table('dana_talangans')->insert([
            'tanggal' => now()->toDateString(),
            'nama_konsumen' => 'Konsumen',
            'project_name' => 'proyek teks',
            'project_id' => null,
            'branch_id' => $source->id,
            'status' => 'aktif',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($actor)->put(route('projects.update', $project), $this->updateData($project, ['branch_id' => $destination->id]))
            ->assertSessionHasErrors('branch_id');

        $this->assertStringContainsString('nama proyek teks', session('errors')->first('branch_id'));
        $this->assertSame($source->id, $project->fresh()->branch_id);
    }

    public function test_empty_project_can_move_to_active_destination_and_records_moved_audit(): void
    {
        $actor = $this->user('superadmin');
        $source = $this->branch('Solo');
        $destination = $this->branch('Madiun');
        $project = $this->project($source);

        $this->actingAs($actor)->put(route('projects.update', $project), $this->updateData($project, ['branch_id' => $destination->id]))
            ->assertRedirect();

        $project->refresh();
        $this->assertSame($destination->id, $project->branch_id);

        $log = ActivityLog::query()->where('event', 'project_moved')->sole();
        $this->assertSame($source->id, $log->properties['old_branch_id']);
        $this->assertSame($destination->id, $log->properties['new_branch_id']);
        $this->assertTrue($log->subject->is($project));
        $this->assertTrue($log->causer->is($actor));
    }

    public function test_branch_move_requires_active_destination(): void
    {
        $actor = $this->user('superadmin');
        $source = $this->branch('Solo');
        $destination = $this->branch('Arsip', false);
        $project = $this->project($source);

        $this->actingAs($actor)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->updateData($project, ['branch_id' => $destination->id]))
            ->assertSessionHasErrors('branch_id');

        $this->assertSame($source->id, $project->fresh()->branch_id);
    }

    public function test_sheet_bound_project_cannot_move_branch(): void
    {
        $actor = $this->user('superadmin');
        $source = $this->branch('Solo');
        $destination = $this->branch('Madiun');
        $project = $this->project($source, 'Proyek', 'ALIAS-SHEET');

        $this->actingAs($actor)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->updateData($project, ['branch_id' => $destination->id]))
            ->assertSessionHasErrors('branch_id');

        $this->assertStringContainsString('spreadsheet', session('errors')->first('branch_id'));
        $this->assertSame($source->id, $project->fresh()->branch_id);
    }

    public function test_destroy_archives_project_and_is_idempotent_without_deleting_relations(): void
    {
        $actor = $this->user('superadmin');
        $project = $this->project($this->branch('Solo'));
        $kavling = Kavling::query()->create(['project_id' => $project->id, 'kavling_code' => 'A-1', 'name' => 'A-1']);
        $token = ['expected_updated_at' => app(OptimisticLockService::class)->token($project)];

        $this->actingAs($actor)->delete(route('projects.destroy', $project), $token)
            ->assertRedirect()
            ->assertSessionHas('success', 'Proyek berhasil dinonaktifkan.');
        $this->assertFalse($project->fresh()->is_active);
        $this->assertDatabaseHas('kavlings', ['id' => $kavling->id]);
        $this->assertSame(1, ActivityLog::query()->where('event', 'project_archived')->count());

        $this->actingAs($actor)->delete(route('projects.destroy', $project), [
            'expected_updated_at' => app(OptimisticLockService::class)->token($project->fresh()),
        ])->assertSessionHas('warning', 'Proyek sudah nonaktif.');
        $this->assertSame(1, ActivityLog::query()->where('event', 'project_archived')->count());
        $this->assertDatabaseHas('lead_master', ['id' => $project->id]);
    }

    public function test_archive_action_is_hidden_for_inactive_project(): void
    {
        $actor = $this->user('superadmin');
        $project = $this->project($this->branch('Solo'));
        $project->forceFill(['is_active' => false])->save();

        $this->actingAs($actor)->get(route('projects.index', ['branch_id' => $project->branch_id]))
            ->assertOk()
            ->assertDontSee('Nonaktifkan');
        $this->actingAs($actor)->get(route('projects.edit', $project))
            ->assertOk()
            ->assertDontSee('Nonaktifkan Proyek');
    }

    public function test_stale_archive_returns_conflict_without_mutating_project(): void
    {
        $actor = $this->user('superadmin');
        $project = $this->project($this->branch('Solo'));

        $this->actingAs($actor)->delete(route('projects.destroy', $project), ['expected_updated_at' => '2000-01-01 00:00:00'])
            ->assertRedirect()
            ->assertSessionHas('conflict', OptimisticLockService::MESSAGE);

        $project->refresh();
        $this->assertTrue($project->is_active);
        $this->assertSame(0, ActivityLog::query()->where('event', 'project_archived')->count());
    }

    public function test_duplicate_active_name_is_rejected_case_and_whitespace_insensitively(): void
    {
        $actor = $this->user('superadmin');
        $branch = $this->branch('Solo');
        $this->project($branch, 'Proyek Utama');
        $project = $this->project($branch, 'Proyek Kedua');

        $this->actingAs($actor)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->updateData($project, ['project_name' => '  PROYEK   utama ']))
            ->assertSessionHasErrors('project_name');

        $this->assertSame('Proyek Kedua', $project->fresh()->project_name);
    }

    public function test_update_rejects_canonical_colliding_with_active_alias(): void
    {
        $actor = $this->user('superadmin');
        $branch = $this->branch('Solo');
        $this->project($branch, 'Proyek Utama', 'ALIAS-KEDUA');
        $project = $this->project($branch, 'Proyek Kedua');

        $this->actingAs($actor)->put(route('projects.update', $project), $this->updateData($project, ['project_name' => 'alias-kedua']))
            ->assertSessionHasErrors('project_name');

        $this->assertSame('Proyek Kedua', $project->fresh()->project_name);
    }

    public function test_update_rejects_alias_colliding_with_active_canonical(): void
    {
        $actor = $this->user('superadmin');
        $branch = $this->branch('Solo');
        $this->project($branch, 'Proyek Utama');
        $project = $this->project($branch, 'Proyek Kedua');

        $this->actingAs($actor)->put(route('projects.update', $project), $this->updateData($project, ['sheet_project_name' => 'proyek utama']))
            ->assertSessionHasErrors('sheet_project_name');

        $this->assertNull($project->fresh()->sheet_project_name);
    }

    public function test_store_rejects_duplicate_canonical_or_alias_and_inactive_branch(): void
    {
        $actor = $this->user('superadmin');
        $branch = $this->branch('Solo');
        $inactive = $this->branch('Arsip', false);
        $this->project($branch, 'Proyek Utama', 'ALIAS-LAIN');

        $base = ['branch_id' => $branch->id, 'project_name' => 'Proyek Baru', 'is_active' => '1'];

        $this->actingAs($actor)->post(route('projects.store'), array_replace($base, ['project_name' => 'PROYEK   utama']))
            ->assertSessionHasErrors('project_name');

        $this->actingAs($actor)->post(route('projects.store'), array_replace($base, ['sheet_project_name' => 'proyek utama']))
            ->assertSessionHasErrors('sheet_project_name');

        $this->actingAs($actor)->post(route('projects.store'), array_replace($base, ['sheet_project_name' => 'alias-lain']))
            ->assertSessionHasErrors('sheet_project_name');

        $this->actingAs($actor)->post(route('projects.store'), ['branch_id' => $inactive->id, 'project_name' => 'Proyek Arsip'])
            ->assertSessionHasErrors('branch_id');

        $this->assertSame(1, LeadMaster::query()->where('branch_id', $branch->id)->count());
    }

    public function test_edit_renders_warning_when_google_project_options_fail(): void
    {
        $actor = $this->user('superadmin');
        $project = $this->project($this->branch('Solo', true, 'sheet-123'), 'Proyek', 'ALIAS-SHEET');
        $options = Mockery::mock(SalesLeadSheetOptionService::class);
        $options->shouldReceive('forBranch')->once()->andThrow(new \RuntimeException('Google unavailable'));
        $this->app->instance(SalesLeadSheetOptionService::class, $options);

        $this->actingAs($actor)->get(route('projects.edit', $project))
            ->assertOk()
            ->assertSee('Opsi proyek spreadsheet sedang tidak tersedia.')
            ->assertSee('ALIAS-SHEET');
    }

    public function test_stale_update_returns_conflict_and_preserves_project(): void
    {
        $actor = $this->user('superadmin');
        $project = $this->project($this->branch('Solo'));
        $data = $this->updateData($project, ['expected_updated_at' => '2000-01-01 00:00:00', 'project_name' => 'Nama Baru']);

        $this->actingAs($actor)->put(route('projects.update', $project), $data)
            ->assertRedirect()
            ->assertSessionHas('conflict', OptimisticLockService::MESSAGE);

        $this->assertSame('Proyek', $project->fresh()->project_name);
    }

    public function test_store_requires_branch_and_rejects_inactive_destination(): void
    {
        $actor = $this->user('superadmin');
        $inactive = $this->branch('Arsip', false);
        $before = LeadMaster::query()->count();

        $this->actingAs($actor)->post(route('projects.store'), ['project_name' => 'Proyek'])
            ->assertSessionHasErrors('branch_id');

        $this->actingAs($actor)->post(route('projects.store'), ['branch_id' => $inactive->id, 'project_name' => 'Proyek'])
            ->assertSessionHasErrors('branch_id');

        $this->assertSame($before, LeadMaster::query()->count());
    }

    public function test_store_canonicalizes_alias_to_exact_remote_option(): void
    {
        $actor = $this->user('superadmin');
        $branch = $this->branch('Solo', true, 'sheet-123');

        $this->mockSheetOptions(function ($options): void {
            $options->shouldReceive('forBranch')->once()->andReturn(['project' => ['Proyek Utama'], 'promo' => [], 'source' => [], 'channel' => [], 'activity' => [], 'sales' => [], 'status' => []]);
        });

        $this->actingAs($actor)->post(route('projects.store'), [
            'branch_id' => $branch->id,
            'project_name' => "  Proyek\t Baru  ",
            'sheet_project_name' => '  proyek   utama  ',
        ])->assertRedirect();

        $project = LeadMaster::query()->where('branch_id', $branch->id)->sole();
        $this->assertSame('Proyek Baru', $project->project_name);
        $this->assertSame('Proyek Utama', $project->sheet_project_name);
    }

    public function test_store_reports_google_outage_and_never_creates(): void
    {
        $actor = $this->user('superadmin');
        $branch = $this->branch('Solo', true, 'sheet-123');
        $before = LeadMaster::query()->count();

        $this->mockSheetOptions(function ($options): void {
            $options->shouldReceive('forBranch')->once()->andThrow(new \RuntimeException('Google unavailable'));
        });

        $this->actingAs($actor)->from(route('projects.create'))
            ->post(route('projects.store'), [
                'branch_id' => $branch->id,
                'project_name' => 'Proyek Baru',
                'sheet_project_name' => 'Proyek Utama',
            ])
            ->assertSessionHasErrors('sheet_project_name')
            ->assertRedirect(route('projects.create'));

        $this->assertSame($before, LeadMaster::query()->count());
    }

    public function test_service_create_rejects_active_identity_collision_after_lock(): void
    {
        $branch = $this->branch('Solo');
        $this->project($branch, 'Proyek Utama', 'ALIAS-LAIN');
        $service = $this->app->make(ProjectAdministrationService::class);

        try {
            $service->create(['branch_id' => $branch->id, 'project_name' => 'proyek   utama']);
            $this->fail('Expected validation exception for canonical collision.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('project_name', $e->errors());
        }

        try {
            $service->create(['branch_id' => $branch->id, 'project_name' => 'Proyek Kedua', 'sheet_project_name' => 'alias-lain']);
            $this->fail('Expected validation exception for alias collision.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sheet_project_name', $e->errors());
        }

        $this->assertSame(1, LeadMaster::query()->where('branch_id', $branch->id)->count());
    }

    public function test_move_to_sheet_destination_requires_exact_canonical_option(): void
    {
        $actor = $this->user('superadmin');
        $source = $this->branch('Solo');
        $destination = $this->branch('Madiun', true, 'sheet-123');
        $project = $this->project($source, 'Proyek Sendirian');

        $this->mockSheetOptions(function ($options) {
            $options->shouldReceive('forBranch')->once()->andReturn(['project' => ['Lain'], 'promo' => [], 'source' => [], 'channel' => [], 'activity' => [], 'sales' => [], 'status' => []]);
        });

        $this->actingAs($actor)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->updateData($project, ['branch_id' => $destination->id]));

        $this->assertSessionErrorFor('sheet_project_name');

        $project->refresh();
        $this->assertSame($source->id, $project->branch_id);
        $this->assertNull($project->sheet_project_name);
        $this->assertSame(0, ActivityLog::query()->where('event', 'project_moved')->count());
    }

    public function test_move_to_sheet_destination_is_blocked_when_google_unavailable(): void
    {
        $actor = $this->user('superadmin');
        $source = $this->branch('Solo');
        $destination = $this->branch('Madiun', true, 'sheet-123');
        $project = $this->project($source, 'Proyek Sendirian');

        $this->mockSheetOptions(function ($options) {
            $options->shouldReceive('forBranch')->once()->andThrow(new \RuntimeException('Google unavailable'));
        });

        $this->actingAs($actor)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->updateData($project, ['branch_id' => $destination->id]));

        $this->assertSessionErrorFor('sheet_project_name');

        $project->refresh();
        $this->assertSame($source->id, $project->branch_id);
        $this->assertNull($project->sheet_project_name);
        $this->assertSame(0, ActivityLog::query()->where('event', 'project_moved')->count());
    }

    public function test_move_to_sheet_destination_persists_exact_remote_identity(): void
    {
        $actor = $this->user('superadmin');
        $source = $this->branch('Solo');
        $destination = $this->branch('Madiun', true, 'sheet-123');
        $project = $this->project($source, 'Proyek Sendirian');

        $this->mockSheetOptions(function ($options) {
            $options->shouldReceive('forBranch')->once()->andReturn(['project' => ['Lain', 'Proyek Sendirian'], 'promo' => [], 'source' => [], 'channel' => [], 'activity' => [], 'sales' => [], 'status' => []]);
        });

        $this->actingAs($actor)->put(route('projects.update', $project), $this->updateData($project, ['branch_id' => $destination->id]))
            ->assertRedirect();

        $project->refresh();
        $this->assertSame($destination->id, $project->branch_id);
        $this->assertNull($project->sheet_project_name);

        $log = ActivityLog::query()->where('event', 'project_moved')->sole();
        $this->assertSame($destination->id, $log->properties['new_branch_id']);
    }

    private function assertSessionErrorFor(string $key): void
    {
        $bag = session('errors');
        $this->assertNotNull($bag, 'Expected validation errors in session.');
        if ($bag instanceof ViewErrorBag) {
            $messages = $bag->getBag('default')->getMessages();
        } elseif (is_array($bag)) {
            $messages = is_array($bag['default']['messages'] ?? null) ? $bag['default']['messages'] : ($bag['messages'] ?? []);
        } else {
            $messages = method_exists($bag, 'getMessages') ? $bag->getMessages() : [];
        }

        $this->assertArrayHasKey($key, $messages, 'Expected validation error on '.$key.', grabbed: '.implode(',', array_keys($messages)));
    }

    private function mockSheetOptions(callable $setup): void
    {
        $options = Mockery::mock(SalesLeadSheetOptionService::class)->makePartial();
        $setup($options);
        $this->app->instance(SalesLeadSheetOptionService::class, $options);
    }

    private function updateData(LeadMaster $project, array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $project->branch_id,
            'project_name' => $project->project_name,
            'sheet_project_name' => $project->sheet_project_name,
            'expected_updated_at' => app(OptimisticLockService::class)->token($project),
        ], $overrides);
    }

    private function branch(string $name, bool $active = true, ?string $sheetId = null): Branch
    {
        return Branch::query()->create([
            'name' => $name,
            'code' => strtoupper(substr($name, 0, 3)).random_int(100, 999),
            'sheet_id' => $sheetId,
            'is_active' => $active,
        ]);
    }

    private function project(Branch $branch, string $name = 'Proyek', ?string $sheetName = null): LeadMaster
    {
        return LeadMaster::query()->create([
            'branch_id' => $branch->id,
            'project_name' => $name,
            'sheet_project_name' => $sheetName,
            'is_active' => true,
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'password_changed_at' => now(),
        ]);
    }
}
