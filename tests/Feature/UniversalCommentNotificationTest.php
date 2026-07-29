<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\CommentNotificationService;
use App\Services\CommentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UniversalCommentNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mentions_notify_once_without_self_notification_or_email_and_store_plain_payload(): void
    {
        Mail::fake();
        [$actor, $target, $branch] = $this->fixture();
        $recipient = $this->user('manager', $branch, 'Mentioned User');
        $comment = app(CommentService::class)->create(
            $actor,
            $target,
            '<b>Isi rahasia</b> '.str_repeat('panjang ', 30),
            null,
            [$recipient->id, $recipient->id, $actor->id],
        );

        $notification = UserNotification::where('user_id', $recipient->id)->sole();
        $this->assertSame('comment_mentioned', $notification->type);
        $this->assertSame($comment->id, $notification->comment_id);
        $this->assertSame($actor->id, $notification->actor_user_id);
        $this->assertSame('content_item', $notification->related_type);
        $this->assertSame($target->id, $notification->related_id);
        $this->assertSame(route('notifications.open', $notification), $notification->action_url);
        $this->assertLessThanOrEqual(160, mb_strlen($notification->data['excerpt']));
        $this->assertStringNotContainsString('<', $notification->message.json_encode($notification->data));
        foreach ($notification->data as $value) {
            $this->assertIsString($value);
        }
        $this->assertFalse(UserNotification::where('user_id', $actor->id)->exists());
        Mail::assertNothingSent();
    }

    public function test_reply_notifies_owner_unless_actor_or_already_mentioned(): void
    {
        [$owner, $target, $branch] = $this->fixture();
        $actor = $this->user('manager', $branch, 'Reply Actor');
        $parent = app(CommentService::class)->create($owner, $target, 'Induk');

        $reply = app(CommentService::class)->create($actor, $target, 'Balasan', $parent->id);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->id,
            'type' => 'comment_replied',
            'comment_id' => $reply->id,
        ]);

        $mentionedReply = app(CommentService::class)->create($actor, $target, 'Balasan mention', $parent->id, [$owner->id]);
        $this->assertSame(1, UserNotification::where('user_id', $owner->id)
            ->where('comment_id', $mentionedReply->id)->count());
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->id,
            'type' => 'comment_mentioned',
            'comment_id' => $mentionedReply->id,
        ]);
        $this->assertDatabaseMissing('user_notifications', [
            'user_id' => $owner->id,
            'type' => 'comment_replied',
            'comment_id' => $mentionedReply->id,
        ]);

        $selfParent = app(CommentService::class)->create($actor, $target, 'Induk sendiri');
        $selfReply = app(CommentService::class)->create($actor, $target, 'Balas sendiri', $selfParent->id);
        $this->assertFalse(UserNotification::where('comment_id', $selfReply->id)->exists());
    }

    public function test_edit_notifies_only_new_mentions_and_duplicate_creation_is_deterministic(): void
    {
        [$actor, $target, $branch] = $this->fixture();
        $unchanged = $this->user('manager', $branch, 'Unchanged');
        $added = $this->user('manager', $branch, 'Added');
        $service = app(CommentService::class);
        $comment = $service->create($actor, $target, 'Awal', null, [$unchanged->id]);
        $updated = $service->update($comment, $actor, 'Edit', 0, [$unchanged->id, $added->id]);

        $this->assertSame(1, UserNotification::where('user_id', $unchanged->id)->where('comment_id', $comment->id)->count());
        $this->assertSame(1, UserNotification::where('user_id', $added->id)->where('comment_id', $comment->id)->count());

        app(CommentNotificationService::class)->updated($updated, $actor, $target, collect([$added]));
        $this->assertSame(1, UserNotification::where('user_id', $added->id)
            ->where('type', 'comment_mentioned')->where('comment_id', $comment->id)->count());
    }

    public function test_notification_service_skips_inactive_and_inaccessible_recipients(): void
    {
        [$actor, $target, $branch] = $this->fixture();
        $inactive = $this->user('manager', $branch, 'Inactive');
        $inactive->forceFill(['is_active' => false])->save();
        $outsider = $this->user('manager', Branch::create(['name' => 'Outside', 'code' => 'OUT']), 'Outside');
        $comment = $target->comments()->create(['user_id' => $actor->id, 'body' => 'Body', 'body_plain' => 'Body']);

        app(CommentNotificationService::class)->created($comment, $actor, $target, collect([$inactive, $outsider]));
        $this->assertFalse(UserNotification::where('comment_id', $comment->id)->exists());
    }

    public function test_open_is_recipient_only_marks_read_redirects_with_fragment_and_reauthorizes(): void
    {
        [$actor, $target, $branch] = $this->fixture();
        $recipient = $this->user('manager', $branch, 'Recipient');
        $other = $this->user('manager', $branch, 'Other');
        $comment = app(CommentService::class)->create($actor, $target, 'Open me', null, [$recipient->id]);
        $notification = UserNotification::where('user_id', $recipient->id)->sole();

        $this->actingAs($other)->get(route('notifications.open', $notification))->assertNotFound();
        $this->actingAs($recipient)->get(route('notifications.open', $notification))
            ->assertRedirect(route('comments.thread', ['alias' => 'planner-item', 'id' => $target->id]).'#comment-'.$comment->id);
        $readAt = $notification->fresh()->read_at;
        $this->assertNotNull($readAt);
        $this->actingAs($recipient)->get(route('notifications.open', $notification))->assertRedirect();
        $this->assertTrue($readAt->equalTo($notification->fresh()->read_at));

        $target->update(['visibility' => 'personal']);
        $blockedComment = app(CommentService::class)->create($actor, $target, 'Blocked direct record');
        $blocked = UserNotification::create([
            'user_id' => $recipient->id,
            'type' => 'comment_mentioned',
            'title' => 'Blocked',
            'message' => 'Blocked',
            'comment_id' => $blockedComment->id,
            'action_url' => route('notifications.open', 999999),
        ]);
        $this->actingAs($recipient)->get(route('notifications.open', $blocked))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('warning', 'Data tidak tersedia atau Anda tidak memiliki akses.');
        $this->assertNotNull($blocked->fresh()->read_at);
    }

    public function test_sales_feed_preserves_comment_open_action_and_hides_deleted_body_without_n_plus_one(): void
    {
        [$sales, $target, $branch] = $this->fixture('sales');
        $actor = $this->user('manager', $branch, 'Sales Actor');
        $comment = app(CommentService::class)->create($actor, $target, 'Rahasia yang dihapus', null, [$sales->id]);
        $notification = UserNotification::where('user_id', $sales->id)->sole();

        $beforeDelete = $this->actingAs($sales)->getJson(route('notifications.index'))->assertOk();
        $beforeDelete->assertJsonPath('notifications.0.action_url', route('notifications.open', $notification));

        $comment->delete();
        foreach (range(1, 9) as $number) {
            $extra = $target->comments()->create([
                'user_id' => $actor->id,
                'body' => 'Extra '.$number,
                'body_plain' => 'Extra '.$number,
            ]);
            UserNotification::create([
                'user_id' => $sales->id,
                'type' => 'comment_mentioned',
                'title' => 'Extra',
                'message' => 'Extra',
                'comment_id' => $extra->id,
                'related_type' => 'content_item',
                'related_id' => $target->id,
                'action_url' => route('notifications.open', $notification),
                'data' => ['excerpt' => 'Extra'],
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson(route('notifications.index'))->assertOk()->assertJsonCount(10, 'notifications');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $deleted = collect($response->json('notifications'))->firstWhere('id', $notification->id);
        $this->assertSame(CommentService::DELETED_PLACEHOLDER, $deleted['message']);
        $this->assertSame(CommentService::DELETED_PLACEHOLDER, $deleted['data']['excerpt']);
        $this->assertStringNotContainsString('Rahasia yang dihapus', $response->getContent());
        $this->assertLessThanOrEqual(12, $queryCount);
    }

    /** @return array{User, ContentItem, Branch} */
    private function fixture(string $role = 'manager'): array
    {
        $branch = Branch::create(['name' => 'Notification Branch', 'code' => uniqid('NB')]);
        $actor = $this->user($role, $branch, 'Actor');
        $target = ContentItem::create([
            'branch_id' => $branch->id,
            'title' => 'Notification target',
            'item_type' => 'task',
            'visibility' => 'team',
            'scheduled_date' => today(),
            'status' => 'todo',
            'created_by' => $actor->id,
        ]);

        return [$actor, $target, $branch];
    }

    private function user(string $role, Branch $branch, string $name): User
    {
        return User::factory()->create([
            'name' => $name,
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
    }
}
