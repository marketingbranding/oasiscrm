<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_change_preserves_current_database_session_and_revokes_others(): void
    {
        $user = $this->user();
        $this->insertSession($user, 'current-session');
        $this->insertSession($user, 'other-session');
        $this->insertSession($this->user(), 'unrelated-session');

        app(UserAccountService::class)->changePassword(
            $user,
            'new-database-session-password',
            'current-session',
            'password_changed',
        );

        $this->assertDatabaseHas('sessions', ['id' => 'current-session', 'user_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-session']);
        $this->assertNotNull($user->refresh()->password_changed_at);
    }

    public function test_suspension_revokes_every_database_session_for_target_only(): void
    {
        $actor = $this->user('superadmin');
        $target = $this->user();
        $other = $this->user();
        $this->insertSession($target, 'target-one');
        $this->insertSession($target, 'target-two');
        $this->insertSession($other, 'other-user');

        app(UserAccountService::class)->suspend($target, $actor);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertDatabaseHas('sessions', ['id' => 'other-user', 'user_id' => $other->id]);
    }

    private function insertSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'IAM test',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ]);
    }

    private function user(string $role = 'staff'): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'password_changed_at' => now()->subDay(),
        ]);
    }
}
