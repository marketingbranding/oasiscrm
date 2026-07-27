<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const BACKUP_TABLE = 'lead_source_standardization_backups';

    private const CANONICAL = [
        'Canvasing',
        'Event',
        'Freelance',
        'Iklan Pusat',
        'Online',
        'Pameran',
        'Refferal',
    ];

    private const LEGACY_DEFAULTS = [
        'Event Mandiri',
        'Kerjasama Event',
        'Event Internal',
        'Digital Campaign',
        'Walk-in',
        'Referensi',
        'Media Sosial',
        'Telemarketing',
        'Website',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::BACKUP_TABLE)) {
            Schema::create(self::BACKUP_TABLE, function (Blueprint $table) {
                $table->unsignedBigInteger('lead_source_id')->primary();
                $table->boolean('is_active');
                $table->timestamp('updated_at')->nullable();
            });

            $snapshot = DB::table('lead_sources')->get(['id', 'is_active', 'updated_at'])->map(fn ($source) => [
                'lead_source_id' => $source->id,
                'is_active' => $source->is_active,
                'updated_at' => $source->updated_at,
            ])->all();
            if ($snapshot !== []) {
                DB::table(self::BACKUP_TABLE)->insert($snapshot);
            }
        }

        $now = now();
        foreach (self::CANONICAL as $name) {
            DB::table('lead_sources')->insertOrIgnore([
                'name' => $name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('lead_sources')->whereIn('name', self::CANONICAL)->where('is_active', false)->update([
            'is_active' => true,
            'updated_at' => $now,
        ]);
        DB::table('lead_sources')->whereNotIn('name', self::CANONICAL)->where('is_active', true)->update([
            'is_active' => false,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable(self::BACKUP_TABLE)) {
            $backup = DB::table(self::BACKUP_TABLE)->get();
            foreach ($backup as $source) {
                DB::table('lead_sources')->where('id', $source->lead_source_id)->update([
                    'is_active' => $source->is_active,
                    'updated_at' => $source->updated_at,
                ]);
            }

            $backedUpIds = $backup->pluck('lead_source_id')->all();
            DB::table('lead_sources')
                ->when($backedUpIds !== [], fn ($query) => $query->whereNotIn('id', $backedUpIds))
                ->where('is_active', true)
                ->update(['is_active' => false, 'updated_at' => now()]);
            Schema::drop(self::BACKUP_TABLE);

            return;
        }

        // Supports local environments that ran an earlier draft before the snapshot existed.
        DB::table('lead_sources')->whereIn('name', self::CANONICAL)->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);
        DB::table('lead_sources')->where('name', 'Pameran')->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);
        DB::table('lead_sources')->whereIn('name', self::LEGACY_DEFAULTS)->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);
    }
};
