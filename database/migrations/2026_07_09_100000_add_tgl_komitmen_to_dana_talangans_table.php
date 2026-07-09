<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dana_talangans', function (Blueprint $table) {
            $table->date('tgl_komitmen')->nullable()->after('nama_marketing');
        });
    }

    public function down(): void
    {
        Schema::table('dana_talangans', function (Blueprint $table) {
            $table->dropColumn('tgl_komitmen');
        });
    }
};
