<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\KonsumenProgressSheetRow;
use App\Models\KonsumenProgressSyncStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KonsumenProgressIndexTest extends TestCase
{
    use RefreshDatabase;

    private function branchAndUser(string $roleSlug = 'admin', bool $withSheet = true): array
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'is_superadmin' => $roleSlug === 'superadmin']);
        $branch = Branch::create([
            'name' => 'Jepara',
            'code' => 'JPR',
            'is_active' => true,
            'sheet_id' => $withSheet ? 'sheet-jepara' : null,
        ]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        return [$branch, $user];
    }

    private function pipelineCustomer(Branch $branch, string $idKavling, string $name, string $stage): void
    {
        KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'data_konsumen',
            'row_hash' => 'konsumen-'.$branch->id.'-'.$idKavling,
            'row_data' => ['id_kavling' => $idKavling, 'nama_konsumen' => $name, 'project_name' => 'Oasis Jepara'],
        ]);
        KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => $stage,
            'row_hash' => $stage.'-'.$branch->id.'-'.$idKavling,
            'row_data' => ['id_kavling' => $idKavling],
        ]);
    }

    public function test_index_renders_canonical_header_toolbar_and_sync_panel(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Budi Santoso', 'akad');

        $html = $this->actingAs($user)->get(route('konsumen-progress.index'))->getContent();

        $this->assertStringContainsString('Konsumen Progress', $html);
        $this->assertStringContainsString('crm-page-header', $html);
        $this->assertStringContainsString('crm-toolbar', $html);
        $this->assertStringContainsString('name="branch_id" value="'.$branch->id.'"', $html);
        $this->assertStringContainsString('Budi Santoso', $html);
    }

    public function test_stage_counts_match_pipeline_and_tabs_expose_pressed_state(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Budi Santoso', 'akad');
        $this->pipelineCustomer($branch, 'A-02', 'Siti Aminah', 'akad');
        $this->pipelineCustomer($branch, 'B-01', 'Joko Widodo', 'bast');

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));
        $response->assertOk();

        $pipeline = $response->viewData('pipeline');
        $this->assertCount(2, $pipeline['akad']);
        $this->assertCount(1, $pipeline['bast']);
        $this->assertCount(0, $pipeline['bi_checking']);

        $html = $response->getContent();
        $this->assertStringContainsString(':aria-pressed="stage === \'akad\'"', $html);
        $this->assertStringContainsString('aria-label="Lihat konsumen tahap Akad"', $html);
        $this->assertStringContainsString('role="group"', $html);
        $this->assertStringContainsString('aria-label="Tahap konsumen"', $html);
    }

    public function test_search_input_has_accessible_label(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Budi Santoso', 'akad');

        $html = $this->actingAs($user)->get(route('konsumen-progress.index'))->getContent();

        $this->assertStringContainsString('aria-label="Cari konsumen progress berdasarkan nama atau kavling"', $html);
        $this->assertStringContainsString('window.__kpItems', $html);
    }

    public function test_sync_control_hidden_for_view_only_role(): void
    {
        [$branch, $user] = $this->branchAndUser('manager');
        $this->pipelineCustomer($branch, 'A-01', 'Budi Santoso', 'akad');

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));

        $response->assertOk()->assertDontSee('name="branch_id" value="'.$branch->id.'"', false);
    }

    public function test_empty_pipeline_shows_local_data_alert(): void
    {
        [$branch, $user] = $this->branchAndUser();

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));

        $response->assertOk()
            ->assertSee('Gagal memuat beberapa stage')
            ->assertSee('Data lokal belum tersedia. Klik Sync Sekarang terlebih dahulu.');
    }

    public function test_branch_without_sheet_shows_empty_state(): void
    {
        [$branch, $user] = $this->branchAndUser('admin', false);

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));

        $response->assertOk()->assertSee('Database branch belum tersedia.');
    }

    public function test_stage_json_endpoint_returns_canonical_stage_items(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Budi Santoso', 'akad');

        $response = $this->actingAs($user)->getJson(route('konsumen-progress.stage', [
            'branch_id' => $branch->id,
            'stage' => 'akad',
        ]));

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('count', 1);
        $this->assertSame('Budi Santoso', $response->json('items.0.nama'));
    }

    public function test_unauthorized_explicit_branch_denied_on_index(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $other = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true, 'sheet_id' => 'sheet-pati']);

        $this->actingAs($user)->get(route('konsumen-progress.index', ['branch_id' => $other->id]))->assertForbidden();
    }

    public function test_unauthorized_role_denied_index(): void
    {
        [$branch] = $this->branchAndUser();
        $sales = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales', 'is_superadmin' => false]);
        $user = User::factory()->create([
            'role_id' => $sales->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($user)->get(route('konsumen-progress.index'))->assertForbidden();
    }

    public function test_stale_sync_status_is_passed_to_view(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $this->pipelineCustomer($branch, 'A-01', 'Budi Santoso', 'akad');
        KonsumenProgressSyncStatus::create(['branch_id' => $branch->id, 'status' => 'success', 'finished_at' => now()->subMinutes(45)]);

        $response = $this->actingAs($user)->get(route('konsumen-progress.index'));

        $response->assertOk()->assertViewHas('isStale', true);
    }
}
