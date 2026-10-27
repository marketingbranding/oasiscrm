<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Pembatasan Workspace Monitoring Supervisor'],
            [
                'category' => 'fixed',
                'description' => 'Monitoring dan detail lead Supervisor kini membatasi tim Sales pada cabang dan proyek workspace yang diizinkan; pemetaan historis hanya digunakan pada monitoring dan ekspor.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', 'Pembatasan Workspace Monitoring Supervisor')
            ->delete();
    }
};
