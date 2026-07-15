<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('database_sheet_records', function (Blueprint $table) {
            $table->json('column_metadata')->nullable()->after('formula_columns');
        });
    }

    public function down(): void
    {
        Schema::table('database_sheet_records', function (Blueprint $table) {
            $table->dropColumn('column_metadata');
        });
    }
};
