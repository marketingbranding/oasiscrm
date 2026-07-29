<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\CommentRevision;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\CommentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniversalCommentMentionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_deduplicates_valid_mentions_and_rejects_inactive_forged_and_out_of_scope_ids(): void
    {
        [$viewer, $target, $branch] = $this->fixture('manager');
        $valid = $this->user('manager', $branch, 'Valid User');
        $inactive = $this->user('manager', $branch, 'Inactive User');
        $inactive->forceFill(['is_active' => false])->save();
        $outsider = $this->user('manager', Branch::create(['name' => 'Outside', 'code' => 'OUT']), 'Outside User');

        $comment = app(CommentService::class)->create($viewer, $target, 'Dengan mention', null, [$valid->id, $valid->id]);
        $this->assertSame([$valid->id], $comment->mentions()->pluck('users.id')->all());
        $this->assertSame([$valid->id], $comment->getRelation('newMentionUsers')->pluck('id')->all());

        foreach ([$inactive->id, $outsider->id, 999999] as $forgedId) {
            $this->actingAs($viewer)->postJson(route('comments.store'), [
                'alias' => 'planner-item', 'id' => $target->id, 'body' => 'Ditolak',
                'mentioned_user_ids' => [$forgedId],
            ])->assertUnprocessable()->assertJsonPath(
                'errors.mentioned_user_ids.0',
                'Satu atau beberapa pengguna yang disebut tidak dapat mengakses data ini.',
            );
        }

        $this->actingAs($viewer)->postJson(route('comments.store'), [
            'alias' => 'planner-item', 'id' => $target->id, 'body' => '@Valid User tanpa ID',
        ])->assertCreated()->assertJsonPath('data.mentions', []);
        $duplicate = $this->postJson(route('comments.store'), [
            'alias' => 'planner-item', 'id' => $target->id, 'body' => 'Duplikat request',
            'mentioned_user_ids' => [$valid->id, $valid->id],
        ])->assertCreated();
        $this->assertSame([$valid->id], collect($duplicate->json('data.mentions'))->pluck('id')->all());
        $tooMany = $this->postJson(route('comments.store'), [
            'alias' => 'planner-item', 'id' => $target->id, 'body' => 'Terlalu banyak',
            'mentioned_user_ids' => range(1, 21),
        ])->assertUnprocessable();
        $this->assertArrayHasKey('mentioned_user_ids', $tooMany->json('errors'));
    }

    public function test_candidates_include_same_project_supervisor_descendants_and_apply_branch_and_global_scope(): void
    {
        $branch = Branch::create(['name' => 'Candidate Branch', 'code' => 'CB']);
        $otherBranch = Branch::create(['name' => 'Other Branch', 'code' => 'OB']);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Project Alpha', 'is_active' => true]);
        $supervisor = $this->user('supervisor', $branch, 'Direct Supervisor');
        $viewer = $this->user('sales', $branch, 'Viewer Sales', $supervisor);
        $report = $this->user('sales', $branch, 'Direct Report', $viewer);
        $descendant = $this->user('sales', $branch, 'Deep Descendant', $report);
        $projectPeer = $this->user('sales', $branch, 'Project Peer');
        $outsider = $this->user('sales', $otherBranch, 'Branch Outsider');
        foreach ([$viewer, $projectPeer] as $user) {
            $user->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);
        }
        $target = $this->planner($viewer, $branch, $project);

        $viewerIds = $this->mentionableIds($viewer, $target);
        $this->assertContains($viewer->id, $viewerIds);
        $this->assertContains($supervisor->id, $viewerIds);
        $this->assertContains($projectPeer->id, $viewerIds);
        $this->assertNotContains($outsider->id, $viewerIds);

        $supervisorIds = $this->mentionableIds($supervisor, $target);
        $this->assertContains($report->id, $supervisorIds);
        $this->assertContains($descendant->id, $supervisorIds);

        $branchManager = $this->user('branch_manager', $branch, 'Branch Manager');
        $this->assertContains($projectPeer->id, $this->mentionableIds($branchManager, $target));
        $this->assertNotContains($outsider->id, $this->mentionableIds($branchManager, $target));

        foreach (['pusat', 'superadmin'] as $role) {
            $global = $this->user($role, null, ucfirst($role));
            $this->assertContains($projectPeer->id, $this->mentionableIds($global, $target));
        }
    }

    public function test_autocomplete_is_case_insensitive_bounded_safe_and_authorized_for_the_target(): void
    {
        [$viewer, $target, $branch] = $this->fixture('branch_manager');
        foreach (range(1, 14) as $number) {
            $this->user('manager', $branch, sprintf('Alpha User %02d', $number));
        }

        $response = $this->actingAs($viewer)->getJson(route('comments.mentionable-users', [
            'alias' => 'planner-item', 'id' => $target->id, 'query' => 'aLpHa',
        ]))->assertOk()->assertJsonCount(10, 'data');
        $this->assertCount(10, $response->json('data'));
        foreach ($response->json('data') as $candidate) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'role', 'context', 'initials'], array_keys($candidate));
            $this->assertArrayNotHasKey('email', $candidate);
        }

        $foreignViewer = $this->user('manager', Branch::create(['name' => 'Foreign', 'code' => 'FOR']));
        $this->actingAs($foreignViewer)->getJson(route('comments.mentionable-users', [
            'alias' => 'planner-item', 'id' => $target->id,
        ]))->assertForbidden();
        $this->actingAs($viewer)->getJson(route('comments.mentionable-users', [
            'alias' => 'planner-item', 'id' => 999999,
        ]))->assertNotFound();
        $this->getJson(route('comments.mentionable-users', [
            'alias' => 'planner-item', 'id' => $target->id, 'query' => str_repeat('x', 101),
        ]))->assertUnprocessable()->assertJsonPath('errors.query.0', 'The query field must not be greater than 100 characters.');
    }

    public function test_candidate_with_organizational_visibility_but_without_target_access_is_rejected(): void
    {
        $branch = Branch::create(['name' => 'Private Branch', 'code' => 'PB']);
        $viewer = $this->user('pusat', null, 'Global Viewer');
        $candidate = $this->user('manager', $branch, 'Cannot See Private');
        $target = $this->planner($viewer, $branch, null, 'personal');

        $this->assertNotContains($candidate->id, $this->mentionableIds($viewer, $target));
        $this->actingAs($viewer)->postJson(route('comments.store'), [
            'alias' => 'planner-item', 'id' => $target->id, 'body' => 'Forged target access',
            'mentioned_user_ids' => [$candidate->id],
        ])->assertUnprocessable()->assertJsonPath(
            'errors.mentioned_user_ids.0',
            'Satu atau beberapa pengguna yang disebut tidak dapat mengakses data ini.',
        );
    }

    public function test_update_syncs_mentions_tracks_delta_and_preserves_previous_ids_without_body_in_mention_activity(): void
    {
        [$viewer, $target, $branch] = $this->fixture('manager');
        $removed = $this->user('manager', $branch, 'Removed User');
        $unchanged = $this->user('manager', $branch, 'Unchanged User');
        $added = $this->user('manager', $branch, 'Added User');
        $service = app(CommentService::class);
        $comment = $service->create($viewer, $target, 'Rahasia awal', null, [$removed->id, $unchanged->id]);

        $updated = $service->update($comment, $viewer, 'Rahasia baru', 0, [$unchanged->id, $added->id]);
        $this->assertEqualsCanonicalizing([$unchanged->id, $added->id], $updated->mentions()->pluck('users.id')->all());
        $this->assertSame([$added->id], $updated->getRelation('newMentionUsers')->pluck('id')->all());
        $this->assertSame([$unchanged->id], $updated->getRelation('unchangedMentionUsers')->pluck('id')->all());
        $this->assertSame([$removed->id], $updated->getRelation('removedMentionUsers')->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$removed->id, $unchanged->id],
            CommentRevision::where('comment_id', $comment->id)->firstOrFail()->previous_mentioned_user_ids,
        );

        $mentionLogs = ActivityLog::where('subject_id', $comment->id)
            ->whereIn('event', ['mention_added', 'mention_removed'])->get();
        $this->assertCount(4, $mentionLogs);
        $this->assertSame(3, $mentionLogs->where('event', 'mention_added')->count());
        $this->assertSame(1, $mentionLogs->where('event', 'mention_removed')->count());
        foreach ($mentionLogs as $log) {
            $this->assertArrayHasKey('mentioned_user_id', $log->properties);
            $this->assertArrayNotHasKey('excerpt', $log->properties);
            $this->assertStringNotContainsString('Rahasia', json_encode($log->properties));
        }

        $this->actingAs($viewer)->deleteJson(route('comments.destroy', $comment->id), ['expected_lock_version' => 1])
            ->assertOk()->assertJsonPath('data.mentions', []);
    }

    /** @return array{User, ContentItem, Branch} */
    private function fixture(string $role): array
    {
        $branch = Branch::create(['name' => 'Mention Branch', 'code' => 'MENT']);
        $viewer = $this->user($role, $branch, 'Mention Viewer');

        return [$viewer, $this->planner($viewer, $branch), $branch];
    }

    private function planner(User $creator, Branch $branch, ?LeadMaster $project = null, string $visibility = 'team'): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $branch->id,
            'sales_project_id' => $project?->id,
            'project_name' => $project?->project_name,
            'title' => 'Mention target',
            'item_type' => 'task',
            'visibility' => $visibility,
            'scheduled_date' => today(),
            'status' => 'todo',
            'created_by' => $creator->id,
        ]);
    }

    private function user(string $role, ?Branch $branch = null, ?string $name = null, ?User $supervisor = null): User
    {
        return User::factory()->create([
            'name' => $name ?? ucfirst($role),
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'branch_id' => $branch?->id,
            'supervisor_user_id' => $supervisor?->id,
            'password_changed_at' => now(),
        ]);
    }

    /** @return array<int> */
    private function mentionableIds(User $viewer, ContentItem $target): array
    {
        return collect($this->actingAs($viewer)->getJson(route('comments.mentionable-users', [
            'alias' => 'planner-item', 'id' => $target->id,
        ]))->assertOk()->json('data'))->pluck('id')->all();
    }
}
