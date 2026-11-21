<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const INDEX = 'consumer_kavling_assignments_active_unique';

    private const CHANGELOG_TITLE = 'Kunci tunggal kavling untuk transaksi aktif dan terjual';

    public function up(): void
    {
        $this->dropIndex();

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE consumer_kavling_assignments DROP COLUMN active_kavling_key');
            DB::statement("ALTER TABLE consumer_kavling_assignments ADD active_kavling_key BIGINT GENERATED ALWAYS AS (IF(assignment_status IN ('active', 'sold') AND released_at IS NULL, kavling_id, NULL)) STORED");
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON consumer_kavling_assignments (active_kavling_key)');
        } else {
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX." ON consumer_kavling_assignments (kavling_id) WHERE assignment_status IN ('active', 'sold') AND released_at IS NULL");
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'description' => 'Kavling hanya dapat memiliki satu assignment aktif atau terjual yang belum dilepas, termasuk saat terjadi benturan penulisan bersamaan.',
                'category' => 'fixed',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
        $this->dropIndex();

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE consumer_kavling_assignments DROP COLUMN active_kavling_key');
            DB::statement("ALTER TABLE consumer_kavling_assignments ADD active_kavling_key BIGINT GENERATED ALWAYS AS (IF(assignment_status = 'active' AND released_at IS NULL, kavling_id, NULL)) STORED");
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON consumer_kavling_assignments (active_kavling_key)');
        } else {
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX." ON consumer_kavling_assignments (kavling_id) WHERE assignment_status = 'active' AND released_at IS NULL");
        }
    }

    private function dropIndex(): void
    {
        $statement = DB::connection()->getDriverName() === 'mysql'
            ? 'DROP INDEX '.self::INDEX.' ON consumer_kavling_assignments'
            : 'DROP INDEX '.self::INDEX;

        DB::statement($statement);
    }
};
