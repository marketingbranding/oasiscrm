<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Menambahkan provenance NUP dan relasi lifecycle';

    public function up(): void
    {
        if (Schema::hasTable('consumer_nups')) {
            Schema::table('consumer_nups', function (Blueprint $table): void {
                if (! Schema::hasColumn('consumer_nups', 'entry_type')) {
                    $table->string('entry_type', 30)->nullable()->after('status');
                }
                if (! Schema::hasColumn('consumer_nups', 'source_application_id')) {
                    $table->foreignId('source_application_id')->nullable()->after('converted_application_id')->constrained('consumer_applications')->nullOnDelete();
                }
                if (! Schema::hasColumn('consumer_nups', 'issue_id')) {
                    $table->foreignId('issue_id')->nullable()->after('source_application_id')->constrained('consumer_issues')->nullOnDelete();
                }
            });

            if (! Schema::hasIndex('consumer_nups', 'consumer_nups_entry_type_index')) {
                Schema::table('consumer_nups', function (Blueprint $table): void {
                    $table->index('entry_type', 'consumer_nups_entry_type_index');
                });
            }
            if (! Schema::hasIndex('consumer_nups', 'consumer_nups_source_application_index')) {
                Schema::table('consumer_nups', function (Blueprint $table): void {
                    $table->index('source_application_id', 'consumer_nups_source_application_index');
                });
            }
            if (! Schema::hasIndex('consumer_nups', 'consumer_nups_issue_index')) {
                Schema::table('consumer_nups', function (Blueprint $table): void {
                    $table->index('issue_id', 'consumer_nups_issue_index');
                });
            }
        }

        DB::table('changelogs')->updateOrInsert(
            ['version' => null, 'title' => self::CHANGELOG_TITLE],
            [
                'description' => 'NUP menyimpan provenance dari lead, pengembalian transaksi, atau data legacy tanpa membuat riwayat historis palsu.',
                'category' => 'added',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();

        if (! Schema::hasTable('consumer_nups')) {
            return;
        }

        if (Schema::hasColumn('consumer_nups', 'issue_id')) {
            Schema::table('consumer_nups', function (Blueprint $table): void {
                $table->dropForeign(['issue_id']);
            });
        }
        if (Schema::hasColumn('consumer_nups', 'source_application_id')) {
            Schema::table('consumer_nups', function (Blueprint $table): void {
                $table->dropForeign(['source_application_id']);
            });
        }

        foreach ([
            'consumer_nups_entry_type_index',
            'consumer_nups_source_application_index',
            'consumer_nups_issue_index',
        ] as $index) {
            if (Schema::hasIndex('consumer_nups', $index)) {
                Schema::table('consumer_nups', function (Blueprint $table) use ($index): void {
                    $table->dropIndex($index);
                });
            }
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('consumer_nups', 'entry_type') ? 'entry_type' : null,
            Schema::hasColumn('consumer_nups', 'source_application_id') ? 'source_application_id' : null,
            Schema::hasColumn('consumer_nups', 'issue_id') ? 'issue_id' : null,
        ]));
        if ($columns !== []) {
            Schema::table('consumer_nups', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
