<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Comment;
use App\Models\CommentRevision;
use App\Models\ContentItem;
use App\Models\Role;
use App\Models\User;
use App\Services\CommentService;
use App\Services\OptimisticLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UniversalCommentsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_plain_text_crud_revision_mentions_and_version_conflicts_are_safe(): void
    {
        [$owner, $item] = $this->fixture();
        $body = '<script>alert("xss")</script> & catatan';

        $created = $this->actingAs($owner)->postJson(route('comments.store'), [
            'alias' => 'planner-item', 'id' => $item->id, 'body' => $body,
            'mentioned_user_ids' => [$owner->id],
        ])->assertCreated()->assertJsonPath('data.body', $body)->assertJsonPath('data.lock_version', 0);
        $commentId = $created->json('data.id');
        $this->assertDatabaseHas('comment_mentions', ['comment_id' => $commentId, 'mentioned_user_id' => $owner->id]);

        $this->patchJson(route('comments.update', $commentId), [
            'body' => '<b>tetap teks biasa</b>', 'expected_lock_version' => 0,
            'mentioned_user_ids' => [$owner->id],
        ])->assertOk()->assertJsonPath('data.body', '<b>tetap teks biasa</b>')->assertJsonPath('data.is_edited', true)
            ->assertJsonPath('data.lock_version', 1);

        $revision = CommentRevision::firstOrFail();
        $this->assertSame($body, $revision->previous_body);
        $this->assertSame([$owner->id], $revision->previous_mentioned_user_ids);

        $conflict = $this->patchJson(route('comments.update', $commentId), [
            'body' => 'perubahan basi', 'expected_lock_version' => 0,
        ])->assertConflict()->assertJsonPath('code', 'record_modified')
            ->assertJsonPath('message', OptimisticLockService::MESSAGE)
            ->assertJsonPath('record_type', 'comment')->assertJsonPath('record_id', $commentId)
            ->assertJsonPath('current_lock_version', 1);
        $this->assertStringEndsWith('#comment-'.$commentId, $conflict->json('reload_url'));

        $this->deleteJson(route('comments.destroy', $commentId), ['expected_lock_version' => 0])
            ->assertConflict()->assertJsonPath('current_lock_version', 1);
    }

    public function test_threads_reject_nested_and_deleted_parent_replies_and_keep_parent_placeholder(): void
    {
        [$owner, $item] = $this->fixture();
        $parentId = $this->createComment($owner, $item, 'Induk');
        $replyId = $this->createComment($owner, $item, 'Balasan', $parentId);

        $this->actingAs($owner)->postJson(route('comments.store'), [
            'alias' => 'planner-item', 'id' => $item->id, 'body' => 'Bertingkat', 'parent_id' => $replyId,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->deleteJson(route('comments.destroy', $parentId), ['expected_lock_version' => 0])->assertOk()
            ->assertJsonPath('data.body', CommentService::DELETED_PLACEHOLDER);

        $this->getJson(route('comments.index', ['alias' => 'planner-item', 'id' => $item->id]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $parentId)
            ->assertJsonPath('data.0.body', CommentService::DELETED_PLACEHOLDER)
            ->assertJsonPath('data.0.replies.0.id', $replyId)->assertJsonPath('data.0.replies.0.body', 'Balasan');

        $this->postJson(route('comments.store'), [
            'alias' => 'planner-item', 'id' => $item->id, 'body' => 'Terlambat', 'parent_id' => $parentId,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    }

    public function test_owner_window_non_owner_and_target_access_are_enforced(): void
    {
        [$owner, $item, $branch] = $this->fixture();
        $other = $this->user('manager', $branch);
        $commentId = $this->createComment($owner, $item, 'Milik owner');

        $this->actingAs($other)->patchJson(route('comments.update', $commentId), [
            'body' => 'Ambil alih', 'expected_lock_version' => 0,
        ])->assertForbidden();
        $this->deleteJson(route('comments.destroy', $commentId), ['expected_lock_version' => 0])->assertForbidden();

        Carbon::setTestNow(now()->addMinutes(31));
        $this->actingAs($owner)->patchJson(route('comments.update', $commentId), [
            'body' => 'Terlambat', 'expected_lock_version' => 0,
        ])->assertForbidden();
        $this->deleteJson(route('comments.destroy', $commentId), ['expected_lock_version' => 0])->assertForbidden();
        Carbon::setTestNow();

        $foreignBranch = Branch::create(['name' => 'Cabang Lain', 'code' => 'LAIN']);
        $outsider = $this->user('manager', $foreignBranch);
        $this->actingAs($outsider)->getJson(route('comments.index', ['alias' => 'planner-item', 'id' => $item->id]))->assertForbidden();
        $this->postJson(route('comments.store'), ['alias' => 'planner-item', 'id' => $item->id, 'body' => 'Tidak boleh'])->assertForbidden();
    }

    public function test_moderation_restore_and_history_authorization_are_audited(): void
    {
        [$owner, $item, $branch] = $this->fixture();
        $commentId = $this->createComment($owner, $item, 'Versi awal');
        $this->patchJson(route('comments.update', $commentId), [
            'body' => 'Versi baru', 'expected_lock_version' => 0,
        ])->assertOk();

        $this->getJson(route('comments.history', $commentId))->assertOk()
            ->assertJsonPath('data.0.previous_body', 'Versi awal');
        $other = $this->user('manager', $branch);
        $this->actingAs($other)->getJson(route('comments.history', $commentId))->assertForbidden();

        $moderator = $this->user('superadmin');
        $this->actingAs($moderator)->patchJson(route('comments.update', $commentId), [
            'body' => 'Moderator tidak boleh menulis ulang', 'expected_lock_version' => 1,
        ])->assertForbidden();
        $this->actingAs($moderator)->postJson(route('comments.moderate', $commentId), [
            'action' => 'hide', 'reason' => 'Melanggar pedoman internal', 'expected_lock_version' => 1,
        ])->assertOk()->assertJsonPath('data.is_deleted', true)
            ->assertJsonPath('data.body', CommentService::DELETED_PLACEHOLDER);
        $this->assertDatabaseHas('comment_moderations', [
            'comment_id' => $commentId, 'moderated_by' => $moderator->id,
            'action' => 'hide', 'reason' => 'Melanggar pedoman internal',
        ]);

        $this->postJson(route('comments.restore', $commentId), ['expected_lock_version' => 2])->assertOk()
            ->assertJsonPath('data.body', 'Versi baru')->assertJsonPath('data.lock_version', 3);
        $this->assertDatabaseHas('activity_log', ['subject_type' => Comment::class, 'subject_id' => $commentId, 'event' => 'comment_moderated']);
        $this->assertDatabaseHas('activity_log', ['subject_type' => Comment::class, 'subject_id' => $commentId, 'event' => 'comment_restored']);

        $secondId = $this->createComment($owner, $item, 'Dihapus moderator');
        $this->actingAs($moderator)->deleteJson(route('comments.destroy', $secondId), ['expected_lock_version' => 0])
            ->assertOk()->assertJsonPath('data.is_deleted', true);
    }

    public function test_latest_pages_are_fetched_in_twenty_item_pages_and_displayed_oldest_first(): void
    {
        [$owner, $item] = $this->fixture();
        $ids = [];
        foreach (range(1, 25) as $number) {
            $ids[] = $this->createComment($owner, $item, 'Komentar '.$number);
        }

        $pageOne = $this->actingAs($owner)->getJson(route('comments.index', [
            'alias' => 'planner-item', 'id' => $item->id,
        ]))->assertOk()->assertJsonPath('meta.total', 25)->assertJsonPath('meta.per_page', 20);
        $this->assertSame(array_slice($ids, 5), collect($pageOne->json('data'))->pluck('id')->all());

        $pageTwo = $this->getJson(route('comments.index', [
            'alias' => 'planner-item', 'id' => $item->id, 'page' => 2,
        ]))->assertOk();
        $this->assertSame(array_slice($ids, 0, 5), collect($pageTwo->json('data'))->pluck('id')->all());
    }

    public function test_activity_logs_use_bounded_excerpt_and_never_full_body(): void
    {
        [$owner, $item] = $this->fixture();
        $body = str_repeat('rahasia ', 30);
        $commentId = $this->createComment($owner, $item, $body);
        $log = ActivityLog::where('subject_type', Comment::class)->where('subject_id', $commentId)->firstOrFail();

        $this->assertSame('comment_created', $log->event);
        $this->assertLessThanOrEqual(100, mb_strlen($log->properties['excerpt']));
        $this->assertNotSame($body, $log->properties['excerpt']);
        $this->assertEqualsCanonicalizing(
            ['comment_id', 'target_id', 'target_alias', 'branch_id', 'excerpt'],
            array_keys($log->properties),
        );
    }

    /** @return array{User, ContentItem, Branch} */
    private function fixture(): array
    {
        $branch = Branch::create(['name' => 'Cabang Komentar', 'code' => 'KOM']);
        $owner = $this->user('manager', $branch);
        $item = ContentItem::create([
            'branch_id' => $branch->id,
            'title' => 'Target komentar',
            'item_type' => 'task',
            'visibility' => 'team',
            'scheduled_date' => today(),
            'status' => 'todo',
            'created_by' => $owner->id,
        ]);

        return [$owner, $item, $branch];
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }

    private function createComment(User $user, ContentItem $item, string $body, ?int $parentId = null): int
    {
        return (int) $this->actingAs($user)->postJson(route('comments.store'), array_filter([
            'alias' => 'planner-item', 'id' => $item->id, 'body' => $body, 'parent_id' => $parentId,
        ], fn ($value) => $value !== null))->assertCreated()->json('data.id');
    }
}
