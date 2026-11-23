<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_migration_states')) {
            Schema::create('branch_migration_states', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('branch_id')->unique()->constrained('branches');
                $table->string('state', 32)->default('sheet');
                $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('changed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        $branchIds = DB::table('branches')->pluck('id');
        foreach ($branchIds as $branchId) {
            DB::table('branch_migration_states')->insertOrIgnore([
                'branch_id' => $branchId,
                'state' => 'sheet',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Status migrasi data per cabang'],
            [
                'category' => 'added',
                'description' => 'Menambahkan status migrasi data per cabang untuk transisi Sheet ke OASIS.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Status migrasi data per cabang')->delete();
        Schema::dropIfExists('branch_migration_states');
    }
};
