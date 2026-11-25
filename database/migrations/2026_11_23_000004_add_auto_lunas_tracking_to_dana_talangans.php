<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dana_talangans', function (Blueprint $table): void {
            $table->boolean('status_auto_lunas')->default(false)->after('status');
            $table->string('status_before_auto_lunas', 50)->nullable()->after('status_auto_lunas');
        });

        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->updateOrInsert(
                ['version' => null, 'title' => 'Status Lunas Dana Talangan mengikuti pembayaran terverifikasi'],
                ['description' => 'Status Lunas otomatis hanya berlaku untuk nominal positif, menyimpan status manual sebelumnya, dan tidak menghapus bukti pembayaran saat soft delete.', 'category' => 'fixed', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::table('dana_talangans', function (Blueprint $table): void {
            $table->dropColumn(['status_auto_lunas', 'status_before_auto_lunas']);
        });
    }
};
