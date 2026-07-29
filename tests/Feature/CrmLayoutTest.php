<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Changelog;
use App\Models\Role;
use App\Models\User;
use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CrmLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_grouped_shell_renders_active_navigation_and_global_utilities(): void
    {
        $response = $this->actingAs($this->user('superadmin'))->get(route('dashboard'))->assertOk();

        $response->assertSee('id="crm-sidebar"', false)
            ->assertSee('id="crm-main"', false)
            ->assertSee('aria-label="Area kerja OASIS"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('Dashboard')
            ->assertSee('Aktivitas')
            ->assertSee('Sales')
            ->assertSee('Operasional')
            ->assertSee('Keuangan')
            ->assertSee('Laporan')
            ->assertSee('Administrasi')
            ->assertSee('crmNotifications', false)
            ->assertSee('Tandai semua dibaca')
            ->assertSee('crmToasts', false)
            ->assertSee('id="oasis-conflict-dialog"', false)
            ->assertSee('action="'.route('logout').'"', false)
            ->assertSee('method="POST"', false);
    }

    public function test_sales_shell_does_not_render_unauthorized_groups_or_destinations(): void
    {
        $response = $this->actingAs($this->user('sales'))->get(route('sales-pocketbook.index'))->assertOk();

        $response->assertSee('Aktivitas')
            ->assertSee('Buku Saku Sales')
            ->assertDontSee('Dashboard', false)
            ->assertDontSee(route('database.index'), false)
            ->assertDontSee(route('expenses.index'), false)
            ->assertDontSee(route('dana-talangan.index'), false)
            ->assertDontSee(route('admin-users.index'), false)
            ->assertDontSee('Administrasi')
            ->assertSee(route('profile.edit'), false)
            ->assertSee(route('logout'), false);
    }

    public function test_mobile_drawer_and_collapsed_desktop_controls_have_accessible_contracts(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/crm.blade.php'));
        $topbar = file_get_contents(resource_path('views/components/crm/topbar.blade.php'));
        $shell = file_get_contents(resource_path('js/crm-shell.js'));

        $this->assertStringContainsString('aria-label="Navigasi utama"', $layout);
        $this->assertStringContainsString(':aria-modal=', $layout);
        $this->assertStringContainsString(':inert="mobileViewport && !sidebarOpen"', $layout);
        $this->assertStringContainsString('aria-labelledby="crm-drawer-title"', $layout);
        $this->assertStringContainsString('x-ref="drawerClose"', $layout);
        $this->assertStringContainsString('@keydown="handleDrawerKeydown($event)"', $layout);
        $this->assertStringContainsString('@click="closeMobileNavigation(false)"', $layout);
        $this->assertStringContainsString('aria-label="Buka navigasi utama"', $topbar);
        $this->assertStringContainsString('aria-controls="crm-sidebar"', $topbar);
        $this->assertStringContainsString("'oasis.sidebar.collapsed'", $shell);
        $this->assertStringContainsString("'oasis.sidebar.groups'", $shell);
        $this->assertStringContainsString("document.body.style.overflow = 'hidden'", $shell);
        $this->assertStringContainsString("event.key === 'Escape'", $shell);
        $this->assertStringContainsString("event.key !== 'Tab'", $shell);
        $this->assertStringContainsString('this.navigationTrigger?.focus()', $shell);
        $this->assertStringContainsString('this.$refs.desktopSidebarToggle?.focus()', $shell);
        $this->assertStringContainsString('function readStorage', $shell);
    }

    public function test_dashboard_uses_named_shell_sections_and_existing_pages_remain_compatible(): void
    {
        $response = $this->actingAs($this->user('manager'))->get(route('dashboard'))->assertOk();

        $response->assertSee('id="crm-main"', false)
            ->assertSee('class="crm-page-heading"', false)
            ->assertSee('class="crm-page-title"', false)
            ->assertSee('Selamat');

        $layout = file_get_contents(resource_path('views/layouts/crm.blade.php'));
        foreach (['breadcrumbs', 'page-title', 'page-description', 'page-actions', 'page-tabs', 'toolbar', 'content'] as $section) {
            $this->assertStringContainsString($section, $layout);
        }
    }

    public function test_representative_crm_pages_render_inside_the_new_shell(): void
    {
        $this->app->instance(GoogleSheetsApiService::class, Mockery::mock(GoogleSheetsApiService::class));
        $user = $this->user('superadmin');

        foreach (['dashboard', 'content-calendar.index', 'sales-pocketbook.index', 'database.index', 'konsumen-progress.index', 'dana-talangan.index', 'expenses.index', 'admin-users.index', 'admin-users.import'] as $routeName) {
            $this->actingAs($user)->get(route($routeName))->assertOk()->assertSee('id="crm-main"', false);
        }
    }

    public function test_navigation_changelog_is_idempotent_and_visible(): void
    {
        $title = 'Navigasi area kerja lebih ringkas';
        $user = $this->user('superadmin');

        $this->assertSame(1, Changelog::query()->whereNull('version')->where('title', $title)->count());
        $this->actingAs($user)->get(route('changelogs.index'))->assertOk()->assertSee($title);
    }

    private function user(string $role): User
    {
        $branch = Branch::firstOrCreate(['code' => 'SLO'], ['name' => 'Solo', 'is_active' => true]);

        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
    }
}
