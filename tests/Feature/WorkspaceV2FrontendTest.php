<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WorkspaceV2FrontendTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_routes_return_not_found_when_feature_flag_is_disabled(): void
    {
        config(['app.workspace_v2_enabled' => false]);
        $user = $this->superadmin();

        $this->actingAs($user)
            ->get(route('workspace-v2.dashboard'))
            ->assertNotFound();
    }

    public function test_workspace_renders_required_navigation_without_standalone_sp3k_entry(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $user = $this->superadmin();

        $response = $this->actingAs($user)->get(route('workspace-v2.transactions.proses-bank'));
        $response->assertOk();
        $response->assertViewHas('activeProcessView', 'proses-bank');

        $response->assertSeeText('Transaksi Konsumen')
            ->assertSeeText('BI Checking')
            ->assertSeeText('Proses Bank')
            ->assertSeeText('PPJB Dev')
            ->assertDontSeeText('SP3K');
        $this->assertFalse(Route::has('workspace-v2.transactions.sp3k'));
    }

    public function test_workspace_exposes_all_process_views_as_views_of_one_transaction_workspace(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $user = $this->superadmin();
        $routes = [
            'workspace-v2.transactions',
            'workspace-v2.transactions.data-konsumen',
            'workspace-v2.transactions.psjb',
            'workspace-v2.transactions.bi-checking',
            'workspace-v2.transactions.pemberkasan',
            'workspace-v2.transactions.proses-bank',
            'workspace-v2.transactions.ppjb-dev',
            'workspace-v2.transactions.akad',
            'workspace-v2.transactions.bast',
        ];

        foreach ($routes as $routeName) {
            $this->actingAs($user)->get(route($routeName))->assertSeeText('Transaksi Konsumen');
        }
    }

    public function test_workspace_exposes_spreadsheet_aligned_data_consumer_form(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $user = $this->superadmin();

        $this->actingAs($user)
            ->get(route('workspace-v2.transactions.data-konsumen.create'))
            ->assertSee('name="nik"', false)
            ->assertSee('name="date_of_birth"', false)
            ->assertSee('name="emergency_contact_name"', false)
            ->assertSeeText('Data Konsumen');
    }

    public function test_workspace_supporting_areas_render_with_the_same_shell(): void
    {
        config(['app.workspace_v2_enabled' => true]);
        $user = $this->superadmin();

        foreach ([
            ['workspace-v2.dashboard', 'Ruang kerja hari ini'],
            ['workspace-v2.lead', 'Lead'],
            ['workspace-v2.nup', 'NUP / Waiting List'],
            ['workspace-v2.mundur', 'Mundur'],
            ['workspace-v2.kendala', 'Kendala'],
            ['workspace-v2.garansi', 'Garansi'],
            ['workspace-v2.selesai', 'Selesai'],
            ['workspace-v2.activity', 'Aktivitas'],
            ['workspace-v2.reports', 'Laporan'],
            ['workspace-v2.settings', 'Pengaturan'],
        ] as [$routeName, $heading]) {
            $this->actingAs($user)->get(route($routeName))->assertOk()->assertSeeText($heading);
        }
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);
    }
}
