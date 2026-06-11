<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->after('id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->after('role_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 20)->after('email')->nullable();
            $table->boolean('is_active')->after('password')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['branch_id']);
            $table->dropColumn(['role_id', 'branch_id', 'phone', 'is_active']);
        });
    }
};
