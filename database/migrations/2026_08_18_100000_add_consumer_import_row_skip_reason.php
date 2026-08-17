<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumer_import_rows', function (Blueprint $table) {
            $table->string('skip_reason', 30)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('consumer_import_rows', fn (Blueprint $table) => $table->dropColumn('skip_reason'));
    }
};
