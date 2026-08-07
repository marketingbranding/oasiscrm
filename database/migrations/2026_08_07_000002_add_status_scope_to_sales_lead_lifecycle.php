<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sales_lead_lifecycle_sync_statuses';

    private const COMPOSITE_UNIQUE = 'lead_lifecycle_status_branch_scope_unique';

    private const PLAIN_BRANCH_INDEX = 'sales_lead_lifecycle_sync_statuses_branch_id_index';

    public function up(): void
    {
        $table = self::TABLE;

        if (! Schema::hasColumn($table, 'scope')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('scope', 40)->nullable()->default('lead')->after('branch_id');
            });
        }

        DB::table($table)->whereNull('scope')->update(['scope' => 'lead']);

        // Composite unique already present -> migration already completed. Idempotent short-circuit.
        if ($this->indexFor($table, ['branch_id', 'scope'], true) !== null) {
            return;
        }

        // MySQL requires branch_id to remain indexed for its foreign key at every ALTER point.
        // Ensure a plain (non-unique) branch_id index exists BEFORE dropping the old unique one.
        if ($this->indexFor($table, ['branch_id'], false) === null) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->index(['branch_id'], self::PLAIN_BRANCH_INDEX);
            });
        }

        $oldUnique = $this->indexFor($table, ['branch_id'], true);
        if ($oldUnique !== null) {
            Schema::table($table, function (Blueprint $blueprint) use ($oldUnique) {
                $blueprint->dropUnique($oldUnique);
            });
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unique(['branch_id', 'scope'], self::COMPOSITE_UNIQUE);
        });
    }

    public function down(): void
    {
        $table = self::TABLE;

        // If the composite unique is present, ensure another branch_id index exists
        // (plain or unique) before dropping it, so the foreign key is never left unsupported.
        $composite = $this->indexFor($table, ['branch_id', 'scope'], true);
        if ($composite !== null) {
            if ($this->indexFor($table, ['branch_id'], false) === null
                && $this->indexFor($table, ['branch_id'], true) === null) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->index(['branch_id'], self::PLAIN_BRANCH_INDEX);
                });
            }
            Schema::table($table, function (Blueprint $blueprint) use ($composite) {
                $blueprint->dropIndex($composite);
            });
        }

        // Restore the legacy unique branch_id index only where structurally possible.
        if ($this->indexFor($table, ['branch_id'], true) === null) {
            if ($this->indexFor($table, ['branch_id'], false) === null) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->index(['branch_id'], self::PLAIN_BRANCH_INDEX);
                });
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unique(['branch_id']);
            });
        }

        // A unique branch_id index now supports the foreign key, so a redundant plain
        // branch_id index can be removed safely.
        $plain = $this->indexFor($table, ['branch_id'], false);
        if ($plain !== null) {
            Schema::table($table, function (Blueprint $blueprint) use ($plain) {
                $blueprint->dropIndex($plain);
            });
        }

        if (Schema::hasColumn($table, 'scope')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('scope');
            });
        }
    }

    private function indexFor(string $table, array $columns, bool $unique): ?string
    {
        $wanted = $this->normaliseColumns($columns);

        foreach ($this->indexes($table) as $meta) {
            if (($meta['unique'] ?? false) === $unique
                && $this->normaliseColumns($meta['columns'] ?? []) === $wanted) {
                return $meta['name'];
            }
        }

        return null;
    }

    private function indexes(string $table): array
    {
        $out = [];
        foreach (Schema::getIndexes($table) as $key => $meta) {
            $out[] = [
                'name' => $meta['name'] ?? $key,
                'columns' => array_values(array_filter(array_map(fn ($column) => (string) $column, $meta['columns'] ?? []))),
                'unique' => (bool) ($meta['unique'] ?? false),
            ];
        }

        return $out;
    }

    private function normaliseColumns(array $columns): array
    {
        sort($columns);

        return $columns;
    }
};
