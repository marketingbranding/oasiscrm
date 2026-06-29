<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dana_talangans', function (Blueprint $table) {
            $table->index(['status', 'konfirmasi_keuangan', 'tanggal']);
        });

        Schema::table('content_items', function (Blueprint $table) {
            $table->index(['status', 'scheduled_date']);
        });

        Schema::table('lead_master', function (Blueprint $table) {
            $table->index(['is_active', 'branch_id', 'project_name']);
        });



        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('dana_talangans', function (Blueprint $table) {
            $table->dropIndex(['status', 'konfirmasi_keuangan', 'tanggal']);
        });

        Schema::table('content_items', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_date']);
        });

        Schema::table('lead_master', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'branch_id', 'project_name']);
        });



        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id', 'created_at']);
        });
    }
};
