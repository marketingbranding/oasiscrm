<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Rekonsiliasi Lite Marison V2';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            ['category' => 'added', 'description' => 'Admin dengan lingkup Progress Konsumen kini dapat mencocokkan transaksi Marison V2 dengan Customer dan menerapkan keputusan Lanjut, Mundur, Pindah Kavling, Reject, atau Selesai melalui lifecycle kavling OASIS.', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
    }
};
