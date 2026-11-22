<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('grantee_user_id')->constrained('users');
            $table->foreignId('subject_user_id')->constrained('users');
            $table->boolean('include_descendants')->default(false);
            $table->string('module', 40);
            $table->boolean('can_view')->default(true);
            $table->boolean('can_edit')->default(false);
            $table->timestamp('expires_at');
            $table->text('reason');
            $table->foreignId('granted_by')->constrained('users');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->index(['grantee_user_id', 'module', 'expires_at']);
            $table->index(['subject_user_id', 'module', 'expires_at']);
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => 'Izin Akses Khusus'],
            ['description' => 'Superadmin dapat memberi dan mencabut akses sementara untuk data tim Sales.', 'category' => 'added', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', 'Izin Akses Khusus')->delete();
        Schema::dropIfExists('access_grants');
    }
};
