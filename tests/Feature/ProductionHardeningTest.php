<?php

namespace Tests\Feature;

use App\Models\AiChatConversation;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPresence;
use App\Services\OptimisticLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password'), 'is_active' => false]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivation_terminates_existing_web_and_json_access_on_next_request(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $user->update(['is_active' => false]);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->actingAs($user->fresh())->getJson(route('notifications.index'))
            ->assertForbidden()->assertJsonPath('code', 'account_inactive');
        $this->actingAs($user->fresh())->postJson(route('presence.heartbeat'), [
            'page_key' => 'dashboard', 'branch_id' => $branch->id, 'mode' => 'viewing', 'session_key' => 'inactive-tab',
        ])->assertForbidden()->assertJsonPath('code', 'account_inactive');
    }

    public function test_read_only_membership_cannot_import_or_bulk_update(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $user->branches()->updateExistingPivot($branch->id, ['can_edit' => false]);
        $item = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team', 'title' => 'Tidak Boleh Diubah',
            'deadline_date' => today(), 'scheduled_date' => today(), 'priority' => 'medium', 'status' => 'todo', 'created_by' => $user->id,
        ]);

        $this->actingAs($user)->post(route('content-calendar.import-store'), [
            'branch_id' => $branch->id,
            'file' => UploadedFile::fake()->create('import.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])->assertForbidden();
        $this->actingAs($user)->post(route('content-calendar.bulk-update'), [
            'ids' => [$item->id], 'status' => 'completed',
        ])->assertForbidden();
        $this->assertSame('todo', $item->fresh()->status);
    }

    public function test_manipulated_import_branch_and_ai_conversation_are_rejected(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $other = User::factory()->create(['role_id' => $user->role_id, 'branch_id' => $otherBranch->id, 'password_changed_at' => now()]);
        $conversation = AiChatConversation::create([
            'user_id' => $other->id, 'branch_id' => $otherBranch->id, 'title' => 'Private', 'messages' => [],
        ]);

        $this->actingAs($user)->post(route('content-calendar.import-store'), [
            'branch_id' => $otherBranch->id,
            'file' => UploadedFile::fake()->create('import.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])->assertForbidden();
        $this->actingAs($user)->post(route('ai-chat.chat'), [
            'message' => 'test', 'conversation_id' => $conversation->id,
        ])->assertSessionHasErrors('conversation_id');
        $this->assertSame($branch->id, $user->branch_id);
    }

    public function test_branch_admin_cannot_trigger_global_dana_sync(): void
    {
        [, $user] = $this->branchAndUser();
        $this->actingAs($user)->postJson(route('dana-talangan.sync'))->assertForbidden();
    }

    public function test_personal_work_planner_presence_context_is_not_accessible_to_colleague(): void
    {
        [$branch, $owner] = $this->branchAndUser();
        $colleague = User::factory()->create(['role_id' => $owner->role_id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $item = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'personal', 'title' => 'Personal',
            'deadline_date' => today(), 'scheduled_date' => today(), 'priority' => 'medium', 'status' => 'todo', 'created_by' => $owner->id,
        ]);

        $this->actingAs($colleague)->postJson(route('presence.heartbeat'), [
            'page_key' => 'content-calendar', 'branch_id' => $branch->id, 'record_type' => 'content_item',
            'record_id' => $item->id, 'mode' => 'viewing', 'session_key' => 'probe-tab',
        ])->assertForbidden();
    }

    public function test_notification_failure_does_not_roll_back_successful_record_update(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $item = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team', 'title' => 'Tetap Tersimpan',
            'deadline_date' => today(), 'scheduled_date' => today(), 'priority' => 'medium', 'status' => 'todo', 'created_by' => $user->id,
        ]);
        $collaborator = User::factory()->create(['role_id' => $user->role_id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        UserPresence::create([
            'user_id' => $collaborator->id, 'branch_id' => $branch->id, 'page_key' => 'content-calendar',
            'record_type' => 'content_item', 'record_id' => $item->id, 'context_key' => 'content_item:'.$item->id,
            'mode' => 'editing', 'session_key' => 'collaborator-tab', 'last_seen_at' => now(),
        ]);
        Schema::drop('user_notifications');

        $this->actingAs($user)->patchJson(route('content-calendar.update-status', $item), [
            'status' => 'completed', 'expected_updated_at' => app(OptimisticLockService::class)->token($item),
            'presence_session_key' => 'update-tab',
        ])->assertOk();
        $this->assertSame('completed', $item->fresh()->status);
    }

    public function test_expected_conflict_is_logged_as_info_not_error(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $item = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team', 'title' => 'Conflict Log',
            'deadline_date' => today(), 'scheduled_date' => today(), 'priority' => 'medium', 'status' => 'todo', 'created_by' => $user->id,
        ]);
        Log::spy();

        $this->actingAs($user)->patchJson(route('content-calendar.update-status', $item), [
            'status' => 'completed', 'expected_updated_at' => '2000-01-01 00:00:00',
        ])->assertConflict();

        Log::shouldHaveReceived('info')->once()->with('Optimistic lock conflict', \Mockery::type('array'));
        Log::shouldNotHaveReceived('error');
    }

    private function branchAndUser(): array
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);

        return [$branch, $user];
    }
}
