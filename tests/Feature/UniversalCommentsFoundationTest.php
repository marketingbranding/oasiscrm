<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Comment;
use App\Models\CommentMention;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\Expense;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\CommentableAccessService;
use App\Support\PermissionCatalog;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UniversalCommentsFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_comment_schema_and_explicit_relations_persist_canonical_model_class(): void
    {
        foreach (['commentable_type', 'commentable_id', 'user_id', 'parent_id', 'body', 'body_plain', 'edited_at', 'lock_version', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('comments', $column), $column);
        }
        $this->assertTrue(Schema::hasTable('comment_mentions'));

        [$user, $branch, $project] = $this->salesFixture();
        $agenda = $this->agenda($user, $branch, $project);
        $parent = $agenda->comments()->create(['user_id' => $user->id, 'body' => '<p>Induk</p>', 'body_plain' => 'Induk']);
        $reply = $parent->replies()->create([
            'commentable_type' => ContentItem::class,
            'commentable_id' => $agenda->id,
            'user_id' => $user->id,
            'body' => 'Balasan',
        ]);
        $parent->mentions()->attach($user->id);

        $this->assertSame(ContentItem::class, $parent->commentable_type);
        $this->assertTrue($parent->commentable->is($agenda));
        $this->assertTrue($reply->parent->is($parent));
        $this->assertTrue($parent->mentions->first()->is($user));
        $this->assertInstanceOf(CommentMention::class, $parent->mentionRecords->first());
        $this->assertInstanceOf(MorphMany::class, (new SalesLead)->comments());
        $this->assertInstanceOf(MorphMany::class, (new ContentItem)->comments());
        $this->assertInstanceOf(MorphMany::class, (new Expense)->comments());
        $this->assertInstanceOf(MorphMany::class, (new DanaTalangan)->comments());
    }

    public function test_registry_rejects_alias_injection_missing_ids_and_wrong_planner_subtypes(): void
    {
        [$user, $branch, $project] = $this->salesFixture();
        $agenda = $this->agenda($user, $branch, $project);
        $planner = ContentItem::create([
            'branch_id' => $branch->id,
            'title' => 'Tugas biasa',
            'item_type' => 'task',
            'visibility' => 'team',
            'scheduled_date' => today(),
            'status' => 'todo',
            'created_by' => $user->id,
        ]);
        $access = app(CommentableAccessService::class);

        $this->assertTrue($access->resolve('sales-agenda', $agenda->id)?->is($agenda));
        $this->assertTrue($access->resolve('planner-item', $planner->id)?->is($planner));
        $this->assertNull($access->resolve('planner-item', $agenda->id));
        $this->assertNull($access->resolve('sales-agenda', $planner->id));
        $this->assertNull($access->resolve(ContentItem::class, $planner->id));
        $this->assertNull($access->resolve('konsumen-progress', 1));
        $this->assertNull($access->resolve('sales-lead', 999999));
        $this->assertSame('sales-agenda', $access->canonicalExternalAlias($agenda));
        $this->assertSame('planner-item', $access->canonicalExternalAlias($planner));
        $this->assertSame(route('comments.thread', ['alias' => 'sales-agenda', 'id' => $agenda->id]).'#comments', $access->targetUrl($agenda, '#comments'));
    }

    public function test_comment_policy_requires_both_comment_permission_and_target_access(): void
    {
        [$owner, $branch, $project] = $this->salesFixture();
        $other = $this->user('sales', Branch::create(['name' => 'Lain', 'code' => 'LAIN']));
        $agenda = $this->agenda($owner, $branch, $project);
        $comment = $agenda->comments()->create(['user_id' => $owner->id, 'body' => 'Catatan']);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $comment));
        $this->assertTrue(Gate::forUser($owner)->allows('create', [Comment::class, $agenda]));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $comment));
        $this->assertFalse(Gate::forUser($other)->allows('view', $comment));
        $this->assertFalse(Gate::forUser($other)->allows('create', [Comment::class, $agenda]));

        $owner->role->permissions()->detach(DB::table('permissions')->where('slug', 'comments.create')->value('id'));
        $this->assertFalse(Gate::forUser($owner->fresh())->allows('create', [Comment::class, $agenda]));
    }

    public function test_bridge_fund_target_honors_permission_and_workspace_branch_scope_and_soft_deletes(): void
    {
        $branch = Branch::create(['name' => 'Cabang A', 'code' => 'CA']);
        $otherBranch = Branch::create(['name' => 'Cabang B', 'code' => 'CB']);
        $viewer = $this->user('staff', $branch);
        $creator = $this->user('admin', $otherBranch);
        $fund = DanaTalangan::create([
            'tanggal' => today(),
            'nama_konsumen' => 'Konsumen B',
            'branch_id' => $otherBranch->id,
            'status' => 'aktif',
            'created_by' => $creator->id,
        ]);
        $access = app(CommentableAccessService::class);

        $this->assertFalse($access->canView($viewer, $fund));
        $this->assertTrue($access->canView($creator, $fund));
        $fund->delete();
        $this->assertFalse($access->canView($creator, $fund));
        $this->assertNull($access->resolve('bridge-fund', $fund->id));
    }

    public function test_comment_permissions_are_exact_and_deployed_as_additions(): void
    {
        $generic = [
            'comments.view', 'comments.create', 'comments.reply', 'comments.update_own',
            'comments.delete_own', 'comments.moderate', 'comments.view_history', 'comments.mention',
        ];
        $this->assertEqualsCanonicalizing($generic, collect(PermissionCatalog::permissions())
            ->pluck('slug')->filter(fn (string $slug) => str_starts_with($slug, 'comments.'))->values()->all());

        foreach (['sales', 'sales_coordinator', 'supervisor', 'manager', 'branch_manager', 'pusat', 'admin', 'staff'] as $role) {
            $expected = array_values($role === 'pusat'
                ? array_diff($generic, ['comments.moderate'])
                : array_diff($generic, ['comments.moderate', 'comments.view_history']));
            $actual = Role::where('slug', $role)->firstOrFail()->permissions()->where('slug', 'like', 'comments.%')->pluck('slug')->all();
            $this->assertEqualsCanonicalizing($expected, $actual, $role);
        }

        $superadmin = Role::where('slug', 'superadmin')->firstOrFail()->permissions()->where('slug', 'like', 'comments.%')->pluck('slug')->all();
        $this->assertEqualsCanonicalizing($generic, $superadmin);
    }

    public function test_universal_comment_changelog_is_unique_and_visible(): void
    {
        $title = 'Komentar terpadu pada data OASIS';
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', $title)->count());

        $this->actingAs($this->user('pusat'))->get(route('changelogs.index'))->assertOk()->assertSeeText($title);
    }

    /** @return array{User, Branch, LeadMaster} */
    private function salesFixture(): array
    {
        $branch = Branch::create(['name' => 'Cabang Sales', 'code' => 'SALES']);
        $user = $this->user('sales', $branch);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Proyek Sales', 'is_active' => true]);
        $user->assignedProjects()->attach($project->id, ['is_primary' => true, 'is_active' => true]);

        return [$user, $branch, $project];
    }

    private function agenda(User $owner, Branch $branch, LeadMaster $project): ContentItem
    {
        return ContentItem::create([
            'branch_id' => $branch->id,
            'project_name' => $project->project_name,
            'item_type' => 'agenda',
            'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
            'visibility' => 'personal',
            'title' => 'Agenda sales',
            'scheduled_date' => today(),
            'status' => 'planned',
            'owner_user_id' => $owner->id,
            'sales_project_id' => $project->id,
            'created_by' => $owner->id,
        ]);
    }

    private function user(string $role, ?Branch $branch = null): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $role)->firstOrFail()->id,
            'branch_id' => $branch?->id,
            'password_changed_at' => now(),
        ]);
    }
}
