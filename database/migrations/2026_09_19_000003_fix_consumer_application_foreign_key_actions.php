<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLE = 'Perbaikan relasi aplikasi konsumen nullable';

    public function up(): void
    {
        $this->dropForeignKey('customer_id');
        $this->dropForeignKey('project_id');

        Schema::table('consumer_applications', function (Blueprint $table): void {
            $table->foreign('customer_id', 'consumer_apps_customer_fk')
                ->references('id')->on('customers')->nullOnDelete();
            $table->foreign('project_id', 'consumer_apps_project_fk')
                ->references('id')->on('lead_master')->nullOnDelete();
        });

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::TITLE],
            [
                'category' => 'fixed',
                'description' => 'Relasi Customer dan proyek pada aplikasi konsumen kini menggunakan ON DELETE SET NULL sesuai schema canonical dan alur rekonsiliasi Marison V2.',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')
            ->whereNull('version')
            ->where('title', self::TITLE)
            ->delete();
    }

    private function dropForeignKey(string $column): void
    {
        if (DB::getDriverName() === 'mysql') {
            $constraint = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->whereRaw('TABLE_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'consumer_applications')
                ->where('COLUMN_NAME', $column)
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->value('CONSTRAINT_NAME');

            if ($constraint !== null) {
                DB::statement(sprintf(
                    'ALTER TABLE `consumer_applications` DROP FOREIGN KEY `%s`',
                    str_replace('`', '``', $constraint),
                ));
            }

            return;
        }

        Schema::table('consumer_applications', function (Blueprint $table) use ($column): void {
            $table->dropForeign([$column]);
        });
    }
};
