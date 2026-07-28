<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserPresence;
use App\Services\CollaborationNotificationService;
use App\Services\DanaTalanganGoogleService;
use App\Services\DatabaseSheetSyncService;
use App\Services\KonsumenProgressSyncService;
use App\Services\OptimisticLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CollaborationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_are_private_and_read_state_is_user_scoped(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $other = User::factory()->create(['role_id' => $user->role_id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $own = UserNotification::create(['user_id' => $user->id, 'type' => 'record_updated', 'title' => 'Milik Saya', 'message' => 'Pesan saya']);
        $foreign = UserNotification::create(['user_id' => $other->id, 'type' => 'record_updated', 'title' => 'Milik Orang Lain', 'message' => 'Rahasia']);

        $this->actingAs($user)->getJson(route('notifications.index'))
            ->assertOk()->assertJsonPath('unread_count', 1)->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.title', 'Milik Saya');
        $this->actingAs($user)->patchJson(route('notifications.read', $foreign))->assertNotFound();
        $this->assertNull($foreign->fresh()->read_at);
        $this->actingAs($user)->patchJson(route('notifications.read', $own))->assertOk();
        $this->assertNotNull($own->fresh()->read_at);

        UserNotification::create(['user_id' => $user->id, 'type' => 'sync_completed', 'title' => 'Baru', 'message' => 'Baru']);
        $this->actingAs($user)->postJson(route('notifications.read-all'))->assertOk();
        $this->assertSame(0, UserNotification::where('user_id', $user->id)->whereNull('read_at')->count());
    }

    public function test_successful_update_sets_modifier_clears_editor_and_notifies_active_collaborator(): void
    {
        [$branch, $actor] = $this->branchAndUser();
        $collaborator = User::factory()->create(['role_id' => $actor->role_id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $item = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team', 'title' => 'Kolaborasi',
            'deadline_date' => today(), 'scheduled_date' => today(), 'priority' => 'medium', 'status' => 'todo', 'created_by' => $actor->id,
        ]);
        foreach ([[$actor, 'actor-tab'], [$collaborator, 'collaborator-tab']] as [$user, $session]) {
            UserPresence::create([
                'user_id' => $user->id, 'branch_id' => $branch->id, 'page_key' => 'content-calendar',
                'record_type' => 'content_item', 'record_id' => $item->id, 'context_key' => 'content_item:'.$item->id,
                'mode' => 'editing', 'session_key' => $session, 'last_seen_at' => now(),
            ]);
        }

        $this->actingAs($actor)->patchJson(route('content-calendar.update-status', $item), [
            'status' => 'completed', 'expected_updated_at' => app(OptimisticLockService::class)->token($item),
            'presence_session_key' => 'actor-tab',
        ])->assertOk();

        $this->assertSame($actor->id, $item->fresh()->updated_by);
        $this->assertDatabaseMissing('user_presences', ['user_id' => $actor->id, 'record_id' => $item->id]);
        $this->assertDatabaseHas('user_presences', ['user_id' => $collaborator->id, 'record_id' => $item->id]);
        $notification = UserNotification::where('user_id', $collaborator->id)->where('type', 'record_updated')->firstOrFail();
        $this->assertStringContainsString($actor->name, $notification->message);
        $this->assertStringNotContainsString($actor->email, $notification->message);
    }

    public function test_conflict_keeps_editing_presence_and_creates_separate_notification_not_activity(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $item = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'team', 'title' => 'Konflik',
            'deadline_date' => today(), 'scheduled_date' => today(), 'priority' => 'medium', 'status' => 'todo', 'created_by' => $user->id,
        ]);
        UserPresence::create([
            'user_id' => $user->id, 'branch_id' => $branch->id, 'page_key' => 'content-calendar',
            'record_type' => 'content_item', 'record_id' => $item->id, 'context_key' => 'content_item:'.$item->id,
            'mode' => 'editing', 'session_key' => 'conflict-tab', 'last_seen_at' => now(),
        ]);
        $activityCount = ActivityLog::count();

        $this->actingAs($user)->patchJson(route('content-calendar.update-status', $item), [
            'status' => 'completed', 'expected_updated_at' => '2000-01-01 00:00:00',
        ])->assertConflict();

        $this->assertDatabaseHas('user_presences', ['user_id' => $user->id, 'record_id' => $item->id, 'mode' => 'editing']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $user->id, 'type' => 'record_conflict']);
        $this->assertSame($activityCount, ActivityLog::count());
    }

    public function test_record_notification_rechecks_personal_item_visibility(): void
    {
        [$branch, $actor] = $this->branchAndUser();
        $formerViewer = User::factory()->create(['role_id' => $actor->role_id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $item = ContentItem::create([
            'branch_id' => $branch->id, 'item_type' => 'task', 'visibility' => 'personal', 'title' => 'Personal',
            'deadline_date' => today(), 'scheduled_date' => today(), 'priority' => 'medium', 'status' => 'todo', 'created_by' => $actor->id,
        ]);
        UserPresence::create([
            'user_id' => $formerViewer->id, 'branch_id' => $branch->id, 'page_key' => 'content-calendar',
            'record_type' => 'content_item', 'record_id' => $item->id, 'context_key' => 'content_item:'.$item->id,
            'mode' => 'editing', 'session_key' => 'former-viewer', 'last_seen_at' => now(),
        ]);

        app(CollaborationNotificationService::class)->recordUpdated($item, $actor, route('content-calendar.edit', $item));

        $this->assertDatabaseMissing('user_notifications', ['user_id' => $formerViewer->id, 'related_id' => $item->id]);
    }

    public function test_heartbeat_creates_neither_notification_nor_activity(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->actingAs($user)->postJson(route('presence.heartbeat'), [
            'page_key' => 'dashboard', 'branch_id' => $branch->id, 'mode' => 'viewing', 'session_key' => 'heartbeat-only',
        ])->assertOk();

        $this->assertSame(0, UserNotification::count());
        $this->assertSame(0, ActivityLog::count());
    }

    public function test_manual_sync_success_and_failures_notify_only_the_initiator(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $database = Mockery::mock(DatabaseSheetSyncService::class);
        $database->shouldReceive('syncBranch')->once()->andReturn(['ok' => true, 'summary' => ['Leads' => 12]]);
        $this->app->instance(DatabaseSheetSyncService::class, $database);
        $this->actingAs($user)->postJson(route('database.sync'), ['branch_id' => $branch->id])->assertOk();

        $progress = Mockery::mock(KonsumenProgressSyncService::class);
        $progress->shouldReceive('syncBranch')->once()->andReturn(['ok' => false, 'message' => 'API tidak tersedia', 'summary' => []]);
        $this->app->instance(KonsumenProgressSyncService::class, $progress);
        $this->actingAs($user)->postJson(route('konsumen-progress.sync'), ['branch_id' => $branch->id])->assertUnprocessable();

        $pusatRole = Role::firstOrCreate(['slug' => 'pusat'], ['name' => 'Pusat', 'is_superadmin' => false]);
        $user->update(['role_id' => $pusatRole->id]);
        $user->setRelation('role', $pusatRole);
        $dana = Mockery::mock(DanaTalanganGoogleService::class);
        $dana->shouldReceive('sync')->once()->with($user->id)->andReturn([
            'ok' => true, 'summary' => ['updated' => 1, 'imported' => 2, 'pushed' => 3],
        ]);
        $this->app->instance(DanaTalanganGoogleService::class, $dana);
        $this->actingAs($user)->postJson(route('dana-talangan.sync'))->assertOk();

        $this->assertDatabaseHas('user_notifications', ['user_id' => $user->id, 'type' => 'sync_completed']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $user->id, 'type' => 'sync_failed']);
        $this->assertTrue(UserNotification::where('user_id', $user->id)->where('message', 'like', '%Global%')->exists());
        $this->assertSame(3, UserNotification::where('user_id', $user->id)->whereIn('type', ['sync_completed', 'sync_failed'])->count());
    }

    public function test_membership_change_keeps_audit_log_and_creates_private_user_notification(): void
    {
        $superRole = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Superadmin', 'is_superadmin' => true]);
        $adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $branch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $actor = User::factory()->create(['role_id' => $superRole->id, 'password_changed_at' => now()]);
        $target = User::factory()->create(['role_id' => $adminRole->id, 'branch_id' => null, 'password_changed_at' => now()]);

        $this->actingAs($actor)->post(route('branches.assign-store', $branch), [
            'user_id' => $target->id, 'can_edit' => '1', 'can_sync' => '0', 'can_manage_members' => '0',
        ])->assertRedirect(route('branches.assign', $branch));

        $this->assertDatabaseHas('activity_log', ['causer_id' => $actor->id, 'subject_id' => $target->id, 'event' => 'membership_added']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $target->id, 'type' => 'membership_changed']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $actor->id, 'type' => 'membership_changed']);
    }

    private function branchAndUser(): array
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $branch = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'sheet_id' => 'sheet-id', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);

        return [$branch, $user];
    }
}
