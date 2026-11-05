<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Onboarding Buku Saku Sales dinonaktifkan sementara';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Onboarding lama Buku Saku Sales dinonaktifkan sementara agar Sales langsung masuk ke ruang kerja. Aktivasi akun tetap berjalan seperti biasa.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
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
            ->where('title', self::TITLE)
            ->delete();
    }
};
