<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Changelog;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_admin_dashboard_renders_with_effective_branch_id(): void
    {
        $role = Role::firstOrCreate([
            'slug' => 'admin',
        ], [
            'name' => 'Admin',
            'is_superadmin' => false,
        ]);
        $branch = Branch::create([
            'name' => 'Test Branch',
            'code' => 'TEST',
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response
            ->assertOk()
            ->assertViewHas('selectedBranchId', $branch->id)
            ->assertSee('name="branch_id" value="'.$branch->id.'"', false);
    }

    public function test_dashboard_renders_command_center_sections_in_operational_order(): void
    {
        [$branch, $user] = $this->dashboardUser('admin');

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $response->assertSee('Selamat')
            ->assertSee($branch->name)
            ->assertSee('Aksi Cepat')
            ->assertSee('aria-controls="dashboard-quick-actions-menu"', false)
            ->assertSee('role="menu"', false)
            ->assertSee('crm-status-badge', false)
            ->assertSeeInOrder([
                'Ringkasan Area Kerja',
                'Attention Center',
                'Pekerjaan Hari Ini',
                'Aktivitas Operasional Terbaru',
                'Konsumen Progress',
                'Status Data &amp; Sistem',
            ], false)
            ->assertSee('Semua pekerjaan sudah terkendali')
            ->assertSee('Tidak ada pekerjaan terjadwal');
    }

    public function test_attention_and_activity_reuse_existing_dashboard_payload(): void
    {
        [$branch, $user] = $this->dashboardUser('admin');
        ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'task',
            'visibility' => 'branch',
            'title' => 'Hubungi konsumen prioritas',
            'scheduled_date' => today()->subDay(),
            'deadline_date' => today()->subDay(),
            'status' => 'todo',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $response->assertSee('Task overdue: Hubungi konsumen prioritas')
            ->assertSee('Hubungi konsumen prioritas')
            ->assertSee('Buka modul')
            ->assertSee('aria-label="Aktivitas operasional terbaru"', false);
    }

    public function test_dashboard_hides_module_surfaces_without_primary_role_permissions(): void
    {
        foreach (['sales_coordinator', 'staff'] as $role) {
            [, $user] = $this->dashboardUser($role);

            $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

            $response->assertDontSee('id="dashboard-kpis"', false)
                ->assertDontSee('id="dashboard-analytics"', false)
                ->assertDontSee('data-testid="dashboard-database-sync"', false)
                ->assertDontSee('name="project_name"', false);
        }
    }

    public function test_lead_quick_action_requires_manage_scope(): void
    {
        $role = Role::create(['slug' => 'dashboard_read_only', 'name' => 'Dashboard Read Only', 'is_active' => true]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', [
            'database.view', 'database.edit', 'database.view_assigned',
        ])->pluck('id'));
        $branch = Branch::create(['name' => 'Read Only', 'code' => 'RO', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertDontSee(route('database.index', ['sheet' => 'lead', 'add' => 1]), false);
    }

    public function test_global_dashboard_requires_branch_selection_before_project_filtering(): void
    {
        [$branch, $superadmin] = $this->dashboardUser('superadmin', false);
        $other = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Sama', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $other->id, 'project_name' => 'Proyek Sama', 'is_active' => true]);

        $response = $this->actingAs($superadmin)->get(route('dashboard'))->assertOk();

        $response->assertSee('name="branch_id"', false)
            ->assertDontSee('name="project_name"', false);

        $this->actingAs($superadmin)->get(route('dashboard', ['project_name' => 'Proyek Sama']))->assertOk()
            ->assertSee('id="dashboard-scope-warning"', false)
            ->assertDontSee('id="dashboard-kpis"', false);
    }

    public function test_ambiguous_project_name_is_not_offered_as_dashboard_scope(): void
    {
        [$branch, $superadmin] = $this->dashboardUser('superadmin');
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Nama Duplikat', 'is_active' => true]);
        LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Nama Duplikat', 'is_active' => true]);

        $this->actingAs($superadmin)->get(route('dashboard', ['branch_id' => $branch->id]))->assertOk()
            ->assertDontSee('value="Nama Duplikat"', false);
    }

    public function test_assigned_sales_scope_monitoring_action_does_not_forward_generic_dashboard_branch(): void
    {
        [$branch, $coordinator] = $this->dashboardUser('sales_coordinator');

        $response = $this->actingAs($coordinator)->get(route('dashboard'))->assertOk();

        $response->assertSee(route('sales-pocketbook.index', ['tab' => 'report']), false)
            ->assertDontSee(route('sales-pocketbook.index', ['tab' => 'report', 'branch_id' => $branch->id]), false);
    }

    public function test_superadmin_quick_actions_do_not_silently_drop_authorized_destinations(): void
    {
        [, $superadmin] = $this->dashboardUser('superadmin');

        $this->actingAs($superadmin)->get(route('dashboard'))->assertOk()
            ->assertSee(route('content-calendar.create', ['type' => 'task']), false)
            ->assertSee(route('projects.create'), false);
    }

    public function test_explicit_unauthorized_dashboard_branch_is_forbidden(): void
    {
        [, $user] = $this->dashboardUser('admin');
        $unauthorized = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);

        $this->actingAs($user)->get(route('dashboard', ['branch_id' => $unauthorized->id]))->assertForbidden();
    }

    public function test_superadmin_global_dashboard_does_not_render_branch_sync_action(): void
    {
        [, $superadmin] = $this->dashboardUser('superadmin', false);

        $response = $this->actingAs($superadmin)->get(route('dashboard'))->assertOk();

        $response->assertViewHas('branch', null)
            ->assertViewHas('selectedBranchId', null)
            ->assertSee('Semua Cabang')
            ->assertDontSee('data-testid="dashboard-database-sync"', false)
            ->assertSee(route('admin.system-health'), false);
    }

    public function test_dashboard_command_center_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Dashboard menjadi pusat kerja harian';
        [, $superadmin] = $this->dashboardUser('superadmin', false);

        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    private function dashboardUser(string $roleSlug, bool $withBranch = true): array
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $branch = Branch::firstOrCreate(['code' => 'TEST'], ['name' => 'Test Branch', 'is_active' => true]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $withBranch ? $branch->id : null,
            'password_changed_at' => now(),
        ]);

        return [$branch, $user];
    }
}
