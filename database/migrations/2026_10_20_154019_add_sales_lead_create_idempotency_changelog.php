<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Perbaikan Idempotensi Pembuatan Lead'],
            [
                'category' => 'fixed',
                'description' => 'Pengiriman ulang permintaan pembuatan Lead dengan identitas operasi yang sama tidak lagi membuat duplikasi atau mengulang side effect.',
                'created_by' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', 'Perbaikan Idempotensi Pembuatan Lead')
            ->delete();
    }
};
