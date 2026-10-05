<?php

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
        if (! Schema::hasTable('organization_units')) {
            Schema::create('organization_units', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_id')->nullable()->constrained('organization_units')->restrictOnDelete();
                $table->foreignId('branch_id')->nullable()->unique()->constrained('branches')->nullOnDelete();
                $table->string('code', 80)->unique();
                $table->string('name', 120);
                $table->string('unit_type', 32)->default('branch');
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['parent_id', 'is_active']);
                $table->index(['unit_type', 'is_active']);
            });
        }

        if (! Schema::hasColumn('users', 'organization_unit_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('organization_unit_id')->nullable()->after('branch_id')->constrained('organization_units')->nullOnDelete();
                $table->index('organization_unit_id');
            });
        }

        if (Schema::hasTable('organization_assignments') && ! Schema::hasColumn('organization_assignments', 'organization_unit_id')) {
            Schema::table('organization_assignments', function (Blueprint $table) {
                $table->foreignId('organization_unit_id')->nullable()->after('parent_user_id')->constrained('organization_units')->nullOnDelete();
                $table->index(['organization_unit_id', 'is_active'], 'organization_assignments_unit_index');
            });
        }

        $centralId = DB::table('organization_units')->where('code', 'pusat')->value('id');
        if ($centralId === null) {
            $centralId = DB::table('organization_units')->insertGetId([
                'parent_id' => null,
                'branch_id' => null,
                'code' => 'pusat',
                'name' => 'Pusat',
                'unit_type' => 'central',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('branches')->orderBy('id')->eachById(function (object $branch) use ($centralId): void {
            DB::table('organization_units')->updateOrInsert(
                ['branch_id' => $branch->id],
                [
                    'parent_id' => $centralId,
                    'code' => 'branch-'.$branch->id,
                    'name' => $branch->name,
                    'unit_type' => 'branch',
                    'is_active' => (bool) $branch->is_active,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        });

        if (Schema::hasColumn('users', 'organization_unit_id')) {
            DB::table('users')->orderBy('id')->eachById(function (object $user) use ($centralId): void {
                if ($user->organization_unit_id !== null) {
                    return;
                }

                $unitId = $user->branch_id === null
                    ? $centralId
                    : DB::table('organization_units')->where('branch_id', $user->branch_id)->value('id');

                DB::table('users')->where('id', $user->id)->update(['organization_unit_id' => $unitId]);
            });
        }

        if (Schema::hasTable('organization_assignments') && Schema::hasColumn('organization_assignments', 'organization_unit_id')) {
            DB::table('organization_assignments')->orderBy('id')->eachById(function (object $assignment): void {
                if ($assignment->organization_unit_id !== null) {
                    return;
                }

                $unitId = DB::table('users')->where('id', $assignment->user_id)->value('organization_unit_id');
                DB::table('organization_assignments')->where('id', $assignment->id)->update([
                    'organization_unit_id' => $unitId,
                    'branch_id' => $assignment->branch_id ?? DB::table('users')->where('id', $assignment->user_id)->value('branch_id'),
                ]);
            });
        }

        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->updateOrInsert(
                ['version' => null, 'title' => 'Tree Organisasi Pusat dan Cabang'],
                [
                    'description' => 'Struktur organisasi kini memiliki tree Pusat dan Cabang dengan satu tree aktif per pengguna.',
                    'category' => 'changed',
                    'created_by' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('organization_assignments') && Schema::hasColumn('organization_assignments', 'organization_unit_id')) {
            Schema::table('organization_assignments', function (Blueprint $table) {
                $table->dropIndex('organization_assignments_unit_index');
                $table->dropConstrainedForeignId('organization_unit_id');
            });
        }

        if (Schema::hasColumn('users', 'organization_unit_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['organization_unit_id']);
                $table->dropConstrainedForeignId('organization_unit_id');
            });
        }

        Schema::dropIfExists('organization_units');

        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->whereNull('version')->where('title', 'Tree Organisasi Pusat dan Cabang')->delete();
        }
    }
};
