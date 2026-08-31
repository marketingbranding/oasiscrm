<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_lead_bridge_settings')) {
            Schema::create('sales_lead_bridge_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('mode', 20)->default('off');
                $table->string('status', 30)->default('never');
                $table->timestamp('last_preflight_at')->nullable();
                $table->string('last_preflight_hash', 64)->nullable();
                $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('enabled_at')->nullable();
                $table->timestamps();
                $table->index(['mode', 'status']);
            });
        }

        Schema::table('sales_leads', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_leads', 'remote_target_branch_id')) {
                $table->foreignId('remote_target_branch_id')->nullable()->constrained('branches')->nullOnDelete()->after('branch_id');
            }
            if (! Schema::hasColumn('sales_leads', 'last_synced_payload_hash')) {
                $table->string('last_synced_payload_hash', 64)->nullable()->after('sync_status');
            }
            if (! Schema::hasColumn('sales_leads', 'last_remote_payload_hash')) {
                $table->string('last_remote_payload_hash', 64)->nullable()->after('last_synced_payload_hash');
            }
            if (! Schema::hasColumn('sales_leads', 'last_synced_field_hashes')) {
                $table->json('last_synced_field_hashes')->nullable()->after('last_remote_payload_hash');
            }
            if (! Schema::hasColumn('sales_leads', 'delivery_attempted_at')) {
                $table->timestamp('delivery_attempted_at')->nullable()->after('last_synced_field_hashes');
            }
            if (! Schema::hasColumn('sales_leads', 'delete_pending_at')) {
                $table->timestamp('delete_pending_at')->nullable()->after('delivery_attempted_at');
            }
            if (Schema::hasColumn('sales_leads', 'sync_status')) {
                $table->string('sync_status', 30)->default('pending_create')->change();
            }
        });

        DB::table('sales_leads')->whereNull('remote_target_branch_id')->whereNotNull('last_synced_at')->update(['remote_target_branch_id' => DB::raw('branch_id')]);

        Schema::table('sales_leads', function (Blueprint $table): void {
            if (! Schema::hasIndex('sales_leads', 'sales_leads_remote_branch_sync_index')) {
                $table->index(['remote_target_branch_id', 'sync_status'], 'sales_leads_remote_branch_sync_index');
            }
            if (! Schema::hasIndex('sales_leads', 'sales_leads_branch_remote_hash_index')) {
                $table->index(['branch_id', 'last_remote_payload_hash'], 'sales_leads_branch_remote_hash_index');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_lead_bridge_settings')) {
            Schema::drop('sales_lead_bridge_settings');
        }

        if (! Schema::hasTable('sales_leads')) {
            return;
        }

        Schema::table('sales_leads', function (Blueprint $table): void {
            if (Schema::hasIndex('sales_leads', 'sales_leads_remote_branch_sync_index')) {
                $table->dropIndex('sales_leads_remote_branch_sync_index');
            }
            if (Schema::hasIndex('sales_leads', 'sales_leads_branch_remote_hash_index')) {
                $table->dropIndex('sales_leads_branch_remote_hash_index');
            }
            $columns = array_values(array_filter([
                Schema::hasColumn('sales_leads', 'remote_target_branch_id') ? 'remote_target_branch_id' : null,
                Schema::hasColumn('sales_leads', 'last_synced_payload_hash') ? 'last_synced_payload_hash' : null,
                Schema::hasColumn('sales_leads', 'last_remote_payload_hash') ? 'last_remote_payload_hash' : null,
                Schema::hasColumn('sales_leads', 'last_synced_field_hashes') ? 'last_synced_field_hashes' : null,
                Schema::hasColumn('sales_leads', 'delivery_attempted_at') ? 'delivery_attempted_at' : null,
                Schema::hasColumn('sales_leads', 'delete_pending_at') ? 'delete_pending_at' : null,
            ]));
            if ($columns !== []) {
                if (in_array('remote_target_branch_id', $columns, true)) {
                    $table->dropForeign(['remote_target_branch_id']);
                }
                $table->dropColumn($columns);
            }
        });
    }
};
