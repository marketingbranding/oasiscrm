<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'account_status')) {
                $table->string('account_status', 32)->default('active');
            }
            if (! Schema::hasColumn('users', 'supervisor_user_id')) {
                $table->foreignId('supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'invited_at')) {
                $table->timestamp('invited_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'activated_at')) {
                $table->timestamp('activated_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'last_login_ip')) {
                $table->string('last_login_ip', 45)->nullable();
            }
            if (! Schema::hasColumn('users', 'last_login_user_agent')) {
                $table->text('last_login_user_agent')->nullable();
            }
            if (! Schema::hasColumn('users', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('users', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        DB::table('users')->where('is_active', true)->update([
            'account_status' => 'active',
            'email_verified_at' => DB::raw('COALESCE(email_verified_at, CURRENT_TIMESTAMP)'),
            'activated_at' => DB::raw('COALESCE(activated_at, created_at, CURRENT_TIMESTAMP)'),
        ]);
        DB::table('users')->where('is_active', false)->update(['account_status' => 'inactive']);

        $this->addIndexIfMissing('users', ['account_status'], 'users_account_status_index');
        $this->addIndexIfMissing('users', ['supervisor_user_id'], 'users_supervisor_user_id_index');
        $this->addIndexIfMissing('users', ['role_id'], 'users_role_id_index');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['supervisor_user_id']);
            $table->dropForeign(['created_by']);
            $table->dropForeign(['updated_by']);
            $table->dropColumn([
                'account_status', 'supervisor_user_id', 'invited_at', 'activated_at',
                'suspended_at', 'deactivated_at', 'last_login_at', 'last_login_ip',
                'last_login_user_agent', 'created_by', 'updated_by',
            ]);
        });
    }

    private function addIndexIfMissing(string $table, array $columns, string $name): void
    {
        $indexes = Schema::getIndexes($table);
        $exists = collect($indexes)->contains(fn (array $index) => $index['columns'] === $columns);

        if (! $exists) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }
};
