<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Full Maintenance Mode untuk pemeliharaan OASIS';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Super Admin kini dapat memblokir sementara seluruh ruang kerja OASIS dengan halaman pemeliharaan yang aman, sementara akses bypass, alur pemulihan akun, dan proses terjadwal tetap berjalan.',
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
