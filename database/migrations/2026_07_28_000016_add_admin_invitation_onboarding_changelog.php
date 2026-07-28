<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Onboarding dan pengelolaan akses pengguna';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Admin kini membuat akun tanpa menentukan kata sandi, mengirim undangan aktivasi, mengatur penugasan organisasi sesuai kewenangan, dan mengelola status serta pemulihan akses dengan aman.',
                'category' => 'changed', 'created_by' => null, 'updated_at' => now(), 'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
    }
};
