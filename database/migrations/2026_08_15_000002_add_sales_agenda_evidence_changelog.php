<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private string $title = 'Bukti Foto Agenda Sales';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(['version' => null, 'title' => $this->title], ['description' => 'Agenda Sales kini mendukung bukti foto opsional yang otomatis dikompres untuk menghemat penyimpanan. Bukti dapat diarsipkan mingguan dalam ZIP dan file lokal yang telah terverifikasi dapat dipurge setelah 60 hari oleh Superadmin.', 'category' => 'added', 'created_by' => null, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', $this->title)->delete();
    }
};
