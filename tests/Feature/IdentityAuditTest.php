<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdentityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_audit_records_actor_target_event_and_sanitized_old_new_values(): void
    {
        $actor = $this->user('superadmin');
        $target = $this->user('staff');

        $log = app(AccountAuditService::class)->log('identity_tested', $target, $actor, [
            'account_status' => 'inactive',
            'password' => 'old-secret',
            'token_hash' => 'old-token-hash',
        ], [
            'account_status' => 'active',
            'password_confirmation' => 'new-secret',
            'remember_token' => 'remember-secret',
        ])->refresh();

        $this->assertSame($actor->id, $log->causer_id);
        $this->assertSame(User::class, $log->subject_type);
        $this->assertSame($target->id, $log->subject_id);
        $this->assertSame('identity_tested', $log->event);
        $this->assertSame($actor->id, $log->properties['actor_user_id']);
        $this->assertSame($target->id, $log->properties['target_user_id']);
        $this->assertSame(['account_status' => 'inactive'], $log->properties['old']);
        $this->assertSame(['account_status' => 'active'], $log->properties['new']);

        $encoded = DB::table('activity_log')->where('id', $log->id)->value('properties');
        foreach (['old-secret', 'new-secret', 'old-token-hash', 'remember-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_login_and_password_events_do_not_store_credentials(): void
    {
        $user = $this->user('staff');

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'audit-new-password',
            'password_confirmation' => 'audit-new-password',
        ])->assertSessionHasNoErrors();

        $events = ActivityLog::where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->whereIn('event', ['login_success', 'password_changed'])
            ->get();
        $this->assertEqualsCanonicalizing(['login_success', 'password_changed'], $events->pluck('event')->all());
        $payload = $events->pluck('properties')->flatten()->toJson();
        $this->assertStringNotContainsString('password', $payload);
        $this->assertStringNotContainsString('audit-new-password', $payload);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'password_changed_at' => now(),
        ]);
    }
}
