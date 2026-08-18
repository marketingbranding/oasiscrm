<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumer_applications', function (Blueprint $table) {
            $table->string('id_kavling')->nullable()->after('branch_id');
            $table->string('nama_konsumen')->nullable()->after('id_kavling');
            $table->text('nik')->nullable()->after('nama_konsumen');
        });

        Schema::table('consumer_legacy_identities', function (Blueprint $table) {
            $table->string('id_kons')->nullable()->after('external_key')->index();
            $table->string('id_psjb')->nullable()->after('id_kons')->index();
            $table->string('id_berkas')->nullable()->after('id_psjb')->index();
            $table->string('no_sp3k')->nullable()->after('id_berkas')->index();
            $table->string('id_ppjb_dev')->nullable()->after('no_sp3k')->index();
            $table->string('no_ppjb_akad')->nullable()->after('id_ppjb_dev')->index();
            $table->string('no_bast')->nullable()->after('no_ppjb_akad')->index();
        });

        Schema::table('consumer_stage_events', function (Blueprint $table) {
            $table->date('event_date')->nullable()->after('occurred_at');
            $table->text('notes')->nullable()->after('reason');
        });

        Schema::table('consumer_bank_processes', function (Blueprint $table) {
            $table->string('id_berkas')->nullable()->after('bank_name')->index();
            $table->string('no_sp3k')->nullable()->after('id_berkas')->index();
            $table->string('kc_unit')->nullable()->after('bank_name');
            $table->decimal('request_plafond', 15, 2)->nullable()->after('kc_unit');
            $table->integer('request_tenor')->nullable()->after('request_plafond');
            $table->decimal('approved_plafond', 15, 2)->nullable()->after('request_tenor');
            $table->integer('approved_tenor')->nullable()->after('approved_plafond');
            $table->string('response_type')->nullable()->after('approved_tenor');
            $table->string('revision_category')->nullable()->after('status');
            $table->text('revision_detail')->nullable()->after('revision_category');
            $table->string('obstacle')->nullable()->after('revision_detail');
            $table->text('notes')->nullable()->after('obstacle');
        });
    }

    public function down(): void
    {
        Schema::table('consumer_bank_processes', function (Blueprint $table) {
            $table->dropColumn(['id_berkas', 'no_sp3k', 'kc_unit', 'request_plafond', 'request_tenor', 'approved_plafond', 'approved_tenor', 'response_type', 'revision_category', 'revision_detail', 'obstacle', 'notes']);
        });

        Schema::table('consumer_stage_events', function (Blueprint $table) {
            $table->dropColumn(['event_date', 'notes']);
        });

        Schema::table('consumer_legacy_identities', function (Blueprint $table) {
            $table->dropColumn(['id_kons', 'id_psjb', 'id_berkas', 'no_sp3k', 'id_ppjb_dev', 'no_ppjb_akad', 'no_bast']);
        });

        Schema::table('consumer_applications', function (Blueprint $table) {
            $table->dropColumn(['id_kavling', 'nama_konsumen', 'nik']);
        });
    }
};
