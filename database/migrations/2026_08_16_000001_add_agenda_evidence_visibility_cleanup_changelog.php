<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Bukti Agenda Sales pada monitoring'],
            ['description' => 'Monitoring Agenda Sales menampilkan bukti foto sesuai scope. Superadmin dapat membersihkan bukti lokal yang belum diarsipkan dengan alasan tercatat.', 'category' => 'fixed', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Bukti Agenda Sales pada monitoring')->delete();
    }
};
