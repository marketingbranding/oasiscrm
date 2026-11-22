<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('sales_leads')->whereNull('sales_user_id')->exists()) {
            throw new RuntimeException('sales_lead owner NULL ditemukan; backfill owner wajib sebelum constraint dipulihkan.');
        }

        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropForeign(['sales_user_id']);
            $table->unsignedBigInteger('sales_user_id')->nullable(false)->change();
            $table->foreign('sales_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropForeign(['sales_user_id']);
            $table->unsignedBigInteger('sales_user_id')->nullable()->change();
            $table->foreign('sales_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
