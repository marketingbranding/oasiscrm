<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'sales_onboarding_proposals';

    private const PENDING_EMAIL_UNIQUE = 'sales_onboarding_proposals_pending_email_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn(self::TABLE, 'pending_email')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->string('pending_email')->nullable()->after('email');
            });
        }

        if ($this->indexFor(self::TABLE, ['pending_email'], true) !== null) {
            return;
        }

        DB::table(self::TABLE)->whereNotNull('pending_email')->update(['pending_email' => null]);

        $seen = [];
        DB::table(self::TABLE)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get(['id', 'email'])
            ->each(function (object $proposal) use (&$seen): void {
                $normalizedEmail = mb_strtolower(trim((string) $proposal->email));
                $pendingEmail = array_key_exists($normalizedEmail, $seen) ? null : $normalizedEmail;
                $seen[$normalizedEmail] = true;

                DB::table(self::TABLE)->where('id', $proposal->id)->update(['pending_email' => $pendingEmail]);
            });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unique('pending_email', self::PENDING_EMAIL_UNIQUE);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $uniqueIndex = $this->indexFor(self::TABLE, ['pending_email'], true);
        if ($uniqueIndex !== null) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($uniqueIndex): void {
                $table->dropUnique($uniqueIndex);
            });
        }

        if (Schema::hasColumn(self::TABLE, 'pending_email')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropColumn('pending_email');
            });
        }
    }

    private function indexFor(string $table, array $columns, bool $unique): ?string
    {
        $wanted = $this->normaliseColumns($columns);

        foreach (Schema::getIndexes($table) as $key => $meta) {
            $indexedColumns = $this->normaliseColumns($meta['columns'] ?? []);
            if ((bool) ($meta['unique'] ?? false) === $unique && $indexedColumns === $wanted) {
                return (string) ($meta['name'] ?? $key);
            }
        }

        return null;
    }

    private function normaliseColumns(array $columns): array
    {
        sort($columns);

        return array_values($columns);
    }
};
