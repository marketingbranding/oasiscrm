<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniversalCommentsUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_thread_renders_plain_text_panel_with_count_and_back_navigation(): void
    {
        [$user, $item] = $this->fixture();
        $item->comments()->create(['user_id' => $user->id, 'body' => '<b>Teks</b>', 'body_plain' => '<b>Teks</b>']);

        $response = $this->actingAs($user)->get(route('comments.thread', ['alias' => 'planner-item', 'id' => $item->id]));

        $response->assertOk()
            ->assertSee('Diskusi Work Planner')
            ->assertSee('data-comments-panel', false)
            ->assertSee('data-commentable-type="planner-item"', false)
            ->assertSee('1 komentar')
            ->assertSee(route('content-calendar.index'), false);
    }

    public function test_comment_index_meta_includes_capabilities_target_and_all_live_comments(): void
    {
        [$user, $item] = $this->fixture();
        $parent = $item->comments()->create(['user_id' => $user->id, 'body' => 'Induk', 'body_plain' => 'Induk']);
        $item->comments()->create(['user_id' => $user->id, 'parent_id' => $parent->id, 'body' => 'Balasan', 'body_plain' => 'Balasan']);
        $deleted = $item->comments()->create(['user_id' => $user->id, 'body' => 'Hapus', 'body_plain' => 'Hapus']);
        $deleted->delete();

        $this->actingAs($user)->getJson(route('comments.index', ['alias' => 'planner-item', 'id' => $item->id]))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.top_level_total', 1)
            ->assertJsonPath('meta.can_create', true)
            ->assertJsonPath('meta.can_mention', true)
            ->assertJsonPath('meta.target.alias', 'planner-item')
            ->assertJsonPath('meta.target.id', $item->id);
    }

    public function test_panel_source_escapes_text_and_module_queries_preload_comment_counts(): void
    {
        $panel = file_get_contents(resource_path('views/components/comments/panel.blade.php'));
        $script = file_get_contents(resource_path('js/comments.js'));

        $this->assertStringNotContainsString('x-html', $panel);
        $this->assertStringContainsString('x-text="comment.body"', $panel);
        $this->assertStringContainsString('Ctrl/Cmd+Enter', $panel);
        $this->assertStringContainsString('Muat komentar sebelumnya', $panel);
        $this->assertStringContainsString('DIEDIT', $panel);
        $this->assertStringContainsString("event.key === 'ArrowDown'", $script);
        $this->assertStringContainsString("reloadLabel: 'Muat Ulang Komentar'", $script);

        foreach (['ExpenseController.php', 'SalesPocketbookController.php', 'ContentCalendarController.php', 'DanaTalanganController.php'] as $controller) {
            $this->assertStringContainsString('withCount(\'comments\')', file_get_contents(app_path('Http/Controllers/Crm/'.$controller)));
        }
        $this->assertStringNotContainsString("'konsumen-progress' =>", file_get_contents(app_path('Services/CommentableAccessService.php')));
    }

    /** @return array{User, ContentItem} */
    private function fixture(): array
    {
        $branch = Branch::create(['name' => 'Cabang UI Komentar', 'code' => 'KUI']);
        $user = User::factory()->create([
            'role_id' => Role::where('slug', 'manager')->firstOrFail()->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $item = ContentItem::create([
            'branch_id' => $branch->id,
            'title' => 'Target UI Komentar',
            'item_type' => 'task',
            'visibility' => 'team',
            'scheduled_date' => today(),
            'status' => 'todo',
            'created_by' => $user->id,
        ]);

        return [$user, $item];
    }
}
