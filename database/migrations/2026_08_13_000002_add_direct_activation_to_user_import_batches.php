<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Aktivasi Langsung Import User';

    public function up(): void
    {
        Schema::table('user_import_batches', function (Blueprint $table) {
            $table->string('activation_mode')->default('invitation');
            $table->text('credential_payload')->nullable();
            $table->timestamp('credential_expires_at')->nullable();
            $table->timestamp('credential_downloaded_at')->nullable();
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'added',
                'description' => 'Super Admin dapat mengaktifkan pengguna secara langsung melalui import massal dengan kredensial sementara yang tersimpan aman dan terbatas waktu.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        Schema::table('user_import_batches', function (Blueprint $table) {
            $table->dropColumn([
                'activation_mode',
                'credential_payload',
                'credential_expires_at',
                'credential_downloaded_at',
            ]);
        });

        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
    }
};
