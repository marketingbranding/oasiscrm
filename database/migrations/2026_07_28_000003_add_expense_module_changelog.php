<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Pengelolaan Kategori Pengeluaran';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Oasis kini memiliki dasar pencatatan pengeluaran dan daftar kategori baku. Superadmin dapat mengatur nama, urutan, serta status kategori tanpa mengubah kode tetap yang menjaga riwayat data tetap konsisten.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
    }
};
