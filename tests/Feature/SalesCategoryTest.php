<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\SalesCategory;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use App\Services\OptimisticLockService;
use App\Services\SalesPocketbookOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SalesCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_category_is_nullable_and_legacy_sales_remains_unclassified(): void
    {
        [$sales] = $this->salesContext();

        $this->assertTrue(Schema::hasColumn('users', 'sales_category'));
        $this->assertNull($sales->sales_category);
        $this->assertSame('sales-pocketbook.index', $sales->landingRouteName());
    }

    #[DataProvider('salesCategories')]
    public function test_each_sales_category_keeps_login_buku_saku_and_agenda_workflow(?SalesCategory $category): void
    {
        [$sales, $project] = $this->salesContext($category);

        $this->post('/login', ['email' => $sales->email, 'password' => 'password'])
            ->assertRedirect(route('sales-pocketbook.index'));
        $this->assertAuthenticatedAs($sales);
        $this->get(route('sales-pocketbook.index'))->assertOk()->assertSee('Buku Saku Saya')->assertSee('Lead Saya');

        $this->post(route('sales-agendas.store'), [
            'scheduled_date' => '2026-10-31',
            'sales_activity_category' => 'Cek Lokasi',
            'title' => 'Agenda Kategori Sales',
        ])->assertRedirect();
        $agenda = ContentItem::query()->where('owner_user_id', $sales->id)->firstOrFail();

        $this->patch(route('sales-agendas.update', $agenda), [
            'activity_result' => 'Selesai ditindaklanjuti',
            'expected_updated_at' => app(OptimisticLockService::class)->token($agenda),
        ])->assertRedirect();
        $this->assertSame('Selesai ditindaklanjuti', $agenda->fresh()->activity_result);
        $this->post('/logout')->assertRedirect('/');
    }

    public function test_authorized_administrator_can_cycle_sales_category_without_changing_access_context(): void
    {
        $administrator = $this->user('superadmin');
        [$sales, $project] = $this->salesContext();
        $original = [
            'role_id' => $sales->role_id,
            'branch_id' => $sales->branch_id,
            'project_id' => $project->id,
            'account_status' => $sales->account_status,
            'permission' => $sales->hasPermission('sales_pocketbook.view_own'),
        ];

        foreach ([SalesCategory::Inhouse->value, SalesCategory::Ojt->value, null] as $category) {
            $sales->refresh();
            $this->actingAs($administrator)->put(route('admin-users.update', $sales), $this->adminUpdatePayload($sales, $project, [
                'sales_category' => $category,
            ]))->assertRedirect(route('admin-users.show', $sales));
        }

        $sales->refresh();
        $this->assertSame($original['role_id'], $sales->role_id);
        $this->assertSame($original['branch_id'], $sales->branch_id);
        $this->assertTrue($sales->assignedProjects()->whereKey($original['project_id'])->exists());
        $this->assertSame($original['account_status'], $sales->account_status);
        $this->assertSame($original['permission'], $sales->hasPermission('sales_pocketbook.view_own'));
        $this->assertNull($sales->sales_category);
    }

    public function test_role_change_clears_sales_category_and_non_sales_user_remains_non_sales(): void
    {
        $administrator = $this->user('superadmin');
        [$sales, $project] = $this->salesContext(SalesCategory::Ojt);
        $staffRole = Role::query()->where('slug', 'staff')->firstOrFail();

        $this->actingAs($administrator)->put(route('admin-users.update', $sales), $this->adminUpdatePayload($sales, $project, [
            'role_id' => $staffRole->id,
            'sales_category' => SalesCategory::Inhouse->value,
        ]))->assertRedirect(route('admin-users.show', $sales));

        $sales->refresh();
        $this->assertSame($staffRole->id, $sales->role_id);
        $this->assertNull($sales->sales_category);
        $this->assertFalse($sales->isSales());
    }

    public function test_authorized_administrator_can_view_and_filter_sales_category(): void
    {
        $administrator = $this->user('superadmin');
        $branch = $this->branch();
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Sales Category Project', 'is_active' => true]);
        $inhouse = $this->sales($branch, $project, SalesCategory::Inhouse, 'Visible Inhouse');
        $ojt = $this->sales($branch, $project, SalesCategory::Ojt, 'Visible OJT');

        $this->actingAs($administrator)->get(route('admin-users.edit', $inhouse))
            ->assertOk()
            ->assertSee('Kategori Sales')
            ->assertSee('INHOUSE', false)
            ->assertSee('OJT', false)
            ->assertSee('id="admin-user-edit-form"', false)
            ->assertSee('form="admin-user-edit-form"', false);
        $this->actingAs($administrator)->get(route('admin-users.show', $inhouse))
            ->assertOk()
            ->assertSee('Inhouse');

        $users = $this->actingAs($administrator)->get(route('admin-users.index', ['sales_category' => 'INHOUSE']))
            ->assertOk()
            ->viewData('users');
        $this->assertContains($inhouse->id, $users->pluck('id')->all());
        $this->assertNotContains($ojt->id, $users->pluck('id')->all());
    }

    public function test_sales_category_can_be_set_when_an_authorized_administrator_creates_sales(): void
    {
        $administrator = $this->user('superadmin');
        $branch = $this->branch();
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Sales Category Project', 'is_active' => true]);
        $coordinator = $this->user('sales_coordinator', $branch, 'Creation Coordinator');
        $salesRole = Role::query()->where('slug', 'sales')->firstOrFail();

        $this->actingAs($administrator)->post(route('admin-users.store'), [
            'name' => 'Created Inhouse Sales',
            'email' => 'created-inhouse-sales@example.test',
            'role_id' => $salesRole->id,
            'sales_category' => SalesCategory::Inhouse->value,
            'branch_id' => $branch->id,
            'branch_ids' => [$branch->id],
            'primary_project_id' => $project->id,
            'assigned_project_ids' => [$project->id],
            'coordinator_user_id' => $coordinator->id,
            'provisioning_mode' => 'direct',
            'temporary_password' => 'Initial123',
            'temporary_password_confirmation' => 'Initial123',
            'submit_action' => 'activate',
        ])->assertRedirect();

        $created = User::query()->where('email', 'created-inhouse-sales@example.test')->firstOrFail();
        $this->assertSame(SalesCategory::Inhouse, $created->sales_category);
        $this->assertTrue($created->isSales());
    }

    public function test_sales_cannot_change_sales_category_through_admin_or_profile_editing(): void
    {
        [$sales, $project] = $this->salesContext();

        $this->actingAs($sales)->put(route('admin-users.update', $sales), $this->adminUpdatePayload($sales, $project, [
            'sales_category' => SalesCategory::Ojt->value,
        ]))->assertForbidden();
        $this->actingAs($sales)->patch(route('profile.update'), [
            'name' => $sales->name,
            'email' => $sales->email,
            'phone' => $sales->phone,
            'sales_category' => SalesCategory::Ojt->value,
        ])->assertRedirect(route('profile.edit'));

        $this->assertNull($sales->fresh()->sales_category);
    }

    public function test_admin_rejects_unknown_sales_category_values(): void
    {
        $administrator = $this->user('superadmin');
        [$sales, $project] = $this->salesContext();

        $this->actingAs($administrator)->put(route('admin-users.update', $sales), $this->adminUpdatePayload($sales, $project, [
            'sales_category' => 'FIELD_TRAINING',
        ]))->assertSessionHasErrors('sales_category');

        $this->assertNull($sales->fresh()->sales_category);
    }

    public function test_sales_coordinator_scope_is_unchanged_by_sales_category(): void
    {
        $branch = $this->branch();
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Sales Category Project', 'is_active' => true]);
        $coordinator = $this->user('sales_coordinator', $branch);
        $legacy = $this->sales($branch, $project, null, 'Legacy Sales');
        $inhouse = $this->sales($branch, $project, SalesCategory::Inhouse, 'Inhouse Sales');
        $ojt = $this->sales($branch, $project, SalesCategory::Ojt, 'OJT Sales');
        SalesCoordinatorSales::insert([
            ['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $legacy->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $inhouse->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $ojt->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($coordinator)->get(route('sales-pocketbook.index'))
            ->assertOk()
            ->assertSee($legacy->name)
            ->assertSee($inhouse->name)
            ->assertSee($ojt->name);
    }

    public static function salesCategories(): array
    {
        return [
            'legacy' => [null],
            'inhouse' => [SalesCategory::Inhouse],
            'ojt' => [SalesCategory::Ojt],
        ];
    }

    private function salesContext(?SalesCategory $category = null): array
    {
        $branch = $this->branch();
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Sales Category Project', 'is_active' => true]);
        $sales = $this->sales($branch, $project, $category);
        app(SalesPocketbookOnboardingService::class)->skip($sales);

        return [$sales, $project];
    }

    private function sales(Branch $branch, LeadMaster $project, ?SalesCategory $category, string $name = 'Sales User'): User
    {
        $sales = $this->user('sales', $branch, $name);
        $sales->update(['sales_category' => $category]);
        $sales->assignedProjects()->attach($project, ['is_primary' => true, 'is_active' => true]);

        return $sales->refresh();
    }

    private function user(string $roleSlug, ?Branch $branch = null, string $name = 'Test User'): User
    {
        return User::factory()->create([
            'name' => $name,
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'branch_id' => $branch?->id,
            'account_status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);
    }

    private function branch(): Branch
    {
        return Branch::create(['name' => 'Sales Category Branch', 'code' => 'SCB', 'is_active' => true]);
    }

    private function adminUpdatePayload(User $user, LeadMaster $project, array $overrides = []): array
    {
        return array_merge([
            'expected_updated_at' => app(OptimisticLockService::class)->token($user),
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role_id' => $user->role_id,
            'branch_id' => $user->branch_id,
            'branch_ids' => [$user->branch_id],
            'primary_project_id' => $project->id,
            'assigned_project_ids' => [$project->id],
        ], $overrides);
    }
}
