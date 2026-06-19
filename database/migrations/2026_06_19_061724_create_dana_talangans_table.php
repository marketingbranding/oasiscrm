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
        Schema::create('dana_talangans', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->string('nama_konsumen', 255);
            $table->string('kav', 100)->nullable();
            $table->string('project_name', 255)->nullable();
            $table->boolean('pinjam_nama')->default(false);
            $table->string('pekerjaan', 255)->nullable();
            $table->string('status_perkawinan', 100)->nullable();
            $table->integer('umur')->nullable();
            $table->string('nama_marketing', 255)->nullable();
            $table->text('penyelesaian')->nullable();
            $table->boolean('konfirmasi_keuangan')->default(false);
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('status', 50)->default('aktif');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dana_talangans');
    }
};
