<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumer_psjbs', function (Blueprint $table): void {
            $table->dropUnique('consumer_psjbs_id_psjb_unique');
            $table->unique(['consumer_application_id', 'id_psjb'], 'consumer_psjbs_application_source_unique');
        });
        foreach (['consumer_ppjb_developers', 'consumer_akad_records', 'consumer_bast_records'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (! Schema::hasColumn($tableName, 'source_system')) {
                    $table->string('source_system', 50)->nullable();
                }
                if (! Schema::hasColumn($tableName, 'source_branch_code')) {
                    $table->string('source_branch_code', 20)->nullable();
                }
            });
        }
        Schema::table('consumer_ppjb_developers', function (Blueprint $table): void {
            $table->dropUnique('consumer_ppjb_source_unique');
            $table->unique(['source_system', 'source_branch_code', 'source_id'], 'consumer_ppjb_source_unique');
        });
        Schema::table('consumer_akad_records', function (Blueprint $table): void {
            $table->dropUnique('consumer_akad_no_ppjb_unique');
            $table->unique(['source_system', 'source_branch_code', 'no_ppjb_akad'], 'consumer_akad_no_ppjb_unique');
        });
        Schema::table('consumer_bast_records', function (Blueprint $table): void {
            $table->dropUnique('consumer_bast_no_bast_unique');
            $table->unique(['source_system', 'source_branch_code', 'no_bast'], 'consumer_bast_no_bast_unique');
        });
    }

    public function down(): void
    {
        Schema::table('consumer_bast_records', function (Blueprint $table): void {
            $table->dropUnique('consumer_bast_no_bast_unique');
            $table->unique('no_bast', 'consumer_bast_no_bast_unique');
            $table->dropColumn('source_branch_code');
        });
        Schema::table('consumer_akad_records', function (Blueprint $table): void {
            $table->dropUnique('consumer_akad_no_ppjb_unique');
            $table->unique('no_ppjb_akad', 'consumer_akad_no_ppjb_unique');
            $table->dropColumn('source_branch_code');
        });
        Schema::table('consumer_ppjb_developers', function (Blueprint $table): void {
            $table->dropUnique('consumer_ppjb_source_unique');
            $table->unique(['source_system', 'source_id'], 'consumer_ppjb_source_unique');
            $table->dropColumn('source_branch_code');
        });
        Schema::table('consumer_psjbs', function (Blueprint $table): void {
            $table->dropUnique('consumer_psjbs_application_source_unique');
            $table->unique('id_psjb', 'consumer_psjbs_id_psjb_unique');
        });
    }
};
