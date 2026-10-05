<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Workspace Organisasi Berbasis Area Unit'],
            [
                'description' => 'Workspace organisasi menggunakan area Pusat, Cabang, dan Belum ditempatkan untuk mengurangi kepadatan diagram. User dapat dipindahkan atau dikeluarkan dari struktur tanpa menghapus akun.',
                'category' => 'changed',
                'created_by' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', 'Workspace Organisasi Berbasis Area Unit')
            ->delete();
    }
};
