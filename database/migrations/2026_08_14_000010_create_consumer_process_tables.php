<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumer_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('id_kavling');
            $table->string('nama_konsumen');
            $table->text('nik')->nullable(); // Encrypted NIK
            $table->timestamps();
        });

        Schema::create('consumer_legacy_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->string('id_kons')->nullable()->index();
            $table->string('id_psjb')->nullable()->index();
            $table->string('id_berkas')->nullable()->index();
            $table->string('no_sp3k')->nullable()->index();
            $table->string('id_ppjb_dev')->nullable()->index();
            $table->string('no_ppjb_akad')->nullable()->index();
            $table->string('no_bast')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('consumer_stage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->string('stage', 50)->index();
            $table->string('source_id', 100)->nullable();
            $table->date('event_date')->nullable();
            $table->string('status', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'stage', 'source_id'], 'consumer_stage_event_source_unique');
        });

        Schema::create('consumer_bank_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('consumer_applications')->cascadeOnDelete();
            $table->string('id_berkas')->nullable()->index();
            $table->string('no_sp3k')->nullable()->index();
            $table->string('bank')->nullable();
            $table->string('kc_unit')->nullable();
            $table->decimal('request_plafond', 15, 2)->nullable();
            $table->integer('request_tenor')->nullable();
            $table->decimal('approved_plafond', 15, 2)->nullable();
            $table->integer('approved_tenor')->nullable();
            $table->string('response_type')->nullable(); // jenis_respon
            $table->string('status')->nullable();
            $table->string('revision_category')->nullable();
            $table->text('revision_detail')->nullable();
            $table->string('obstacle')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'id_berkas', 'no_sp3k'], 'consumer_bank_process_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumer_bank_processes');
        Schema::dropIfExists('consumer_stage_events');
        Schema::dropIfExists('consumer_legacy_identities');
        Schema::dropIfExists('consumer_applications');
    }
};
