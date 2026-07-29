<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\Expense;
use App\Models\SalesLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\BuildsUniversalCommentFixtures;
use Tests\TestCase;

class UniversalCommentsPerformanceIntegrationTest extends TestCase
{
    use BuildsUniversalCommentFixtures, RefreshDatabase;

    public function test_pagination_counts_replies_and_query_count_is_bounded_as_threads_grow(): void
    {
        $branch = $this->commentBranch();
        $user = $this->commentUser('manager', $branch);
        $target = $this->commentPlanner($user, $branch);
        $parent = $target->comments()->create(['user_id' => $user->id, 'body' => 'Parent', 'body_plain' => 'Parent']);
        foreach (range(1, 3) as $number) {
            $target->comments()->create([
                'user_id' => $user->id, 'parent_id' => $parent->id, 'body' => 'Reply '.$number, 'body_plain' => 'Reply '.$number,
            ]);
        }
        foreach (range(1, 24) as $number) {
            $target->comments()->create(['user_id' => $user->id, 'body' => 'Top '.$number, 'body_plain' => 'Top '.$number]);
        }

        $url = route('comments.index', ['alias' => 'planner-item', 'id' => $target->id]);
        $response = $this->actingAs($user)->getJson($url)->assertOk()
            ->assertJsonPath('meta.total', 28)
            ->assertJsonPath('meta.top_level_total', 25)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.last_page', 2);
        $serializedParent = collect($response->json('data'))->firstWhere('id', $parent->id);
        if ($serializedParent) {
            $this->assertSame(3, $serializedParent['reply_count']);
            $this->assertCount(3, $serializedParent['replies']);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $largeThreadQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(12, $largeThreadQueries);

        $older = $this->getJson($url.'&page=2')->assertOk();
        $this->assertCount(5, $older->json('data'));
        $this->assertSame($parent->id, $older->json('data.0.id'));
        $this->assertSame(3, $older->json('data.0.reply_count'));
    }

    public function test_supported_models_and_module_lists_keep_comment_count_preloading_without_n_plus_one_accessors(): void
    {
        foreach ([SalesLead::class, ContentItem::class, Expense::class, DanaTalangan::class] as $model) {
            $this->assertTrue(method_exists($model, 'comments'), $model);
        }

        $controllers = [
            'SalesPocketbookController.php' => 2,
            'ContentCalendarController.php' => 1,
            'ExpenseController.php' => 1,
            'DanaTalanganController.php' => 1,
        ];
        foreach ($controllers as $controller => $minimum) {
            $source = file_get_contents(app_path('Http/Controllers/Crm/'.$controller));
            $this->assertGreaterThanOrEqual($minimum, substr_count($source, "withCount('comments')"), $controller);
        }

        $this->assertStringNotContainsString('getCommentsCountAttribute', file_get_contents(app_path('Models/ContentItem.php')));
        $this->assertStringNotContainsString('getCommentsCountAttribute', file_get_contents(app_path('Models/SalesLead.php')));
    }

    public function test_all_supported_module_surfaces_link_to_canonical_aliases_and_hide_links_without_permission(): void
    {
        $views = [
            'crm/sales-pocketbook/index.blade.php' => ['sales-lead', 'sales-agenda'],
            'crm/content-calendar/_item-card.blade.php' => ['planner-item'],
            'crm/expenses/index.blade.php' => ['expense'],
            'crm/dana-talangan/index.blade.php' => ['bridge-fund'],
        ];
        foreach ($views as $view => $aliases) {
            $source = file_get_contents(resource_path('views/'.$view));
            foreach ($aliases as $alias) {
                $this->assertStringContainsString($alias, $source, $view);
            }
        }

        $expenseView = file_get_contents(resource_path('views/crm/expenses/index.blade.php'));
        $this->assertStringContainsString("hasPermission('comments.view')", $expenseView);
        $this->assertStringNotContainsString('konsumen-progress', file_get_contents(app_path('Services/CommentableAccessService.php')));
    }

    public function test_comment_routes_do_not_replace_existing_exports_locks_or_notification_polling_contracts(): void
    {
        foreach (['expenses.export', 'sales-pocketbook.export', 'content-calendar.export', 'dana-talangan.export', 'notifications.index'] as $route) {
            $this->assertTrue(Route::has($route), $route);
        }
        foreach (['content-calendar.update-status', 'dana-talangan.update', 'sales-leads.update', 'expenses.update'] as $route) {
            $this->assertTrue(Route::has($route), $route);
        }

        $notificationMenu = file_get_contents(resource_path('views/components/crm/notification-menu.blade.php'));
        $notifications = file_get_contents(resource_path('js/notifications.js'));
        $this->assertStringContainsString("route('notifications.index')", $notificationMenu);
        $this->assertStringContainsString('setInterval', $notifications);
        $this->assertStringContainsString('60000', $notifications);
    }

    public function test_comment_schema_supports_lookup_pagination_and_only_one_reply_level(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_07_28_000022_create_universal_comment_tables.php'));
        $this->assertStringContainsString("\$table->morphs('commentable')", $migration);
        $this->assertStringContainsString("\$table->index('parent_id')", $migration);
        $this->assertStringContainsString("\$table->index('created_at')", $migration);
        $this->assertSame(1, substr_count($migration, "constrained('comments')"));

        $this->assertTrue(Route::has('comments.store'));
        $this->assertFalse(Route::has('comments.replies.store'));
        $this->assertTrue(class_exists(Comment::class));
    }
}
