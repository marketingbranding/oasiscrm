<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class RememberLoginTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'account_status' => AccountStatus::Active,
            'is_active' => true,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ], $attributes));
    }

    private function recallerName(): string
    {
        return Auth::guard('web')->getRecallerName();
    }

    private function remember(User $user): string
    {
        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $response->assertCookie($this->recallerName());
        $this->assertNotEmpty($user->fresh()->remember_token);

        return $response->getCookie($this->recallerName())->getValue();
    }

    private function recallerRequest(string $cookie, string $uri = '/profile')
    {
        $this->app['session']->flush();
        Auth::guard('web')->forgetUser();

        return $this->withCookie($this->recallerName(), $cookie)->get($uri);
    }

    public function test_remember_login_sets_recaller_and_token_while_normal_login_does_not(): void
    {
        $remembered = $this->activeUser(['remember_token' => null]);
        $cookie = $this->remember($remembered);
        $this->assertNotEmpty($cookie);

        $this->post('/logout');
        $normal = $this->activeUser(['remember_token' => null]);
        $response = $this->post('/login', [
            'email' => $normal->email,
            'password' => 'password',
        ]);

        $response->assertCookieMissing($this->recallerName());
        $this->assertNull($normal->fresh()->remember_token);
    }

    public function test_recaller_only_request_restores_active_user(): void
    {
        $user = $this->activeUser();
        $cookie = $this->remember($user);

        $this->recallerRequest($cookie)->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_logout_expires_recaller_rotates_token_and_replay_stays_guest(): void
    {
        $user = $this->activeUser();
        $cookie = $this->remember($user);
        $token = $user->fresh()->remember_token;

        $logout = $this->withCookie($this->recallerName(), $cookie)->post('/logout');

        $logout->assertCookieExpired($this->recallerName());
        $this->assertNotSame($token, $user->fresh()->remember_token);
        $this->recallerRequest($cookie)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_nonactive_statuses_cannot_restore_from_session_id_or_valid_stale_recaller(): void
    {
        foreach (AccountStatus::cases() as $status) {
            if ($status === AccountStatus::Active) {
                continue;
            }

            $user = $this->activeUser();
            $cookie = $this->remember($user);
            $token = $user->fresh()->remember_token;
            $user->forceFill(['account_status' => $status, 'is_active' => false])->save();
            $this->assertSame($token, $user->fresh()->remember_token);

            $this->app['session']->flush();
            Auth::guard('web')->forgetUser();
            $sessionKey = Auth::guard('web')->getName();
            $this->withSession([$sessionKey => $user->id])->get('/profile')->assertRedirect(route('login'));
            $this->assertGuest();

            $this->recallerRequest($cookie)->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_supported_suspension_and_deactivation_rotate_token_and_reject_old_cookie(): void
    {
        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $actor = $this->activeUser(['role_id' => $role->id]);

        foreach (['suspend', 'deactivate'] as $transition) {
            $user = $this->activeUser(['role_id' => $role->id]);
            $cookie = $this->remember($user);
            $token = $user->fresh()->remember_token;

            app(UserAccountService::class)->{$transition}($user, $actor);

            $this->assertNotSame($token, $user->fresh()->remember_token);
            $this->recallerRequest($cookie)->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    public function test_remembered_forced_password_user_redirects_to_password_change(): void
    {
        $user = $this->activeUser([
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);
        $cookie = $this->remember($user);

        $this->recallerRequest($cookie, '/dashboard')->assertRedirect(route('password.change'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_mandatory_password_update_invalidates_old_recaller_but_keeps_current_session(): void
    {
        $user = $this->activeUser([
            'must_change_password' => true,
            'password_changed_at' => null,
        ]);
        $cookie = $this->remember($user);
        $token = $user->fresh()->remember_token;

        $this->put(route('password.change.update'), [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($token, $user->fresh()->remember_token);
        $this->recallerRequest($cookie)->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
