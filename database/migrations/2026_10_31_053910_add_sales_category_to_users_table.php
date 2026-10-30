<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Klasifikasi Sales Inhouse dan OJT';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('sales_category', 16)->nullable()->after('role_id');
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Administrator dapat mengklasifikasikan user Sales sebagai Inhouse atau OJT. Nilai kosong tetap dipertahankan untuk akun Sales lama dan klasifikasi ini tidak mengubah izin atau ruang kerja.',
                'category' => 'added',
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
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sales_category');
        });

        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
    }
};
