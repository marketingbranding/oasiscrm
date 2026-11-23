<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dana_talangan_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dana_talangan_id')->constrained()->cascadeOnDelete();
            $table->date('paid_at');
            $table->decimal('amount', 15, 2);
            $table->text('note')->nullable();
            $table->boolean('is_verified')->default(true);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('evidence_path')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['dana_talangan_id', 'is_verified']);
        });

        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->updateOrInsert(
                ['version' => null, 'title' => 'Pembayaran Dana Talangan tercatat dan terverifikasi'],
                ['description' => 'Dana Talangan mendukung pencatatan pembayaran, verifikasi langsung, bukti privat, sisa tagihan, lebih bayar, dan Lunas otomatis.', 'category' => 'added', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->whereNull('version')->where('title', 'Pembayaran Dana Talangan tercatat dan terverifikasi')->delete();
        }

        Schema::dropIfExists('dana_talangan_payments');
    }
};
