<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropForeign(['sales_user_id']);
            $table->unsignedBigInteger('sales_user_id')->nullable()->change();
            $table->foreign('sales_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table): void {
            $table->dropForeign(['sales_user_id']);
            $table->unsignedBigInteger('sales_user_id')->nullable(false)->change();
            $table->foreign('sales_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }
};
