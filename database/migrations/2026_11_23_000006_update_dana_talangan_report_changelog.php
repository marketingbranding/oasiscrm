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
                ['version' => null, 'title' => 'Perbaiki periode dan keamanan laporan Dana Talangan'],
                ['description' => 'Laporan memisahkan tanggal talangan dari tanggal pembayaran, menyediakan halaman laporan, validasi periode, scope pengguna, serta pencatatan skip bridge per cabang.', 'category' => 'fixed', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->whereNull('version')->where('title', 'Perbaiki periode dan keamanan laporan Dana Talangan')->delete();
        }
    }
};
