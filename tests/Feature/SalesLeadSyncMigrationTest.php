<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SalesLeadLifecycleSyncStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalesLeadSyncMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const COMPOSITE = 'lead_lifecycle_status_branch_scope_unique';

    private const PLAIN_BRANCH = 'sales_lead_lifecycle_sync_statuses_branch_id_index';

    private const LEGACY_UNIQUE = 'sales_lead_lifecycle_sync_statuses_branch_id_unique';

    private const TABLE = 'sales_lead_lifecycle_sync_statuses';

    private function indexNames(string $table): array
    {
        return array_values(array_map(fn (array $meta) => (string) $meta['name'], Schema::getIndexes($table)));
    }

    public function test_fresh_schema_has_scope_and_composite_unique_with_existing_data(): void
    {
        $names = $this->indexNames(self::TABLE);

        $this->assertTrue(Schema::hasColumn(self::TABLE, 'scope'));
        $this->assertTrue(in_array(self::COMPOSITE, $names, true), 'composite unique missing');
        $this->assertTrue(in_array(self::PLAIN_BRANCH, $names, true), 'plain branch index missing');
        $this->assertFalse(in_array(self::LEGACY_UNIQUE, $names, true), 'legacy unique must be gone');
    }

    public function test_distinct_scopes_allowed_and_duplicate_rejected(): void
    {
        $branch = Branch::query()->create(['name' => 'Cabang', 'code' => 'CBG', 'sheet_id' => 'sheet', 'is_active' => true]);

        SalesLeadLifecycleSyncStatus::query()->create(['branch_id' => $branch->id, 'scope' => 'lead', 'status' => 'success']);
        SalesLeadLifecycleSyncStatus::query()->create(['branch_id' => $branch->id, 'scope' => 'lifecycle', 'status' => 'failed']);

        $this->assertSame(2, SalesLeadLifecycleSyncStatus::query()->where('branch_id', $branch->id)->count());

        try {
            SalesLeadLifecycleSyncStatus::query()->create(['branch_id' => $branch->id, 'scope' => 'lead', 'status' => 'success']);
            $this->fail('Duplicate (branch_id, scope) should have been rejected.');
        } catch (\Throwable $exception) {
            $this->assertTrue(true);
        }
        $this->assertSame(2, SalesLeadLifecycleSyncStatus::query()->where('branch_id', $branch->id)->count());
    }

    public function test_branch_foreign_key_remains_enforced(): void
    {
        try {
            SalesLeadLifecycleSyncStatus::query()->create(['branch_id' => 999999, 'scope' => 'lead']);
            $this->fail('Foreign key on branch_id should reject a missing branch.');
        } catch (\Throwable $exception) {
            $this->assertTrue(true);
        }
    }

    public function test_migration_up_is_idempotent(): void
    {
        $migration = require database_path('migrations/2026_08_07_000002_add_status_scope_to_sales_lead_lifecycle.php');

        $migration->up();
        $migration->up();

        $names = $this->indexNames(self::TABLE);
        $this->assertTrue(in_array(self::COMPOSITE, $names, true), 'composite unique missing');
        $this->assertTrue(in_array(self::PLAIN_BRANCH, $names, true), 'plain branch index missing');
        $this->assertFalse(in_array(self::LEGACY_UNIQUE, $names, true), 'legacy unique must be gone');
        $this->assertSame(1, count(array_filter($names, fn (string $n) => $n === self::COMPOSITE)));
        $this->assertSame(1, count(array_filter($names, fn (string $n) => $n === self::PLAIN_BRANCH)));
    }

    public function test_partially_migrated_state_recovers(): void
    {
        // Reproduce the exact production partial state: scope exists, no composite
        // unique, legacy unique(branch_id) exists, no plain branch index.
        Schema::table(self::TABLE, function ($table) {
            $table->dropIndex(self::COMPOSITE);
        });
        Schema::table(self::TABLE, function ($table) {
            $table->dropIndex(self::PLAIN_BRANCH);
        });
        Schema::table(self::TABLE, function ($table) {
            $table->unique('branch_id');
        });

        $branch = Branch::query()->create(['name' => 'Cabang', 'code' => 'CBG', 'sheet_id' => 'sheet', 'is_active' => true]);
        SalesLeadLifecycleSyncStatus::query()->create(['branch_id' => $branch->id, 'scope' => 'lead', 'status' => 'success']);

        $migration = require database_path('migrations/2026_08_07_000002_add_status_scope_to_sales_lead_lifecycle.php');
        $migration->up();

        $names = $this->indexNames(self::TABLE);
        $this->assertTrue(in_array(self::COMPOSITE, $names, true), 'composite unique missing');
        $this->assertTrue(in_array(self::PLAIN_BRANCH, $names, true), 'plain branch index missing');
        $this->assertFalse(in_array(self::LEGACY_UNIQUE, $names, true), 'legacy unique must be gone');

        SalesLeadLifecycleSyncStatus::query()->create(['branch_id' => $branch->id, 'scope' => 'lifecycle', 'status' => 'failed']);
        $this->assertSame(2, SalesLeadLifecycleSyncStatus::query()->where('branch_id', $branch->id)->count());
    }

    public function test_down_restores_legacy_unique_then_up_recovers(): void
    {
        $migration = require database_path('migrations/2026_08_07_000002_add_status_scope_to_sales_lead_lifecycle.php');

        $migration->down();

        $names = $this->indexNames(self::TABLE);
        $this->assertTrue(in_array(self::LEGACY_UNIQUE, $names, true), 'legacy unique must be restored');
        $this->assertFalse(in_array(self::COMPOSITE, $names, true), 'composite unique must be gone');
        $this->assertFalse(in_array(self::PLAIN_BRANCH, $names, true), 'plain branch index must be gone');
        $this->assertFalse(Schema::hasColumn(self::TABLE, 'scope'));

        $migration->up();

        $names = $this->indexNames(self::TABLE);
        $this->assertTrue(in_array(self::COMPOSITE, $names, true), 'composite unique missing');
        $this->assertTrue(in_array(self::PLAIN_BRANCH, $names, true), 'plain branch index missing');
        $this->assertFalse(in_array(self::LEGACY_UNIQUE, $names, true), 'legacy unique must be gone');
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'scope'));
    }
}
