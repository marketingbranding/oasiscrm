<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\NavigationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DesignSystemAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_has_full_lifecycle_and_primary_superadmin_gate(): void
    {
        $route = Route::getRoutes()->getByName('admin.design-system');

        $this->assertNotNull($route);
        $this->assertSame('admin/design-system', $route->uri());
        $this->assertContains('GET', $route->methods());
        foreach (['web', 'auth', 'active', 'verified', 'password.changed', 'sales.access', 'can:viewDesignSystem'] as $middleware) {
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
    }

    public function test_primary_superadmin_can_view_static_showcase(): void
    {
        $response = $this->actingAs($this->user('superadmin'))->get(route('admin.design-system'));

        $response->assertOk()
            ->assertSee('OASIS Design System 2.0')
            ->assertSee('data-testid="design-system-showcase"', false)
            ->assertSee('Data Contoh Alpha')
            ->assertSee('id="crm-main"', false)
            ->assertDontSee('service-account')
            ->assertDontSee('APP_KEY');
    }

    public function test_non_superadmin_roles_and_primary_sales_are_denied(): void
    {
        foreach (['pusat', 'branch_manager', 'manager', 'admin', 'staff', 'sales'] as $role) {
            $this->actingAs($this->user($role))->get(route('admin.design-system'))->assertForbidden();
        }
    }

    public function test_supplemental_superadmin_does_not_escalate_gate_or_navigation(): void
    {
        $manager = $this->user('manager');
        $manager->roles()->attach(Role::query()->where('slug', 'superadmin')->firstOrFail());
        $manager = $manager->fresh(['role.permissions']);

        $this->assertTrue($manager->hasRole('superadmin'));
        $this->assertFalse($manager->isSuperadmin());
        $this->assertFalse(Gate::forUser($manager)->allows('viewDesignSystem'));
        $this->actingAs($manager)->get(route('admin.design-system'))->assertForbidden();

        $labels = collect(app(NavigationService::class)->forUser($manager))
            ->flatMap(fn (array $group) => array_column($group['children'], 'label'));
        $this->assertNotContains('Design System', $labels);
    }

    public function test_guest_unverified_and_forced_password_change_cannot_view_showcase(): void
    {
        $this->get(route('admin.design-system'))->assertRedirect(route('login'));

        $unverified = $this->user('superadmin', ['email_verified_at' => null]);
        $this->actingAs($unverified)->get(route('admin.design-system'))->assertRedirect(route('verification.notice'));

        $forced = $this->user('superadmin', ['password_changed_at' => null]);
        $this->actingAs($forced)->get(route('admin.design-system'))->assertRedirect(route('password.change'));
    }

    private function user(string $role, array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'password_changed_at' => now(),
        ], $overrides));
    }
}
