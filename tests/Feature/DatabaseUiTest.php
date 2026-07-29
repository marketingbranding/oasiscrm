<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DatabaseSheetRecord;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DatabaseUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_renders_canonical_header_scope_toolbar_and_authorized_write_actions(): void
    {
        [$branch, $record] = $this->databaseRecord();
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'pusat')->firstOrFail()->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $this->mockSheetTitles($branch);

        $response = $this->actingAs($user)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertViewHas('canEdit', true);

        $html = $response->getContent();
        $this->assertStringContainsString('crm-page-header', $html);
        $this->assertStringContainsString('database-page-header', $html);
        $this->assertStringContainsString('aria-label="Ruang kerja Database"', $html);
        $this->assertStringContainsString('Cabang: Magelang', $html);
        $this->assertStringContainsString('canEdit: true', $html);
        $this->assertMatchesRegularExpression('/<th[^>]*>Aksi<\/th>/', $html);
        $this->assertStringContainsString('editRecord(rec)', $html);
        $this->assertStringContainsString('database/records/'.$record->id, route('database.records.update', $record));
    }

    public function test_database_hides_write_actions_from_view_only_user(): void
    {
        [$branch] = $this->databaseRecord();
        $role = Role::query()->create([
            'name' => 'Database Viewer',
            'slug' => 'database_viewer',
            'is_superadmin' => false,
            'is_active' => true,
        ]);
        $role->permissions()->sync(Permission::query()
            ->whereIn('slug', ['database.view', 'database.view_all'])
            ->pluck('id'));
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $this->mockSheetTitles($branch);

        $response = $this->actingAs($user)->get(route('database.index', ['branch_id' => $branch->id]))
            ->assertOk()
            ->assertViewHas('canEdit', false);

        $html = $response->getContent();
        $this->assertStringContainsString('canEdit: false', $html);
        $this->assertDoesNotMatchRegularExpression('/<th[^>]*>Aksi<\/th>/', $html);
        $this->assertStringNotContainsString('>Edit</button>', $html);
        $this->assertStringNotContainsString('>Hapus</button>', $html);
    }

    private function databaseRecord(): array
    {
        $branch = Branch::query()->create([
            'name' => 'Magelang',
            'code' => 'MGL',
            'is_active' => true,
            'sheet_id' => 'spreadsheet-id',
        ]);
        $record = DatabaseSheetRecord::query()->create([
            'branch_id' => $branch->id,
            'sheet_id' => $branch->sheet_id,
            'sheet_name' => 'Leads',
            'row_number' => 2,
            'headers' => ['id_kavling', 'nama_konsumen'],
            'row_data' => ['id_kavling' => 'A-01', 'nama_konsumen' => 'Siti'],
            'formula_columns' => [],
            'column_metadata' => [],
        ]);

        return [$branch, $record];
    }

    private function mockSheetTitles(Branch $branch): void
    {
        $google = Mockery::mock(GoogleSheetsApiService::class);
        $google->shouldReceive('sheetTitles')->once()->with($branch->sheet_id)->andReturn(['Leads']);
        $this->app->instance(GoogleSheetsApiService::class, $google);
    }
}
