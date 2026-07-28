<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CATEGORIES = [
        ['name' => 'Iklan Digital', 'code' => 'iklan_digital'],
        ['name' => 'Event / Pameran', 'code' => 'event_pameran'],
        ['name' => 'Cetak dan Media Promosi', 'code' => 'cetak_media_promosi'],
        ['name' => 'Transportasi', 'code' => 'transportasi'],
        ['name' => 'Konsumsi', 'code' => 'konsumsi'],
        ['name' => 'Peralatan', 'code' => 'peralatan'],
        ['name' => 'Langganan dan Software', 'code' => 'langganan_software'],
        ['name' => 'Operasional Kantor', 'code' => 'operasional_kantor'],
        ['name' => 'Pemeliharaan', 'code' => 'pemeliharaan'],
        ['name' => 'Dana Talangan', 'code' => 'dana_talangan'],
        ['name' => 'Pengadaan', 'code' => 'pengadaan'],
        ['name' => 'Lainnya', 'code' => 'lainnya'],
    ];

    public function up(): void
    {
        foreach (self::CATEGORIES as $index => $category) {
            DB::table('expense_categories')->updateOrInsert(
                ['code' => $category['code']],
                [
                    'name' => $category['name'],
                    'is_active' => true,
                    'sort_order' => $index + 1,
                    'created_by' => null,
                    'updated_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'deleted_at' => null,
                ],
            );
        }
    }

    public function down(): void
    {
        $codes = array_column(self::CATEGORIES, 'code');

        DB::table('expense_categories')
            ->whereIn('code', $codes)
            ->whereNotIn('id', DB::table('expenses')->select('expense_category_id'))
            ->delete();
    }
};
