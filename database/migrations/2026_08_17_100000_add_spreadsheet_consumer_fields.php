<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->text('nik_encrypted')->nullable()->after('phone');
            $table->date('date_of_birth')->nullable()->after('nik_encrypted');
            $table->string('occupation')->nullable()->after('date_of_birth');
            $table->string('occupation_detail')->nullable()->after('occupation');
            $table->text('address')->nullable()->after('occupation_detail');
            $table->string('kelurahan')->nullable()->after('address');
            $table->string('kecamatan')->nullable()->after('kelurahan');
            $table->string('kabupaten_kota')->nullable()->after('kecamatan');
            $table->string('emergency_contact_name')->nullable()->after('kabupaten_kota');
            $table->string('emergency_contact_phone', 50)->nullable()->after('emergency_contact_name');
        });

        Schema::table('consumer_applications', function (Blueprint $table) {
            $table->boolean('status_cash')->nullable()->after('application_status');
            $table->text('notes')->nullable()->after('akad_date');
        });

        Schema::table('consumer_import_rows', function (Blueprint $table) {
            $table->text('sensitive_data')->nullable()->after('normalized_data');
        });
    }

    public function down(): void
    {
        Schema::table('consumer_import_rows', fn (Blueprint $table) => $table->dropColumn('sensitive_data'));
        Schema::table('consumer_applications', fn (Blueprint $table) => $table->dropColumn(['status_cash', 'notes']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['nik_encrypted', 'date_of_birth', 'occupation', 'occupation_detail', 'address', 'kelurahan', 'kecamatan', 'kabupaten_kota', 'emergency_contact_name', 'emergency_contact_phone']));
    }
};
