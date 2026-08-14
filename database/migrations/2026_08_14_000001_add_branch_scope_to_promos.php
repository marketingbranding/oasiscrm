<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TITLE = 'Master Promo Cabang';

    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->string('code', 100)->nullable()->after('branch_id');
            $table->date('start_date')->nullable()->after('name');
            $table->date('end_date')->nullable()->after('start_date');
            $table->text('description')->nullable()->after('end_date');
            $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->dropUnique('promos_name_unique');
            $table->index('name', 'promos_name_index');
        });

        DB::table('promos')->orderBy('id')->get(['id', 'name'])->each(function ($promo) {
            $base = Str::slug($promo->name, '_') ?: 'promo';
            $code = $base;
            $suffix = 1;
            while (DB::table('promos')->where('code', $code)->exists()) {
                $code = $base.'_'.($suffix++);
            }
            DB::table('promos')->where('id', $promo->id)->update(['code' => $code]);
        });

        Schema::table('promos', fn (Blueprint $table) => $table->unique(['branch_id', 'code'], 'promos_branch_code_unique'));

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            ['category' => 'added', 'description' => 'Admin Cabang dan Koordinator kini dapat mengelola serta mengimpor promo cabang melalui copy-paste dari spreadsheet, dan pilihan promo Lead otomatis mengikuti cabang serta periode berlaku.', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::TITLE)->delete();
        Schema::table('promos', function (Blueprint $table) {
            $table->dropUnique('promos_branch_code_unique');
            $table->dropIndex('promos_name_index');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['code', 'start_date', 'end_date', 'description']);
        });
    }
};
