<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Tambahkan atribut user PRD v3 dan role Ass. Manager';

    private const CHANGELOG_DESCRIPTION = 'OASIS kini menyimpan atribut tipe dan lingkup user PRD v3 serta menyediakan role Ass. Manager tanpa mengubah role utama lama.';

    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'sales_type')) {
                    $table->string('sales_type')->nullable()->after('role_id');
                }

                if (! Schema::hasColumn('users', 'staff_type')) {
                    $table->string('staff_type')->nullable()->after('sales_type');
                }

                if (! Schema::hasColumn('users', 'scope_level')) {
                    $table->string('scope_level')->nullable()->after('staff_type');
                }
            });

            $staffTypes = [
                'admin' => 'admin_cabang',
                'sas' => 'sales',
                'sam' => 'marketing',
                'staff' => 'umum',
            ];

            foreach ($staffTypes as $roleSlug => $staffType) {
                $roleId = DB::table('roles')->where('slug', $roleSlug)->value('id');
                if (! $roleId) {
                    continue;
                }

                DB::table('users')
                    ->where('role_id', $roleId)
                    ->whereNull('staff_type')
                    ->update(['staff_type' => $staffType]);
            }

            DB::table('users')
                ->whereNull('scope_level')
                ->update([
                    'scope_level' => DB::raw("CASE WHEN branch_id IS NULL THEN 'pusat' ELSE 'cabang' END"),
                ]);
        }

        if (Schema::hasTable('roles')) {
            $manager = DB::table('roles')->where('slug', 'manager')->first();
            $managerAuthorityLevel = $manager?->authority_level ?? 40;
            $managerRoleId = $manager?->id;

            $assManagerValues = [
                'name' => 'Ass. Manager',
                'description' => 'Ass. Manager dengan akses pemantauan berdasarkan penugasan.',
                'is_superadmin' => false,
                'updated_at' => now(),
                'created_at' => now(),
            ];
            if (Schema::hasColumn('roles', 'is_active')) {
                $assManagerValues['is_active'] = true;
            }
            if (Schema::hasColumn('roles', 'authority_level')) {
                $assManagerValues['authority_level'] = $managerAuthorityLevel;
            }

            DB::table('roles')->updateOrInsert(['slug' => 'ass_manager'], $assManagerValues);

            if ($managerRoleId && Schema::hasTable('role_permission') && Schema::hasTable('permissions')) {
                $assManagerRoleId = DB::table('roles')->where('slug', 'ass_manager')->value('id');
                $permissionIds = DB::table('permissions')->pluck('id', 'slug');
                $permissions = collect(PermissionCatalog::rolePermissions()['ass_manager'])
                    ->map(fn (string $permissionSlug): ?int => $permissionIds[$permissionSlug] ?? null)
                    ->filter()
                    ->map(fn (int $permissionId): array => [
                        'permission_id' => $permissionId,
                        'role_id' => $assManagerRoleId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                    ->all();

                DB::table('role_permission')->where('role_id', $assManagerRoleId)->delete();
                DB::table('role_permission')->insert($permissions);
            }
        }

        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->updateOrInsert(
                ['version' => null, 'title' => self::CHANGELOG_TITLE],
                [
                    'category' => 'added',
                    'description' => self::CHANGELOG_DESCRIPTION,
                    'created_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')
                ->whereNull('version')
                ->where('title', self::CHANGELOG_TITLE)
                ->where('description', self::CHANGELOG_DESCRIPTION)
                ->delete();
        }

        // Role and pivot rows may predate this migration; preserve them conservatively.
        // Columns may predate this migration; preserve all user columns conservatively.
    }
};
