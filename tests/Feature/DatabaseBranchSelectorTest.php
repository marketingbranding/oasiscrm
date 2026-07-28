<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseBranchSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_pusat_can_select_non_primary_database_branch_with_separate_get_and_sync_forms(): void
    {
        [$user, , $selected] = $this->pusatUser();
        $url = route('database.index', ['branch_id' => $selected->id]);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame((string) $selected->id, (string) ($query['branch_id'] ?? null));

        $response = $this->actingAs($user)->get($url)
            ->assertOk()
            ->assertViewHas('selectedBranchId', $selected->id)
            ->assertViewHas('selectedBranch', fn (Branch $branch) => $branch->is($selected));
        $html = $response->getContent();

        $selectorForm = $this->formByAction($html, 'GET', route('database.index'));
        $this->assertSame(1, substr_count($selectorForm, '<form'));
        $this->assertStringContainsString('name="branch_id"', $selectorForm);
        $this->assertStringContainsString('onchange="this.form.submit()"', $selectorForm);
        $this->assertMatchesRegularExpression('/<option value="'.preg_quote((string) $selected->id, '/').'" selected/', $selectorForm);
        $this->assertStringNotContainsString('method="POST"', $selectorForm);

        $syncForm = $this->formByAction($html, 'POST', route('database.sync'));
        $this->assertStringContainsString('name="branch_id" value="'.$selected->id.'"', $syncForm);
        $this->assertGreaterThan(strpos($html, $selectorForm) + strlen($selectorForm), strpos($html, $syncForm));
    }

    public function test_database_branch_selector_keeps_requested_option_selected_after_reload(): void
    {
        [$user, $primary, $selected] = $this->pusatUser();

        $html = $this->actingAs($user)
            ->get('/database?branch_id='.$selected->id)
            ->assertOk()
            ->getContent();
        $selectorForm = $this->formByAction($html, 'GET', route('database.index'));

        $this->assertMatchesRegularExpression('/<option value="'.preg_quote((string) $selected->id, '/').'" selected/', $selectorForm);
        $this->assertDoesNotMatchRegularExpression('/<option value="'.preg_quote((string) $primary->id, '/').'"[^>]* selected/', $selectorForm);
    }

    public function test_inaccessible_database_branch_returns_403(): void
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $primary = Branch::create(['name' => 'Kantor Pusat', 'code' => 'PST', 'is_active' => true]);
        $inaccessible = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $primary->id,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('database.index', ['branch_id' => $inaccessible->id]))
            ->assertForbidden();
    }

    public function test_missing_database_branch_id_falls_back_to_primary_branch(): void
    {
        [$user, $primary] = $this->pusatUser();

        $response = $this->actingAs($user)->get(route('database.index'))
            ->assertOk()
            ->assertViewHas('selectedBranchId', $primary->id)
            ->assertViewHas('selectedBranch', fn (Branch $branch) => $branch->is($primary));

        $selectorForm = $this->formByAction($response->getContent(), 'GET', route('database.index'));
        $this->assertMatchesRegularExpression('/<option value="'.preg_quote((string) $primary->id, '/').'"[^>]* selected/', $selectorForm);
    }

    public function test_konsumen_progress_selector_does_not_nest_sync_form(): void
    {
        [$user, , $selected] = $this->pusatUser();
        $html = $this->actingAs($user)
            ->get(route('konsumen-progress.index', ['branch_id' => $selected->id]))
            ->assertOk()
            ->getContent();

        $selectorForm = $this->formByAction($html, 'GET', route('konsumen-progress.index'));
        $syncForm = $this->formByAction($html, 'POST', route('konsumen-progress.sync'));

        $this->assertSame(1, substr_count($selectorForm, '<form'));
        $this->assertStringNotContainsString('method="POST"', $selectorForm);
        $this->assertStringContainsString('name="branch_id" value="'.$selected->id.'"', $syncForm);
    }

    private function formByAction(string $html, string $method, string $action): string
    {
        $pattern = '/<form(?=[^>]*method="'.preg_quote($method, '/').'\")(?=[^>]*action="'.preg_quote($action, '/').'\")[^>]*>.*?<\/form>/s';
        $this->assertSame(1, preg_match($pattern, $html, $matches), "Missing {$method} form for {$action}.");

        return $matches[0];
    }

    private function pusatUser(): array
    {
        $role = Role::firstOrCreate(['slug' => 'pusat'], ['name' => 'Pusat', 'is_superadmin' => false]);
        $primary = Branch::create(['name' => 'Kantor Pusat', 'code' => 'PST', 'is_active' => true]);
        $selected = Branch::create(['name' => 'Magelang', 'code' => 'MGL', 'is_active' => true]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $primary->id,
            'password_changed_at' => now(),
        ]);

        return [$user, $primary, $selected];
    }
}
