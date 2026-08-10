<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MandatoryPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_flagged_login_redirects_immediately_to_mandatory_password_change(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('password.change'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_flagged_user_can_only_open_mandatory_password_change_and_logout(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('password.change'));
        $this->actingAs($user)->get(route('sales-pocketbook.index'))->assertRedirect(route('password.change'));
        $this->actingAs($user)->get(route('profile.edit'))->assertRedirect(route('password.change'));
        $this->actingAs($user)->put(route('password.update'), [])->assertRedirect(route('password.change'));
        $this->actingAs($user)->get(route('password.change'))
            ->assertOk()
            ->assertSee('Ganti Password')
            ->assertSee('Anda menggunakan password sementara. Buat password baru untuk melanjutkan.')
            ->assertDontSee('Password Saat Ini');

        $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_null_password_changed_timestamp_without_flag_allows_normal_access(): void
    {
        $user = User::factory()->create([
            'must_change_password' => false,
            'password_changed_at' => null,
        ]);

        $this->actingAs($user)->get(route('password.change'))
            ->assertRedirect(route($user->landingRouteName()));
        $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    }

    public function test_mandatory_password_change_clears_flag_and_redirects_each_role_to_its_landing_page(): void
    {
        foreach (['sales', 'sales_coordinator', 'supervisor'] as $slug) {
            $role = Role::query()->where('slug', $slug)->firstOrFail();
            $user = User::factory()->create([
                'role_id' => $role->id,
                'must_change_password' => true,
                'password_changed_at' => null,
            ]);
            $password = "new-password-{$slug}";

            $this->actingAs($user)->put(route('password.change.update'), [
                'password' => $password,
                'password_confirmation' => $password,
            ])->assertRedirect(route('sales-pocketbook.index'));

            $user->refresh();
            $this->assertFalse($user->must_change_password);
            $this->assertNotNull($user->password_changed_at);
            $this->assertTrue(Hash::check($password, $user->password));
        }
    }
}
