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
            $table->foreignId('consumer_application_id')->nullable()->after('project_id')->constrained('consumer_applications')->nullOnDelete();
            $table->foreignId('kavling_id')->nullable()->after('consumer_application_id')->constrained('kavlings')->nullOnDelete();
            $table->string('id_transaksi', 80)->nullable()->after('kavling_id')->index();
            $table->string('id_psjb', 150)->nullable()->after('id_transaksi')->index();
        });

        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->updateOrInsert(
                ['version' => null, 'title' => 'Relasi Dana Talangan dan backfill aman'],
                [
                    'description' => 'Dana Talangan memiliki relasi Konsumen/Kavling dan perintah backfill dry-run yang hanya menerapkan pencocokan kuat.',
                    'category' => 'added',
                    'created_by' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->whereNull('version')->where('title', 'Relasi Dana Talangan dan backfill aman')->delete();
        }

        Schema::table('dana_talangans', function (Blueprint $table): void {
            $table->dropForeign(['consumer_application_id']);
            $table->dropForeign(['kavling_id']);
            $table->dropColumn(['consumer_application_id', 'kavling_id', 'id_transaksi', 'id_psjb']);
        });
    }
};
