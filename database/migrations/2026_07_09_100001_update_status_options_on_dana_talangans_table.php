<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('dana_talangans')
            ->where('status', 'aktif')
            ->orWhereNull('status')
            ->update(['status' => 'sanggup']);

        Schema::table('dana_talangans', function (Blueprint $table) {
            $table->string('status', 50)->default('sanggup')->change();
        });
    }

    public function down(): void
    {
        DB::table('dana_talangans')
            ->where('status', 'sanggup')
            ->update(['status' => 'aktif']);

        Schema::table('dana_talangans', function (Blueprint $table) {
            $table->string('status', 50)->default('aktif')->change();
        });
    }
};
