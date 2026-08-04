<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Sumber lead dan sinkronisasi cabang diselaraskan';

    public function up(): void
    {
        if (! Schema::hasColumn('sales_lead_lifecycle_sync_statuses', 'duration_ms')) {
            Schema::table('sales_lead_lifecycle_sync_statuses', function (Blueprint $table) {
                $table->unsignedBigInteger('duration_ms')->nullable()->after('finished_at');
            });
        }

        $salesRoleId = DB::table('roles')->where('slug', 'sales')->value('id');
        $syncPermissionId = DB::table('permissions')->where('slug', 'sales_pocketbook.sync')->value('id');
        if ($salesRoleId && $syncPermissionId) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $salesRoleId,
                'permission_id' => $syncPermissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'description' => 'Sumber lead kini mengikuti pilihan spreadsheet cabang, sementara status dan aksi sinkronisasi Buku Saku Sales mengikuti lingkup cabang serta penugasan aktif.',
                'category' => 'changed',
                'created_by' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $salesRoleId = DB::table('roles')->where('slug', 'sales')->value('id');
        $syncPermissionId = DB::table('permissions')->where('slug', 'sales_pocketbook.sync')->value('id');
        if ($salesRoleId && $syncPermissionId) {
            DB::table('role_permission')->where('role_id', $salesRoleId)->where('permission_id', $syncPermissionId)->delete();
        }

        if (Schema::hasColumn('sales_lead_lifecycle_sync_statuses', 'duration_ms')) {
            Schema::table('sales_lead_lifecycle_sync_statuses', function (Blueprint $table) {
                $table->dropColumn('duration_ms');
            });
        }

        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
    }
};
