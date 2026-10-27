<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Penguatan Scope Supervisor dan Lead Historis';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'fixed',
                'description' => 'Monitoring dan ekspor Supervisor kini mengikuti irisan tim, cabang, dan proyek workspace; perubahan lead historis tidak dapat memindahkan pemilik atau cabang/proyek dan tidak membuka mutasi lifecycle.',
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
