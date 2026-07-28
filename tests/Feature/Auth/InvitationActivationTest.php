<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use App\Services\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InvitationActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resending_invitation_revokes_the_previous_token(): void
    {
        Notification::fake();
        $inviter = User::factory()->create();
        $user = User::factory()->create([
            'account_status' => AccountStatus::PendingInvitation,
            'is_active' => false,
        ]);
        $service = app(UserInvitationService::class);

        $first = $service->send($user, $inviter);
        $second = $service->resend($user->refresh(), $inviter);

        $this->assertNotNull($first->refresh()->revoked_at);
        $this->assertNotNull($second->sent_at);
        $this->assertNotSame($first->token_hash, $second->token_hash);
        $this->assertSame(64, strlen($second->token_hash));
        Notification::assertSentToTimes($user, UserInvitationNotification::class, 2);
    }

    public function test_invitation_can_activate_an_account_once_without_storing_raw_token(): void
    {
        $rawToken = 'secure-test-token';
        $user = User::factory()->create([
            'account_status' => AccountStatus::Invited,
            'is_active' => false,
            'email_verified_at' => null,
        ]);
        $invitation = UserInvitation::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addHour(),
            'sent_at' => now(),
        ]);

        $response = $this->post(route('invitations.store', $rawToken), [
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(AccountStatus::Active, $user->refresh()->account_status);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
        $this->assertNotNull($invitation->refresh()->accepted_at);
        $this->assertDatabaseMissing('user_invitations', ['token_hash' => $rawToken]);
    }

    public function test_expired_invitation_cannot_activate_an_account(): void
    {
        $rawToken = 'expired-test-token';
        $user = User::factory()->create([
            'account_status' => AccountStatus::Invited,
            'is_active' => false,
        ]);
        UserInvitation::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->subMinute(),
        ]);

        $this->post(route('invitations.store', $rawToken), [
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertRedirect(route('invitations.show', $rawToken));

        $this->assertGuest();
        $this->assertSame(AccountStatus::Invited, $user->refresh()->account_status);
    }

    public function test_suspended_account_cannot_login_and_receives_status_message(): void
    {
        $user = User::factory()->create([
            'account_status' => AccountStatus::Suspended,
            'is_active' => false,
        ]);

        $this->post('/login', [
            'email' => strtoupper($user->email),
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => 'Akun Anda sedang ditangguhkan. Hubungi administrator OASIS.']);

        $this->assertGuest();
    }
}
