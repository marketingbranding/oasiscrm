<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Maintenance Per Modul';

    public function up(): void
    {
        Schema::create('module_maintenances', function (Blueprint $table) {
            $table->id();
            $table->string('module_key')->unique();
            $table->boolean('is_enabled')->default(false);
            $table->text('message')->nullable();
            $table->timestamp('estimated_end_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Superadmin kini dapat menonaktifkan sementara modul tertentu tanpa menutup seluruh OASIS. User lain akan melihat halaman maintenance, sementara Superadmin tetap dapat melakukan pengecekan modul.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
        Schema::dropIfExists('module_maintenances');
    }
};
