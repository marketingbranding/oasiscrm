<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Hard Reset Akses Pengguna';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('temporary_password_expires_at')->nullable()->after('must_change_password');
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'changed',
                'description' => 'Super Admin dapat mereset akses pengguna dengan password sementara unik yang berlaku 24 jam dan wajib diganti saat login. Password sementara ditampilkan sekali dan tidak dikirim melalui email.',
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
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('temporary_password_expires_at');
        });

        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
    }
};
