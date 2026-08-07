<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            [
                'version' => null,
                'title' => 'Buku Saku Sales: sinkronisasi lead terpisah dari siklus lengkap',
            ],
            [
                'category' => 'changed',
                'description' => 'Tombol sinkronisasi Buku Saku Sales kini hanya membaca, menulis, dan merekonsiliasi tab lead. Status sinkronisasi lead ditentukan oleh rekonsiliasi lead saja; tab data_konsumen, NUP, bi_checking, akad, data_sales, dan data_ceklok tidak lagi memengaruhi status atau laporan "perlu diperiksa" untuk Sales.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->where('title', 'Buku Saku Sales: sinkronisasi lead terpisah dari siklus lengkap')->delete();
    }
};
