<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_sales_login_ignores_unrelated_intended_url_and_redirects_to_pocketbook(): void
    {
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales', 'is_superadmin' => false]);
        $sales = User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);

        $response = $this->withSession(['url.intended' => route('database.index')])->post('/login', [
            'email' => $sales->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($sales);
        $response->assertRedirect(route('sales-pocketbook.index'));
    }

    public function test_sales_forced_password_change_redirects_to_pocketbook(): void
    {
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales', 'is_superadmin' => false]);
        $sales = User::factory()->create(['role_id' => $role->id, 'password_changed_at' => null]);

        $this->actingAs($sales)->put(route('password.change.update'), [
            'current_password' => 'password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('sales-pocketbook.index'));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }
}
