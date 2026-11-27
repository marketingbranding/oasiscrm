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

        if (! Schema::hasColumn('database_sheet_records', 'ownership_status')) {
            Schema::table('database_sheet_records', function (Blueprint $table): void {
                $table->string('ownership_status', 20)->nullable()->after('sync_status');
            });
            DB::table('oasis_migration_column_ownership')->insert([
                'migration_key' => '2026_11_27_120000_add_ownership_status_to_sheet_caches',
                'table_name' => 'database_sheet_records',
                'column_name' => 'ownership_status',
            ]);
        }

        if (! Schema::hasColumn('konsumen_progress_sheet_rows', 'ownership_status')) {
            Schema::table('konsumen_progress_sheet_rows', function (Blueprint $table): void {
                $table->string('ownership_status', 20)->nullable()->after('row_hash');
            });
            DB::table('oasis_migration_column_ownership')->insert([
                'migration_key' => '2026_11_27_120000_add_ownership_status_to_sheet_caches',
                'table_name' => 'konsumen_progress_sheet_rows',
                'column_name' => 'ownership_status',
            ]);
        }

        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->updateOrInsert(
                ['version' => null, 'title' => 'Arsip audit kepemilikan Sheet'],
                [
                    'category' => 'changed',
                    'description' => 'Menyimpan baris Sheet asing dan tidak terpetakan sebagai arsip audit tanpa memasukkannya ke data operasional.',
                    'created_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')
                ->whereNull('version')
                ->where('title', 'Arsip audit kepemilikan Sheet')
                ->where('description', 'Menyimpan baris Sheet asing dan tidak terpetakan sebagai arsip audit tanpa memasukkannya ke data operasional.')
                ->delete();
        }

        if (! Schema::hasTable('oasis_migration_column_ownership')) {
            return;
        }

        $ownership = DB::table('oasis_migration_column_ownership')
            ->where('migration_key', '2026_11_27_120000_add_ownership_status_to_sheet_caches')
            ->get();
        foreach ($ownership as $column) {
            if (Schema::hasColumn($column->table_name, $column->column_name)) {
                Schema::table($column->table_name, function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column->column_name);
                });
            }
        }
        DB::table('oasis_migration_column_ownership')
            ->where('migration_key', '2026_11_27_120000_add_ownership_status_to_sheet_caches')
            ->delete();
    }
};
