<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TITLE = 'Perlindungan Scope Relasi Konsumen';

    public function up(): void
    {
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Perubahan peran tidak dapat dilakukan melalui sesi impersonasi, dan relasi Sales PIC, promo, BAST, serta PIC kendala divalidasi terhadap scope transaksi konsumen.',
                'category' => 'fixed',
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
