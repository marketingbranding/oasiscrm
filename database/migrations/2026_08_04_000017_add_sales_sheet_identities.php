<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_master', function (Blueprint $table) {
            $table->string('sheet_project_name')->nullable()->after('project_name');
        });
        Schema::create('sales_sheet_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('spreadsheet_value');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['branch_id', 'user_id']);
            $table->unique(['branch_id', 'spreadsheet_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_sheet_identities');
        Schema::table('lead_master', fn (Blueprint $table) => $table->dropColumn('sheet_project_name'));
    }
};
