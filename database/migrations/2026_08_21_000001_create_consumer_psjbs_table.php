<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_psjbs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consumer_application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->foreignId('consumer_stage_event_id')->nullable()->constrained('consumer_stage_events')->nullOnDelete();
            $table->string('id_kavling')->nullable();
            $table->string('id_kons')->nullable();
            $table->string('id_psjb')->unique();
            $table->date('tanggal_psjb');
            $table->string('nama_koordinator')->nullable();
            $table->string('nama_sales')->nullable();
            $table->decimal('harga_unit', 15, 2)->nullable();
            $table->date('tanggal_utj')->nullable();
            $table->decimal('utj', 15, 2)->nullable();
            $table->date('tanggal_dp_klt')->nullable();
            $table->decimal('dp_all_in', 15, 2)->nullable();
            $table->decimal('nominal_cicilan', 15, 2)->nullable();
            $table->unsignedInteger('jumlah_cicilan')->nullable();
            $table->decimal('luas_klt', 12, 2)->nullable();
            $table->decimal('harga_klt_m', 15, 2)->nullable();
            $table->decimal('harga_klt_total', 15, 2)->nullable();
            $table->string('cara_pembayaran')->nullable();
            $table->foreignId('promo_id')->nullable()->constrained('promos')->nullOnDelete();
            $table->string('status')->nullable();
            $table->text('keterangan')->nullable();
            $table->timestamps();
            $table->index(['consumer_application_id', 'tanggal_psjb']);
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Input manual Data Konsumen, BI Checking, dan PSJB'],
            [
                'description' => 'Menambahkan alur input manual Data Konsumen, BI Checking, dan PSJB tanpa ketergantungan Google Sheets.',
                'category' => 'added',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->where('version', null)->where('title', 'Input manual Data Konsumen, BI Checking, dan PSJB')->delete();
        Schema::dropIfExists('consumer_psjbs');
    }
};
