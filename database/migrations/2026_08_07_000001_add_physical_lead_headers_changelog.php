<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Sinkronisasi Lead Menggunakan Header Fisik Spreadsheet';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'fixed',
                'description' => 'Kontrak lead kini memakai header fisik tab lead (sumber_lead, kanal_masuk, aktivitas_lead) sehingga sinkronisasi antar cabang tidak lagi gagal karena mencari nama internal seperti source, platform, atau campaign_name.',
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
