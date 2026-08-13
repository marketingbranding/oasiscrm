<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Superadmin Impersonation';

    private const DESCRIPTION = 'Superadmin kini dapat masuk sementara sebagai user lain untuk kebutuhan pengecekan dan QA tanpa mengetahui atau mengubah password user, dengan banner sesi dan audit aktivitas.';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => self::DESCRIPTION,
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
