<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class HorizontalTabsContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_horizontal_tab_strips_use_the_shared_contract(): void
    {
        $views = [
            'layouts/crm.blade.php',
            'crm/database/index.blade.php',
            'crm/sales-pocketbook/index.blade.php',
            'crm/content-calendar/index.blade.php',
            'crm/konsumen-progress/index.blade.php',
            'crm/ai-chat/_widget.blade.php',
        ];

        foreach ($views as $view) {
            $source = file_get_contents(resource_path('views/'.$view));

            $this->assertStringContainsString('data-horizontal-tabs', $source, $view);
            $this->assertStringContainsString('crm-horizontal-tabs', $source, $view);
        }
    }

    public function test_shared_css_hides_scrollbars_without_disabling_horizontal_scrolling(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.crm-horizontal-tabs\s*\{[^}]*overflow-x:\s*auto;/s', $css);
        $this->assertMatchesRegularExpression('/\.crm-horizontal-tabs\s*\{[^}]*scrollbar-width:\s*none;/s', $css);
        $this->assertMatchesRegularExpression('/\.crm-horizontal-tabs\s*\{[^}]*-ms-overflow-style:\s*none;/s', $css);
        $this->assertMatchesRegularExpression('/\.crm-horizontal-tabs::\-webkit-scrollbar\s*\{[^}]*display:\s*none;/s', $css);
    }

    public function test_wheel_and_active_visibility_behavior_is_centralized(): void
    {
        $source = file_get_contents(resource_path('js/horizontal-tabs.js'));
        $app = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("'[aria-selected=\"true\"]'", $source);
        $this->assertStringContainsString("'[aria-current=\"page\"]'", $source);
        $this->assertStringContainsString("'[aria-pressed=\"true\"]'", $source);
        $this->assertStringContainsString("'[data-horizontal-tab-active=\"true\"]'", $source);
        $this->assertStringContainsString("strip.addEventListener('wheel'", $source);
        $this->assertStringContainsString('{ passive: false }', $source);
        $this->assertMatchesRegularExpression('/if \(!canMove\) return;\s+event\.preventDefault\(\);/', $source);
        $this->assertStringContainsString('keepActiveTabVisible(strip)', $source);
        $this->assertStringContainsString("import registerHorizontalTabs from './horizontal-tabs';", $app);
        $this->assertStringContainsString('registerHorizontalTabs();', $app);

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js')));
        $wheelListeners = [];
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'js') {
                continue;
            }

            if (str_contains(file_get_contents($file->getPathname()), "addEventListener('wheel'")) {
                $wheelListeners[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        $this->assertCount(1, $wheelListeners);
        $this->assertStringEndsWith('/resources/js/horizontal-tabs.js', $wheelListeners[0]);
    }

    public function test_existing_keyboard_and_aria_contracts_remain_intact(): void
    {
        $database = file_get_contents(resource_path('views/crm/database/index.blade.php'));
        $sales = file_get_contents(resource_path('views/crm/sales-pocketbook/index.blade.php'));
        $planner = file_get_contents(resource_path('views/crm/content-calendar/index.blade.php'));
        $progress = file_get_contents(resource_path('views/crm/konsumen-progress/index.blade.php'));

        $this->assertStringContainsString('role="tablist"', $database);
        $this->assertStringContainsString('role="tab"', $database);
        $this->assertStringContainsString('@keydown.right.prevent', $database);
        $this->assertStringContainsString('@keydown.left.prevent', $database);
        $this->assertStringContainsString('@keydown.home.prevent', $database);
        $this->assertStringContainsString('@keydown.end.prevent', $database);
        $this->assertStringContainsString('aria-current="page"', $sales);
        $this->assertStringContainsString('aria-current="page"', $planner);
        $this->assertStringContainsString('role="group"', $progress);
        $this->assertStringContainsString(':aria-pressed=', $progress);
    }

    public function test_horizontal_tabs_changelog_is_unique_and_visible(): void
    {
        $user = User::factory()->create(['password_changed_at' => now()]);

        $this->assertSame(1, app('db')->table('changelogs')
            ->whereNull('version')
            ->where('title', 'Navigasi Tab Lebih Mudah Digulir')
            ->count());

        $this->actingAs($user)->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText('Navigasi Tab Lebih Mudah Digulir');
    }
}
