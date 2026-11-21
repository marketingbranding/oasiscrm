<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sales_agenda_day_submissions', 'is_free_day')) {
            return;
        }

        Schema::table('sales_agenda_day_submissions', function (Blueprint $table): void {
            $table->boolean('is_free_day')->default(false)->after('is_weekend');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sales_agenda_day_submissions', 'is_free_day')) {
            return;
        }

        Schema::table('sales_agenda_day_submissions', function (Blueprint $table): void {
            $table->dropColumn('is_free_day');
        });
    }
};
