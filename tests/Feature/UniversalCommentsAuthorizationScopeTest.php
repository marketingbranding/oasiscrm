<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Permission;
use App\Models\Role;
use App\Services\CommentableAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\BuildsUniversalCommentFixtures;
use Tests\TestCase;

class UniversalCommentsAuthorizationScopeTest extends TestCase
{
    use BuildsUniversalCommentFixtures, RefreshDatabase;

    public function test_endpoints_require_authentication_and_reject_invalid_targets_and_bodies(): void
    {
        $branch = $this->commentBranch();
        $user = $this->commentUser('manager', $branch);
        $target = $this->commentPlanner($user, $branch);

        $this->getJson(route('comments.index', ['alias' => 'planner-item', 'id' => $target->id]))->assertRedirect(route('login'));
        $this->postJson(route('comments.store'), [
            'alias' => 'planner-item', 'id' => $target->id, 'body' => 'Unauthenticated',
        ])->assertRedirect(route('login'));

        $this->actingAs($user)->postJson(route('comments.store'), [
            'alias' => 'konsumen-progress', 'id' => 1, 'body' => 'Unsupported',
        ])->assertUnprocessable()->assertJsonValidationErrors('alias');
        $this->postJson(route('comments.store'), [
            'alias' => 'planner-item', 'id' => 999999, 'body' => 'Missing',
        ])->assertNotFound();

        foreach ([null, '', " \n\t", str_repeat('x', 5001)] as $body) {
            $this->postJson(route('comments.store'), [
                'alias' => 'planner-item', 'id' => $target->id, 'body' => $body,
            ])->assertUnprocessable()->assertJsonValidationErrors('body');
        }

        $body = '<img src=x onerror=alert(1)> & "plain"';
        $created = $this->postJson(route('comments.store'), [
            'alias' => 'planner-item', 'id' => $target->id, 'body' => $body,
        ])->assertCreated()->assertJsonPath('data.body', $body);
        $this->assertSame($body, Comment::findOrFail($created->json('data.id'))->body_plain);
    }

    public function test_comment_permissions_are_supplemental_and_cannot_escalate_direct_target_access(): void
    {
        $branch = $this->commentBranch();
        $manager = $this->commentUser('manager', $branch);
        $target = $this->commentPlanner($manager, $branch);
        $comment = $target->comments()->create(['user_id' => $manager->id, 'body' => 'Scoped', 'body_plain' => 'Scoped']);

        $modulePermissions = Permission::query()->whereIn('slug', [
            'work_planner.view_assigned', 'work_planner.view_branch',
        ])->pluck('id');
        $manager->role->permissions()->detach($modulePermissions);
        $manager = $manager->fresh();

        $this->assertTrue($manager->hasPermission('comments.view'));
        $this->assertFalse(app(CommentableAccessService::class)->canView($manager, $target));
        $this->actingAs($manager)->get(route('comments.thread', ['alias' => 'planner-item', 'id' => $target->id]))->assertForbidden();
        $this->getJson(route('comments.index', ['alias' => 'planner-item', 'id' => $target->id]))->assertForbidden();
        $this->patchJson(route('comments.update', $comment), [
            'body' => 'Escalation denied', 'expected_lock_version' => 0,
        ])->assertForbidden();
    }

    public function test_sales_coordinator_manager_pusat_and_superadmin_follow_existing_sales_scope(): void
    {
        $branch = $this->commentBranch('Scoped Branch');
        $otherBranch = $this->commentBranch('Foreign Branch');
        $project = $this->commentProject($branch, 'Scoped Project');
        $otherProject = $this->commentProject($branch, 'Other Project');
        $foreignProject = $this->commentProject($otherBranch, 'Foreign Project');
        $coordinator = $this->commentUser('sales_coordinator', $branch);
        $sales = $this->commentUser('sales', $branch, ['supervisor_user_id' => $coordinator->id]);
        $peer = $this->commentUser('sales', $branch);
        $foreignSales = $this->commentUser('sales', $otherBranch);
        foreach ([[$coordinator, $project], [$sales, $project], [$peer, $otherProject], [$foreignSales, $foreignProject]] as [$user, $assignedProject]) {
            $this->assignCommentProject($user, $assignedProject);
        }

        $ownLead = $this->commentLead($sales, $project, 'Own lead');
        $peerLead = $this->commentLead($peer, $otherProject, 'Peer lead');
        $foreignLead = $this->commentLead($foreignSales, $foreignProject, 'Foreign lead');
        $access = app(CommentableAccessService::class);

        $this->assertTrue($access->canView($sales, $ownLead));
        $this->assertFalse($access->canView($sales, $peerLead));
        $this->assertTrue($access->canView($coordinator, $ownLead));
        $this->assertFalse($access->canView($coordinator, $peerLead));

        foreach (['manager', 'branch_manager'] as $role) {
            $manager = $this->commentUser($role, $branch);
            $this->assertTrue($access->canView($manager, $ownLead), $role);
            $this->assertTrue($access->canView($manager, $peerLead), $role);
            $this->assertFalse($access->canView($manager, $foreignLead), $role);
        }

        foreach (['pusat', 'superadmin'] as $role) {
            $global = $this->commentUser($role);
            $this->assertTrue($access->canView($global, $ownLead), $role);
            $this->assertTrue($access->canView($global, $foreignLead), $role);
        }
    }

    public function test_legacy_agenda_project_name_fallback_is_constrained_by_branch(): void
    {
        $branch = $this->commentBranch('Agenda Branch');
        $otherBranch = $this->commentBranch('Duplicate Branch');
        $project = $this->commentProject($branch, 'Duplicate Name');
        $this->commentProject($otherBranch, 'Duplicate Name');
        $sales = $this->commentUser('sales', $branch);
        $viewer = $this->commentUser('manager', $branch);
        $this->assignCommentProject($sales, $project);
        $this->assignCommentProject($viewer, $project);
        $agenda = $this->commentAgenda($sales, $branch, null, ['project_name' => 'Duplicate Name']);

        $this->assertTrue(app(CommentableAccessService::class)->canView($viewer, $agenda));

        $project->update(['project_name' => 'Renamed in correct branch']);
        $this->assertFalse(app(CommentableAccessService::class)->canView($viewer->fresh(), $agenda->fresh()));
    }

    public function test_permission_and_changelog_migrations_are_additive_idempotent_and_exactly_scoped(): void
    {
        $role = Role::where('slug', 'manager')->firstOrFail();
        $unrelated = Permission::where('slug', 'work_planner.export')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$unrelated->id]);

        $permissionsMigration = require database_path('migrations/2026_07_28_000023_add_universal_comment_permissions.php');
        $permissionsMigration->up();
        $this->assertTrue($role->fresh()->permissions()->whereKey($unrelated->id)->exists());

        $changelogMigration = require database_path('migrations/2026_07_28_000024_add_universal_comments_changelog.php');
        $changelogMigration->up();
        $changelogMigration->up();
        $title = 'Komentar terpadu pada data OASIS';
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', 'Penulisan komentar lebih stabil')->count());

        $source = file_get_contents(database_path('migrations/2026_07_28_000024_add_universal_comments_changelog.php'));
        $this->assertStringContainsString("DB::table('changelogs')->updateOrInsert", $source);
        $this->assertStringContainsString("whereNull('version')->where('title', self::TITLE)->delete()", $source);
        $this->actingAs($this->commentUser('pusat'))->get(route('changelogs.index'))->assertOk()
            ->assertSeeText($title)
            ->assertSeeText('Penulisan komentar lebih stabil');
    }
}
