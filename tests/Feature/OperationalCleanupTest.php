<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SystemTaskRun;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\UserPresence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperationalCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_presence_cleanup_records_successful_run_and_deleted_count(): void
    {
        $user = User::factory()->create();
        UserPresence::create([
            'user_id' => $user->id, 'page_key' => 'dashboard', 'context_key' => 'page', 'mode' => 'viewing',
            'session_key' => 'old-tab', 'last_seen_at' => now()->subHours(25),
        ]);

        $this->artisan('oasis:presence-cleanup')->assertSuccessful();
        $run = SystemTaskRun::where('task_key', 'oasis:presence-cleanup')->latest()->firstOrFail();
        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->summary['rows_deleted']);
    }

    public function test_presence_cleanup_records_failure_without_sensitive_trace(): void
    {
        Schema::drop('user_presences');

        $this->artisan('oasis:presence-cleanup')->assertFailed();
        $run = SystemTaskRun::where('task_key', 'oasis:presence-cleanup')->latest()->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->error_message);
        $this->assertStringNotContainsString('#0', $run->error_message);
    }

    public function test_notification_cleanup_dry_run_and_retention_policy_preserve_unread_and_activity_logs(): void
    {
        $user = User::factory()->create();
        $oldRead = UserNotification::create([
            'user_id' => $user->id, 'type' => 'record_updated', 'title' => 'Read', 'message' => 'Read',
            'read_at' => now()->subDays(181),
        ]);
        $oldUnread = UserNotification::create([
            'user_id' => $user->id, 'type' => 'record_updated', 'title' => 'Unread', 'message' => 'Unread',
            'created_at' => now()->subDays(365),
        ]);
        $activity = ActivityLog::create([
            'causer_id' => $user->id, 'subject_type' => User::class, 'subject_id' => $user->id,
            'event' => 'test', 'description' => 'Permanent audit', 'properties' => [],
        ]);

        $this->artisan('oasis:notifications-cleanup --dry-run')->assertSuccessful();
        $this->assertDatabaseHas('user_notifications', ['id' => $oldRead->id]);
        $this->artisan('oasis:notifications-cleanup')->assertSuccessful();
        $this->assertDatabaseMissing('user_notifications', ['id' => $oldRead->id]);
        $this->assertDatabaseHas('user_notifications', ['id' => $oldUnread->id]);
        $this->assertDatabaseHas('activity_log', ['id' => $activity->id]);
    }
}
