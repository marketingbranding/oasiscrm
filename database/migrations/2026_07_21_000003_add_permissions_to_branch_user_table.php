<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_user', function (Blueprint $table) {
            $table->string('membership_role', 50)->nullable()->after('branch_id');
            $table->boolean('can_view')->default(true)->after('membership_role');
            $table->boolean('can_edit')->default(false)->after('can_view');
            $table->boolean('can_sync')->default(false)->after('can_edit');
            $table->boolean('can_manage_members')->default(false)->after('can_sync');
            $table->index(['branch_id', 'can_view']);
            $table->index(['user_id', 'can_view']);
        });

        $now = now();
        DB::table('users')
            ->whereNotNull('branch_id')
            ->orderBy('id')
            ->get(['id', 'branch_id'])
            ->each(function ($user) use ($now) {
                DB::table('branch_user')->insertOrIgnore([
                    'user_id' => $user->id,
                    'branch_id' => $user->branch_id,
                    'can_view' => true,
                    'can_edit' => true,
                    'can_sync' => true,
                    'can_manage_members' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('branch_user', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'can_view']);
            $table->dropIndex(['user_id', 'can_view']);
            $table->dropColumn(['membership_role', 'can_view', 'can_edit', 'can_sync', 'can_manage_members']);
        });
    }
};
