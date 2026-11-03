<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const TITLE = 'Rencana akhir pekan tersimpan langsung tanpa struk';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Rencana akhir pekan kini langsung tersimpan sebagai daftar tanpa melalui struk atau proses kirim ke Koordinator.',
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
