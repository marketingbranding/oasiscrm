<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Changelog;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OptimisticLockService;
use App\Services\OrganizationScopeService;
use App\Services\WorkspaceAccessService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class WorkPlannerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const PUSAT_PERMISSIONS = [
        'work_planner.create',
        'work_planner.update',
        'work_planner.assign',
        'work_planner.export',
        'work_planner.view_all',
        'work_planner.manage_all',
    ];

    public function test_primary_pusat_can_open_create_form_and_manage_every_active_branch(): void
    {
        $primary = $this->branch('Pusat', 'PST');
        $operational = $this->branch('Magelang', 'MGL');
        $pusat = $this->user('pusat', $primary);

        foreach (self::PUSAT_PERMISSIONS as $permission) {
            $this->assertTrue($pusat->hasPermission($permission), "Pusat lacks {$permission}.");
        }
        $this->assertContains($operational->id, app(OrganizationScopeService::class)->branchIds($pusat, 'work_planner', 'manage'));
        $this->assertTrue(app(WorkspaceAccessService::class)->canManageBranch($pusat, $operational, 'work_planner'));

        $this->actingAs($pusat)->get(route('content-calendar.create'))
            ->assertOk()
            ->assertViewHas('branches', fn ($branches) => $branches->contains('id', $operational->id));
    }

    public function test_primary_pusat_can_create_and_assign_task_in_another_active_branch(): void
    {
        $primary = $this->branch('Pusat', 'PST');
        $operational = $this->branch('Solo', 'SLO');
        $pusat = $this->user('pusat', $primary);
        $assignee = $this->user('staff', $operational);

        $this->actingAs($pusat)->post(route('content-calendar.store'), $this->taskPayload($operational, [
            'title' => 'Task lintas cabang',
            'assigned_user_ids' => [$assignee->id],
        ]))->assertRedirect();

        $item = ContentItem::query()->where('title', 'Task lintas cabang')->firstOrFail();
        $this->assertSame($operational->id, $item->branch_id);
        $this->assertTrue($item->assignees()->whereKey($assignee->id)->exists());
    }

    public function test_manage_all_controls_active_branch_resolution_when_view_mapping_is_stale(): void
    {
        $primary = $this->branch('Pusat', 'PST');
        $operational = $this->branch('Yogyakarta', 'YGY');
        $pusatRole = Role::query()->where('slug', 'pusat')->firstOrFail();
        $pusatRole->permissions()->detach(Permission::query()->where('slug', 'work_planner.view_all')->firstOrFail());
        $pusat = $this->user('pusat', $primary);
        $this->assertFalse($pusat->hasPermission('work_planner.view_all'));
        $this->assertTrue($pusat->hasPermission('work_planner.manage_all'));
        $this->assertTrue(app(WorkspaceAccessService::class)->canManageBranch($pusat, $operational, 'work_planner'));
        $this->actingAs($pusat)->get(route('content-calendar.create'))->assertOk();

        $this->actingAs($pusat)->post(route('content-calendar.store'), $this->taskPayload($operational, [
            'title' => 'Task dari manage all',
        ]))->assertRedirect();
        $this->assertDatabaseHas('content_items', ['title' => 'Task dari manage all', 'branch_id' => $operational->id]);
    }

    public function test_other_module_view_all_does_not_grant_global_work_planner_management(): void
    {
        $primary = $this->branch('Cabang Utama', 'UTM');
        $foreign = $this->branch('Cabang Asing', 'ASG');
        $role = Role::query()->create([
            'name' => 'Planner Terbatas',
            'slug' => 'planner_terbatas',
            'is_superadmin' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', [
            'database.view_all',
            'work_planner.view_own',
            'work_planner.create',
            'work_planner.update',
        ])->pluck('id'));
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $primary->id,
            'password_changed_at' => now(),
        ]);

        $this->assertTrue($user->canViewAllBranches());
        $this->assertFalse($user->hasPermission('work_planner.manage_all'));
        $this->assertFalse(app(WorkspaceAccessService::class)->canManageBranch($user, $foreign, 'work_planner'));
        $this->actingAs($user)->get(route('content-calendar.create'))
            ->assertOk()
            ->assertViewHas('branches', fn ($branches) => ! $branches->contains('id', $foreign->id));
        $this->actingAs($user)->post(route('content-calendar.store'), $this->taskPayload($foreign))
            ->assertSessionHasErrors('branch_id');
    }

    public function test_primary_pusat_can_edit_and_update_item_in_another_active_branch(): void
    {
        $primary = $this->branch('Pusat', 'PST');
        $operational = $this->branch('Semarang', 'SMG');
        $pusat = $this->user('pusat', $primary);
        $creator = $this->user('staff', $operational);
        $item = $this->item($operational, $creator);

        $this->actingAs($pusat)->get(route('content-calendar.edit', $item))->assertOk();
        $this->actingAs($pusat)->put(route('content-calendar.update', $item), $this->taskPayload($operational, [
            'title' => 'Task diperbarui Pusat',
            'expected_updated_at' => $item->updated_at->copy()->utc()->format('Y-m-d H:i:s'),
        ]))->assertRedirect();

        $this->assertSame('Task diperbarui Pusat', $item->fresh()->title);
    }

    public function test_supplemental_pusat_does_not_escalate_sales_and_sales_cannot_assign_another_user(): void
    {
        $branch = $this->branch('Cabang Sales', 'SAL');
        $foreignBranch = $this->branch('Cabang Asing', 'ASG');
        $sales = $this->user('sales', $branch);
        $sales->roles()->attach(Role::query()->where('slug', 'pusat')->firstOrFail());
        $assignee = $this->user('staff', $branch);

        $this->assertTrue($sales->hasRole('pusat'));
        $this->assertTrue($sales->hasPrimaryRole('sales'));
        $this->assertFalse($sales->hasPermission('work_planner.assign'));
        $this->assertFalse($sales->hasPermission('work_planner.manage_all'));

        $this->actingAs($sales)->post(route('content-calendar.store'), $this->taskPayload($branch, [
            'title' => 'Task Sales sendiri',
        ]))->assertRedirect();
        $this->assertDatabaseHas('content_items', ['title' => 'Task Sales sendiri', 'created_by' => $sales->id]);

        $this->actingAs($sales)->post(route('content-calendar.store'), $this->taskPayload($branch, [
            'title' => 'Task Sales untuk orang lain',
            'assigned_user_ids' => [$assignee->id],
        ]))->assertSessionHasErrors(['assigned_user_ids' => 'Anda tidak memiliki izin menugaskan task kepada pengguna lain.']);
        $this->assertDatabaseMissing('content_items', ['title' => 'Task Sales untuk orang lain']);

        $this->actingAs($sales)->post(route('content-calendar.store'), $this->taskPayload($foreignBranch, [
            'title' => 'Task Sales cabang asing',
        ]))->assertSessionHasErrors('branch_id');
        $this->assertDatabaseMissing('content_items', ['title' => 'Task Sales cabang asing']);
    }

    public function test_branch_scoped_user_cannot_create_in_unauthorized_branch(): void
    {
        $primary = $this->branch('Cabang Utama', 'UTM');
        $unauthorized = $this->branch('Cabang Lain', 'LAIN');
        $user = $this->user('admin', $primary);

        $this->actingAs($user)->post(route('content-calendar.store'), $this->taskPayload($unauthorized))
            ->assertSessionHasErrors(['branch_id' => 'Cabang yang dipilih tidak dapat diedit oleh akun ini.']);
    }

    public function test_branch_user_without_can_edit_cannot_create_item(): void
    {
        $branch = $this->branch('Read Only', 'READ');
        $user = $this->user('admin', $branch);
        $user->branches()->updateExistingPivot($branch->id, ['can_view' => true, 'can_edit' => false]);

        $this->actingAs($user)->post(route('content-calendar.store'), $this->taskPayload($branch))
            ->assertSessionHasErrors(['branch_id' => 'Cabang yang dipilih tidak dapat diedit oleh akun ini.']);
    }

    public function test_primary_pusat_cannot_create_in_inactive_or_invalid_branch(): void
    {
        $primary = $this->branch('Pusat', 'PST');
        $inactive = $this->branch('Nonaktif', 'OFF', false);
        $pusat = $this->user('pusat', $primary);

        $this->actingAs($pusat)->post(route('content-calendar.store'), $this->taskPayload($inactive))
            ->assertSessionHasErrors(['branch_id' => 'Cabang yang dipilih tidak dapat diedit oleh akun ini.']);

        $this->actingAs($pusat)->post(route('content-calendar.store'), $this->taskPayload($primary, ['branch_id' => 999999]))
            ->assertSessionHasErrors('branch_id');
    }

    public function test_assigned_scope_blocks_same_branch_other_project_planner_items_everywhere(): void
    {
        $branch = $this->branch('Assigned Planner Branch', 'APL');
        $assignedProject = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Assigned Planner Project', 'is_active' => true]);
        $otherProject = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Other Planner Project', 'is_active' => true]);
        $user = $this->user('supervisor', $branch);
        $creator = $this->user('staff', $branch);
        DB::table('project_user')->insert([
            'user_id' => $user->id, 'project_id' => $assignedProject->id, 'is_primary' => true,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $allowed = $this->item($branch, $creator);
        $allowed->update(['title' => 'Planner Assigned Visible', 'item_type' => 'agenda', 'status' => 'planned', 'sales_project_id' => $assignedProject->id]);
        $blocked = $this->item($branch, $creator);
        $blocked->update(['title' => 'Planner Other Hidden', 'item_type' => 'agenda', 'status' => 'planned', 'sales_project_id' => $otherProject->id]);
        $freeText = $this->item($branch, $creator);
        $freeText->update(['title' => 'Planner Free Text Hidden', 'project_name' => $otherProject->project_name]);
        $own = $this->item($branch, $user);
        $own->update(['title' => 'Planner Own Visible', 'project_name' => $otherProject->project_name]);

        $this->actingAs($user)->getJson(route('content-calendar.detail', $blocked))->assertForbidden();
        $response = $this->actingAs($user)->get(route('content-calendar.export'))->assertOk();
        $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
        $titles = array_column($sheet->rangeToArray('C2:C'.$sheet->getHighestRow()), 0);
        $this->assertContains($allowed->title, $titles);
        $this->assertContains($own->title, $titles);
        $this->assertNotContains($blocked->title, $titles);
        $this->actingAs($user)->put(route('content-calendar.update', $blocked), $this->taskPayload($branch, [
            'title' => 'Blocked update',
            'expected_updated_at' => app(OptimisticLockService::class)->token($blocked),
        ]))->assertForbidden();
    }

    public function test_coordinator_and_manager_update_reachable_team_items_without_manage_scopes(): void
    {
        $branch = $this->branch('Tim Planner', 'TPL');
        $foreignBranch = $this->branch('Cabang Asing Planner', 'CAP');
        $creator = $this->user('staff', $branch);
        $manager = $this->user('manager', $branch);
        $coordinator = $this->user('sales_coordinator', $branch);
        $item = $this->item($branch, $creator);

        $this->assertFalse($manager->hasPermission('work_planner.manage_all'));
        $this->assertFalse($manager->hasPermission('work_planner.manage_team'));
        $this->assertFalse($coordinator->hasPermission('work_planner.manage_team'));

        foreach ([$manager, $coordinator] as $actor) {
            $this->actingAs($actor)->put(route('content-calendar.update', $item), $this->taskPayload($branch, [
                'title' => "Diperbarui aktor {$actor->id}",
                'expected_updated_at' => app(OptimisticLockService::class)->token($item->fresh()),
            ]))->assertRedirect();
            $this->assertDatabaseHas('content_items', ['title' => "Diperbarui aktor {$actor->id}"]);
        }

        $foreignItem = $this->item($foreignBranch, $this->user('staff', $foreignBranch));
        $this->actingAs($manager)->put(route('content-calendar.update', $foreignItem), $this->taskPayload($foreignBranch, [
            'title' => 'Diperbarui cabang asing',
            'expected_updated_at' => app(OptimisticLockService::class)->token($foreignItem),
        ]))->assertForbidden();
        $this->assertDatabaseMissing('content_items', ['title' => 'Diperbarui cabang asing']);
    }

    public function test_project_scoped_user_cannot_submit_unassigned_planner_project_but_keeps_historical_value(): void
    {
        $branch = $this->branch('Proyek Planner', 'PRP');
        $assignedProject = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Assigned Planner Proyek', 'is_active' => true]);
        $otherProject = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Other Planner Proyek', 'is_active' => true]);
        $user = $this->user('supervisor', $branch);
        DB::table('project_user')->insert([
            'user_id' => $user->id, 'project_id' => $assignedProject->id, 'is_primary' => true,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = $this->item($branch, $user);
        $item->update(['project_name' => $otherProject->project_name]);

        $this->actingAs($user)->post(route('content-calendar.store'), $this->taskPayload($branch, [
            'project_name' => $otherProject->project_name,
        ]))->assertSessionHasErrors('project_name');

        $this->actingAs($user)->put(route('content-calendar.update', $item), $this->taskPayload($branch, [
            'project_name' => $otherProject->project_name,
            'expected_updated_at' => app(OptimisticLockService::class)->token($item),
        ]))->assertRedirect();
        $this->assertSame($otherProject->project_name, $item->fresh()->project_name);
    }

    public function test_corrective_migration_restores_only_required_pusat_work_planner_mappings(): void
    {
        $pusat = Role::query()->where('slug', 'pusat')->firstOrFail();
        $permissionIds = Permission::query()->whereIn('slug', self::PUSAT_PERMISSIONS)->pluck('id');
        $custom = Permission::query()->where('slug', 'comments.moderate')->firstOrFail();
        $pusat->permissions()->syncWithoutDetaching([$custom->id]);
        DB::table('role_permission')->where('role_id', $pusat->id)->whereIn('permission_id', $permissionIds)->delete();
        $before = $pusat->permissions()->pluck('slug')->all();
        $admin = Role::query()->where('slug', 'admin')->firstOrFail();
        $adminBefore = $admin->permissions()->pluck('slug')->sort()->values()->all();

        $migration = require database_path('migrations/2026_07_30_000002_sync_pusat_work_planner_permissions.php');
        $migration->up();
        $migration->up();

        $deployed = $pusat->permissions()->pluck('slug')->all();
        $this->assertEqualsCanonicalizing(self::PUSAT_PERMISSIONS, array_values(array_diff($deployed, $before)));
        $this->assertEqualsCanonicalizing(self::PUSAT_PERMISSIONS, array_values(array_intersect(self::PUSAT_PERMISSIONS, $deployed)));
        $this->assertContains($custom->slug, $deployed);
        $this->assertEmpty(array_diff(self::PUSAT_PERMISSIONS, PermissionCatalog::rolePermissions()['pusat']));
        $this->assertSame($adminBefore, $admin->permissions()->pluck('slug')->sort()->values()->all());

        $migration->down();
        $this->assertEqualsCanonicalizing($deployed, $pusat->permissions()->pluck('slug')->all());
    }

    public function test_work_planner_access_fix_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Akses Work Planner Tim Pusat diperbaiki';
        $migration = require database_path('migrations/2026_07_30_000003_add_pusat_work_planner_access_fix_changelog.php');
        $migration->up();
        $migration->up();
        $superadmin = $this->user('superadmin', $this->branch('Superadmin', 'SUP'));

        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($superadmin)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    private function branch(string $name, string $code, bool $active = true): Branch
    {
        return Branch::query()->create(['name' => $name, 'code' => $code, 'is_active' => $active]);
    }

    private function user(string $role, Branch $branch): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->firstOrFail()->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
    }

    private function item(Branch $branch, User $creator): ContentItem
    {
        return ContentItem::query()->create([
            'branch_id' => $branch->id,
            'item_type' => 'task',
            'visibility' => 'team',
            'title' => 'Task awal',
            'scheduled_date' => today(),
            'deadline_date' => today(),
            'priority' => 'medium',
            'status' => 'todo',
            'created_by' => $creator->id,
        ]);
    }

    private function taskPayload(Branch $branch, array $overrides = []): array
    {
        return array_merge([
            'item_type' => 'task',
            'visibility' => 'team',
            'title' => 'Task otorisasi',
            'task_detail' => 'Pemeriksaan akses Work Planner',
            'branch_id' => $branch->id,
            'deadline_date' => today()->toDateString(),
            'priority' => 'medium',
            'status' => 'todo',
            'assigned_user_ids' => [],
            'pic_names' => [],
            'return_view' => 'tasks',
        ], $overrides);
    }
}
