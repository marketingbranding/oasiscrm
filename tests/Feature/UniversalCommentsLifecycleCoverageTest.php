<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\UserNotification;
use App\Services\CommentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\BuildsUniversalCommentFixtures;
use Tests\TestCase;

class UniversalCommentsLifecycleCoverageTest extends TestCase
{
    use BuildsUniversalCommentFixtures, RefreshDatabase;

    public function test_reply_permission_wrong_target_and_deleted_placeholders_are_enforced(): void
    {
        $branch = $this->commentBranch();
        $owner = $this->commentUser('manager', $branch);
        $first = $this->commentPlanner($owner, $branch);
        $second = $this->commentPlanner($owner, $branch, ['title' => 'Second target']);
        $parent = app(CommentService::class)->create($owner, $first, 'Parent');

        $owner->role->permissions()->detach(Permission::where('slug', 'comments.reply')->value('id'));
        $this->actingAs($owner->fresh())->postJson(route('comments.store'), [
            'alias' => 'planner-item', 'id' => $first->id, 'body' => 'Denied reply', 'parent_id' => $parent->id,
        ])->assertForbidden();

        $owner->role->permissions()->attach(Permission::where('slug', 'comments.reply')->value('id'));
        $this->actingAs($owner->fresh())->postJson(route('comments.store'), [
            'alias' => 'planner-item', 'id' => $second->id, 'body' => 'Wrong target', 'parent_id' => $parent->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $reply = app(CommentService::class)->create($owner->fresh(), $first, 'Reply', $parent->id);
        app(CommentService::class)->delete($parent, $owner->fresh(), 0);
        $response = $this->actingAs($owner->fresh())->getJson(route('comments.index', [
            'alias' => 'planner-item', 'id' => $first->id,
        ]))->assertOk();
        $response->assertJsonPath('data.0.body', CommentService::DELETED_PLACEHOLDER)
            ->assertJsonPath('data.0.mentions', [])
            ->assertJsonPath('data.0.replies.0.id', $reply->id)
            ->assertJsonPath('data.0.reply_count', 1);
    }

    public function test_readded_mention_reuses_notification_resets_unread_and_refreshes_payload(): void
    {
        $branch = $this->commentBranch();
        $actor = $this->commentUser('manager', $branch, ['name' => 'Comment Actor']);
        $recipient = $this->commentUser('manager', $branch, ['name' => 'Mention Recipient']);
        $target = $this->commentPlanner($actor, $branch);
        $service = app(CommentService::class);
        $comment = $service->create($actor, $target, 'First body', null, [$recipient->id]);
        $notification = UserNotification::where('user_id', $recipient->id)->sole();
        $notification->update(['read_at' => now()]);

        $comment = $service->update($comment, $actor, 'Mention removed', 0, []);
        $this->assertNotNull($notification->fresh()->read_at);
        $comment = $service->update($comment, $actor, 'Fresh re-added excerpt', 1, [$recipient->id]);

        $this->assertSame(1, UserNotification::where('user_id', $recipient->id)->where('comment_id', $comment->id)->count());
        $notification = $notification->fresh();
        $this->assertNull($notification->read_at);
        $this->assertSame('Fresh re-added excerpt', $notification->data['excerpt']);
        $this->assertSame((string) $actor->id, $notification->data['actor_id']);

        $notification->update(['read_at' => now()]);
        $service->update($comment, $actor, 'Mention unchanged', 2, [$recipient->id]);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_revoked_access_redacts_feed_and_open_is_safe_and_marks_read(): void
    {
        $branch = $this->commentBranch();
        $actor = $this->commentUser('manager', $branch);
        $recipient = $this->commentUser('manager', $branch);
        $target = $this->commentPlanner($actor, $branch);
        app(CommentService::class)->create($actor, $target, 'Sensitive revoked text', null, [$recipient->id]);
        $notification = UserNotification::where('user_id', $recipient->id)->sole();

        $target->update(['visibility' => 'personal']);
        $feed = $this->actingAs($recipient)->getJson(route('notifications.index'))->assertOk();
        $feed->assertJsonPath('notifications.0.title', 'Notifikasi komentar')
            ->assertJsonPath('notifications.0.message', 'Data tidak tersedia atau Anda tidak memiliki akses.')
            ->assertJsonPath('notifications.0.data', null);
        $this->assertStringNotContainsString('Sensitive revoked text', $feed->getContent());

        $this->get(route('notifications.open', $notification))->assertRedirect(route('dashboard'))
            ->assertSessionHas('warning', 'Data tidak tersedia atau Anda tidak memiliki akses.');
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_deleted_and_missing_comments_open_without_exposing_stale_content(): void
    {
        $branch = $this->commentBranch();
        $actor = $this->commentUser('manager', $branch);
        $recipient = $this->commentUser('manager', $branch);
        $target = $this->commentPlanner($actor, $branch);
        $comment = app(CommentService::class)->create($actor, $target, 'Deleted secret', null, [$recipient->id]);
        $notification = UserNotification::where('user_id', $recipient->id)->sole();
        $comment->delete();

        $this->actingAs($recipient)->getJson(route('notifications.index'))->assertOk()
            ->assertJsonPath('notifications.0.message', CommentService::DELETED_PLACEHOLDER)
            ->assertJsonPath('notifications.0.data.excerpt', CommentService::DELETED_PLACEHOLDER);
        $this->get(route('notifications.open', $notification))->assertRedirect(
            route('comments.thread', ['alias' => 'planner-item', 'id' => $target->id]).'#comment-'.$comment->id
        );

        $comment->forceDelete();
        $this->get(route('notifications.open', $notification))->assertRedirect(route('dashboard'))
            ->assertSessionHas('warning');
    }

    public function test_moderate_and_restore_use_lock_versions_and_reason_validation(): void
    {
        $branch = $this->commentBranch();
        $owner = $this->commentUser('manager', $branch);
        $moderator = $this->commentUser('superadmin');
        $target = $this->commentPlanner($owner, $branch);
        $comment = app(CommentService::class)->create($owner, $target, 'Moderate me');
        app(CommentService::class)->update($comment, $owner, 'Current body', 0);

        $this->actingAs($moderator)->postJson(route('comments.moderate', $comment), [
            'action' => 'hide', 'reason' => '   ', 'expected_lock_version' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $requestSource = file_get_contents(app_path('Http/Requests/Crm/ModerateCommentRequest.php'));
        $this->assertStringContainsString("Rule::in(['hide'])", $requestSource);
        $this->assertStringContainsString("'max:1000'", $requestSource);

        $this->actingAs($moderator)->postJson(route('comments.moderate', $comment), [
            'action' => 'hide', 'reason' => 'Stale moderation', 'expected_lock_version' => 0,
        ])->assertConflict()->assertJsonPath('current_lock_version', 1);

        $this->postJson(route('comments.moderate', $comment), [
            'action' => 'hide', 'reason' => 'Documented moderation reason', 'expected_lock_version' => 1,
        ])->assertOk()->assertJsonPath('data.body', CommentService::DELETED_PLACEHOLDER);
        $this->postJson(route('comments.restore', $comment), ['expected_lock_version' => 1])
            ->assertConflict()->assertJsonPath('current_lock_version', 2);
        $this->postJson(route('comments.restore', $comment), ['expected_lock_version' => 2])
            ->assertOk()->assertJsonPath('data.body', 'Current body')->assertJsonPath('data.lock_version', 3);
    }

    public function test_comments_have_no_permanent_delete_route_and_restore_controls_are_moderator_only(): void
    {
        $this->assertFalse(Route::has('comments.force-delete'));
        $this->assertFalse(Route::has('comments.forceDelete'));
        $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn (string $name) => str_starts_with($name, 'comments.'));
        $this->assertFalse($routes->contains(fn (string $name) => str_contains($name, 'permanent')));

        $panel = file_get_contents(resource_path('views/components/comments/panel.blade.php'));
        $this->assertStringContainsString('comment.is_deleted && comment.can_restore', $panel);
        $this->assertStringContainsString('reply.is_deleted && reply.can_restore', $panel);
        $this->assertStringContainsString('Alasan Moderasi', $panel);
    }
}
