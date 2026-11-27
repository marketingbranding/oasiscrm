<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oasis_migration_column_ownership')) {
            Schema::create('oasis_migration_column_ownership', function (Blueprint $table): void {
                $table->id();
                $table->string('migration_key', 255);
                $table->string('table_name', 255);
                $table->string('column_name', 255);
                $table->unique(['migration_key', 'table_name', 'column_name'], 'migration_column_ownership_unique');
            });
        }

        if (! Schema::hasColumn('project_transfer_items', 'new_values')) {
            Schema::table('project_transfer_items', function (Blueprint $table): void {
                $table->json('new_values')->nullable()->after('old_values');
            });
            DB::table('oasis_migration_column_ownership')->insert([
                'migration_key' => '2026_11_26_170000_add_new_values_to_project_transfer_items',
                'table_name' => 'project_transfer_items',
                'column_name' => 'new_values',
            ]);
        }

        if (! Schema::hasTable('changelogs')) {
            return;
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Pemulihan dan validasi cache pindah proyek'],
            [
                'category' => 'fixed',
                'description' => 'Memperkuat pemulihan batch, snapshot rollback, kepemilikan data Sheet, dan identitas cache setelah pemindahan proyek.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')
                ->whereNull('version')
                ->where('title', 'Pemulihan dan validasi cache pindah proyek')
                ->where('description', 'Memperkuat pemulihan batch, snapshot rollback, kepemilikan data Sheet, dan identitas cache setelah pemindahan proyek.')
                ->delete();
        }

        if (! Schema::hasTable('oasis_migration_column_ownership')) {
            return;
        }

        $owned = DB::table('oasis_migration_column_ownership')
            ->where('migration_key', '2026_11_26_170000_add_new_values_to_project_transfer_items')
            ->get();
        foreach ($owned as $column) {
            if (Schema::hasColumn($column->table_name, $column->column_name)) {
                Schema::table($column->table_name, function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column->column_name);
                });
            }
        }
        DB::table('oasis_migration_column_ownership')
            ->where('migration_key', '2026_11_26_170000_add_new_values_to_project_transfer_items')
            ->delete();
    }
};
