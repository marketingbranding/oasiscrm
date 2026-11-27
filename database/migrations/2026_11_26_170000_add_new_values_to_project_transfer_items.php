<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('project_transfer_items', 'new_values')) {
            Schema::table('project_transfer_items', function (Blueprint $table): void {
                $table->json('new_values')->nullable()->after('old_values');
            });
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

        if (Schema::hasColumn('project_transfer_items', 'new_values')) {
            Schema::table('project_transfer_items', function (Blueprint $table): void {
                $table->dropColumn('new_values');
            });
        }
    }
};
