<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumer_bank_processes', function (Blueprint $table): void {
            $table->date('tanggal_terima_bank')->nullable();
            $table->string('tipe_pemberkasan')->nullable();
        });

        Schema::create('consumer_ppjb_developers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consumer_application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->foreignId('consumer_stage_event_id')->nullable()->constrained('consumer_stage_events')->nullOnDelete();
            $table->date('tanggal_sp3k')->nullable();
            $table->date('tanggal_ttd_ppjb')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('consumer_akad_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consumer_application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->foreignId('consumer_stage_event_id')->nullable()->constrained('consumer_stage_events')->nullOnDelete();
            $table->date('tanggal_akad')->nullable();
            $table->string('kualitas_akad')->nullable();
            $table->string('status_bangunan')->nullable();
            $table->string('status_dp_konsumen')->nullable();
            $table->string('status_utilitas')->nullable();
            $table->string('status_konsumen')->nullable();
            $table->text('keterangan_terlambat')->nullable();
            $table->timestamps();
        });

        Schema::create('consumer_bast_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consumer_application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->foreignId('consumer_stage_event_id')->nullable()->constrained('consumer_stage_events')->nullOnDelete();
            $table->date('tanggal_bast')->nullable();
            $table->timestamps();
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Penyederhanaan alur operasional konsumen lokal'],
            ['description' => 'Menambahkan Pemberkasan, Proses Bank, PPJB Developer, Akad, dan BAST dengan histori konsumen serta riwayat kavling yang tetap dipertahankan. ID teknis tidak dibebankan kepada pengguna operasional.', 'category' => 'added', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Penyederhanaan alur operasional konsumen lokal')->delete();
        Schema::dropIfExists('consumer_bast_records');
        Schema::dropIfExists('consumer_akad_records');
        Schema::dropIfExists('consumer_ppjb_developers');
        Schema::table('consumer_bank_processes', function (Blueprint $table): void {
            $table->dropColumn(['tanggal_terima_bank', 'tipe_pemberkasan']);
        });
    }
};
