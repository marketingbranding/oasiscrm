<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_agenda_day_submissions', function (Blueprint $table): void {
            $table->boolean('is_weekend')->default(false)->after('normal_minutes_at_submission');
        });
    }

    public function down(): void
    {
        Schema::table('sales_agenda_day_submissions', function (Blueprint $table): void {
            $table->dropColumn('is_weekend');
        });
    }
};
