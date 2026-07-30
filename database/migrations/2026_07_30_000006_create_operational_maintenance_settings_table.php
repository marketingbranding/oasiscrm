<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('operational_maintenance_settings')) {
            Schema::create('operational_maintenance_settings', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->boolean('enabled')->default(false);
                $table->string('title', 160)->default('OASIS sedang dalam pemeliharaan');
                $table->text('message');
                $table->timestamp('estimated_end_at')->nullable();
                $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('disabled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('enabled_at')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->unsignedInteger('lock_version')->default(0);
                $table->timestamps();
            });
        }

        DB::table('operational_maintenance_settings')->insertOrIgnore([
            'id' => 'global',
            'enabled' => false,
            'title' => 'OASIS sedang dalam pemeliharaan',
            'message' => 'Kami sedang melakukan peningkatan sistem agar OASIS dapat digunakan dengan lebih baik. Data Anda tetap aman. Silakan coba kembali beberapa saat lagi.',
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_maintenance_settings');
    }
};
