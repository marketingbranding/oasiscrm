<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->updateOrInsert(
                ['version' => null, 'title' => 'Laporan dan sumber utama Dana Talangan per cabang'],
                ['description' => 'Menambahkan laporan scoped Dana Talangan, ekspor ringkasan tanpa data pribadi, serta flag sumber utama per cabang yang melewati bridge sheet setelah rollout diaktifkan.', 'category' => 'added', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')
                ->whereNull('version')
                ->where('title', 'Laporan dan sumber utama Dana Talangan per cabang')
                ->delete();
        }
    }
};
