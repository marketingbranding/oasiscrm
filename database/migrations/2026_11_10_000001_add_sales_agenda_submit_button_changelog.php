<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Tombol kirim struk agenda kembali tampil';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'fixed',
                'description' => 'Berkas JavaScript aplikasi yang rusak membuat panel struk agenda tidak terbuka sehingga tombol "Sobek & kirim ke Koordinator" tidak muncul. Struk rencana tepat 480 menit kembali dapat dikirim ke Koordinator tanpa perlu mengosongkan cache perangkat.',
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
            ->where('title', self::TITLE)
            ->delete();
    }
};
