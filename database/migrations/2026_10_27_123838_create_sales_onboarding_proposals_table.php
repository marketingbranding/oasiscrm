<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales_onboarding_proposals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('lead_master')->restrictOnDelete();
            $table->foreignId('coordinator_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->json('submitted_scope');
            $table->timestamp('submitted_at');
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->uuid('decision_token')->unique();
            $table->timestamps();
        });

        $permissions = collect([
            ['name' => 'Mengusulkan Sales', 'slug' => 'users.propose_sales', 'description' => 'Mengusulkan onboarding pengguna Sales dalam lingkup KC/KCP.', 'group_name' => 'Pengguna'],
            ['name' => 'Menyetujui onboarding Sales', 'slug' => 'users.approve_sales', 'description' => 'Menyetujui atau menolak proposal onboarding pengguna Sales.', 'group_name' => 'Pengguna'],
        ]);
        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['slug' => $permission['slug']],
                [...$permission, 'created_at' => now(), 'updated_at' => now()],
            );
        }
        Permission::resetRegisteredSlugs();
        $adminRoleId = DB::table('roles')->where('slug', 'admin')->value('id');
        $proposalPermissionId = DB::table('permissions')->where('slug', 'users.propose_sales')->value('id');
        if ($adminRoleId !== null && $proposalPermissionId !== null) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $adminRoleId,
                'permission_id' => $proposalPermissionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Persetujuan onboarding Sales'],
            [
                'category' => 'added',
                'description' => 'Onboarding Sales kini diajukan Admin dalam lingkup KC/KCP dan hanya diprovisikan setelah persetujuan Superadmin.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Persetujuan onboarding Sales')->delete();
        $permissionIds = DB::table('permissions')->whereIn('slug', ['users.propose_sales', 'users.approve_sales'])->pluck('id');
        DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('slug', ['users.propose_sales', 'users.approve_sales'])->delete();
        Permission::resetRegisteredSlugs();
        Schema::dropIfExists('sales_onboarding_proposals');
    }
};
