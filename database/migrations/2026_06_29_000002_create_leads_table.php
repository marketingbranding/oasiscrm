<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('lead_daily');
        Schema::dropIfExists('lead_events');

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('id_lead')->unique();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('id_promo')->nullable();
            $table->date('tanggal_lead');
            $table->string('sumber');
            $table->string('platform');
            $table->string('campaign');
            $table->string('nama_konsumen');
            $table->string('no_hp')->nullable();
            $table->string('proyek');
            $table->string('sales_pic');
            $table->string('status_lead');
            $table->text('keterangan')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'tanggal_lead']);
            $table->index('status_lead');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
