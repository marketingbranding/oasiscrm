<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('consumer_warranties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumer_application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->foreignId('consumer_bast_record_id')->nullable()->constrained('consumer_bast_records')->nullOnDelete();
            $table->string('status_komplain', 30)->nullable();
            $table->date('tgl_sales_ke_sam')->nullable();
            $table->date('tgl_sam_ke_sat')->nullable();
            $table->date('tgl_sat_ke_sam')->nullable();
            $table->date('tgl_sam_ke_sales')->nullable();
            $table->date('tgl_sales_ke_kons')->nullable();
            $table->text('detail_garansi')->nullable();
            $table->date('tanggal_selesai')->nullable();
            $table->string('status_garansi', 40)->nullable();
            $table->string('source', 50)->nullable();
            $table->string('source_id', 150)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['consumer_application_id', 'status_garansi']);
            $table->index(['consumer_application_id', 'tanggal_selesai']);
            $table->index(['source', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consumer_warranties');
    }
};
