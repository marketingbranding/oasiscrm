<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\Customer;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsumerDatabaseWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_is_scoped_and_renders_paginated_local_applications(): void
    {
        [$user, $branch, $project] = $this->context();
        $visible = $this->application($branch, $project, 'Konsumen Terlihat');
        [, $foreignBranch, $foreignProject] = $this->context('manager');
        $this->application($foreignBranch, $foreignProject, 'Konsumen Rahasia');

        $this->actingAs($user)
            ->get(route('consumer-database.workspace'))
            ->assertOk()
            ->assertSee('Konsumen Terlihat')
            ->assertDontSee('Konsumen Rahasia')
            ->assertSee($visible->id_transaksi)
            ->assertDontSee('id="consumer-workspace-branch"');

        $this->actingAs($user)
            ->get(route('consumer-database.workspace', ['search' => 'Konsumen Terlihat']))
            ->assertOk()
            ->assertSee('Konsumen Terlihat')
            ->assertDontSee('Konsumen Rahasia');
    }

    public function test_workspace_rejects_requested_foreign_context_and_detail(): void
    {
        [$user] = $this->context();
        [, $foreignBranch, $foreignProject] = $this->context('manager');
        $foreign = $this->application($foreignBranch, $foreignProject, 'Konsumen Rahasia');

        $this->actingAs($user)
            ->get(route('consumer-database.workspace', ['branch_id' => $foreignBranch->id]))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('consumer-database.workspace.show', $foreign))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('consumer-database.workspace', ['project_id' => $foreignProject->id]))
            ->assertForbidden();
    }

    public function test_workspace_supports_nik_sales_and_bank_filters_without_exposing_nik(): void
    {
        [$user, $branch, $project] = $this->context();
        $application = $this->application($branch, $project, 'Konsumen Filter', null, '3308106504650001', $user->id);
        ConsumerBankProcess::query()->create([
            'consumer_application_id' => $application->id,
            'bank_name' => 'Bank Contoh',
            'status' => 'approved',
        ]);
        $this->application($branch, $project, 'Konsumen Lainnya', null, '3308106504650002');

        $this->actingAs($user)
            ->get(route('consumer-database.workspace', [
                'search' => '3308106504650001',
                'sales_id' => $user->id,
                'bank' => 'Bank Contoh',
            ]))
            ->assertOk()
            ->assertSee('Konsumen Filter')
            ->assertDontSee('Konsumen Lainnya')
            ->assertSee('Bank Contoh');

        $this->actingAs($user)
            ->getJson(route('consumer-database.workspace.show', $application))
            ->assertOk()
            ->assertJsonMissingPath('data.overview.nik');
    }

    public function test_detail_returns_safe_overview_process_and_payment_data(): void
    {
        [$user, $branch, $project] = $this->context();
        $application = $this->application($branch, $project, 'Detail Konsumen', 'Lanjut');
        $application->stageEvents()->create([
            'stage' => 'PSJB',
            'event_date' => today(),
            'status' => 'complete',
            'occurred_at' => now(),
        ]);
        $application->bankProcesses()->create([
            'bank_name' => 'Bank Contoh',
            'status' => 'approved',
            'approved_plafond' => 250000000,
        ]);

        $this->actingAs($user)
            ->getJson(route('consumer-database.workspace.show', $application))
            ->assertOk()
            ->assertJsonPath('data.overview.customer_name', 'Detail Konsumen')
            ->assertJsonPath('data.process.0.stage', 'PSJB')
            ->assertJsonPath('data.payment.0.bank_name', 'Bank Contoh')
            ->assertJsonPath('data.counts.process', 1)
            ->assertJsonPath('data.counts.payment', 1)
            ->assertJsonMissingPath('data.overview.nik');
    }

    public function test_detail_returns_human_readable_activity_and_process_labels(): void
    {
        [$user, $branch, $project] = $this->context();
        $application = $this->application($branch, $project, 'Timeline Konsumen', 'Lanjut');
        $application->stageEvents()->create([
            'stage' => 'ready_100',
            'event_date' => today(),
            'status' => 'confirmed',
            'occurred_at' => now(),
        ]);
        ActivityLog::query()->create([
            'causer_id' => $user->id,
            'subject_type' => ConsumerApplication::class,
            'subject_id' => $application->id,
            'event' => 'consumer_ready_100_recorded',
            'description' => 'Perubahan lifecycle aplikasi konsumen: consumer_ready_100_recorded.',
            'properties' => ['source' => 'manual'],
        ]);

        $this->actingAs($user)
            ->getJson(route('consumer-database.workspace.show', $application))
            ->assertOk()
            ->assertJsonPath('data.process.0.stage', 'Ready100')
            ->assertJsonPath('data.process.0.status', 'Dikonfirmasi')
            ->assertJsonPath('data.activity.0.description', $user->name.' - Ready100 dicatat')
            ->assertJsonPath('data.activity.0.source', 'Manual')
            ->assertJsonMissingPath('data.activity.0.properties');
    }

    public function test_workspace_changelog_is_idempotent_and_visible(): void
    {
        [$user] = $this->context();
        $migration = require database_path('migrations/2026_10_01_201730_add_consumer_database_workspace_changelog.php');
        $migration->up();
        $migration->up();

        $title = 'Workspace Database Konsumen Baru Tersedia';
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->assertStringContainsString('data konsumen', DB::table('changelogs')->where('title', $title)->value('description'));
        $this->actingAs($user)->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

    /** @return array{0: User, 1: Branch, 2: LeadMaster} */
    private function context(string $roleSlug = 'admin'): array
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $branch = Branch::query()->create([
            'name' => 'Cabang '.str()->random(5),
            'code' => str()->upper(str()->random(5)),
            'is_active' => true,
        ]);
        $project = LeadMaster::query()->create([
            'branch_id' => $branch->id,
            'project_name' => 'Proyek '.str()->random(5),
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        return [$user, $branch, $project];
    }

    private function application(Branch $branch, LeadMaster $project, string $name, ?string $status = null, ?string $nik = null, ?int $salesUserId = null): ConsumerApplication
    {
        return ConsumerApplication::query()->create([
            'customer_id' => Customer::query()->create(['name' => $name, 'phone' => '081234567890', 'nik_encrypted' => $nik])->id,
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $salesUserId,
            'application_status' => 'active',
            'consumer_status' => $status,
            'current_stage' => 'PSJB',
        ]);
    }
}
