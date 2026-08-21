<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Edit Aman Database Konsumen'],
            [
                'description' => 'Nama konsumen, nomor HP, keterangan, dan status cash kini dapat diperbarui langsung dengan validasi akses, deteksi konflik, dan audit perubahan.',
                'category' => 'added',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Edit Aman Database Konsumen')->delete();
    }
};
