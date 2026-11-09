<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use RuntimeException;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_mail_delivery_failure_returns_a_form_error_instead_of_a_server_error(): void
    {
        $user = User::factory()->create(['email' => 'mail-failure@example.test']);
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andThrow(new RuntimeException('SMTP unavailable'));

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasErrors(['email' => 'Email reset belum dapat dikirim. Periksa konfigurasi email atau hubungi administrator OASIS.']);
    }

    public function test_password_reset_requests_are_rate_limited(): void
    {
        $user = User::factory()->create(['email' => 'rate-limited@example.test']);
        Password::shouldReceive('sendResetLink')
            ->times(5)
            ->andReturn(Password::RESET_LINK_SENT);

        foreach (range(1, 5) as $_) {
            $this->post('/forgot-password', ['email' => $user->email])
                ->assertSessionHasNoErrors();
        }

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasErrors(['email' => 'Terlalu banyak permintaan reset. Coba lagi beberapa saat lagi.']);
    }
}
