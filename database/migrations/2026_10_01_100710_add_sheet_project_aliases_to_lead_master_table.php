<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lead_master', 'sheet_project_aliases')) {
            Schema::table('lead_master', function (Blueprint $table): void {
                $table->json('sheet_project_aliases')->nullable()->after('sheet_project_name');
            });
        }

        $aliases = [
            2 => ['sheet' => 'Wagir', 'aliases' => ['Marison Regency Wagir Malang']],
            3 => ['sheet' => 'Madiun 2 Perluasan', 'aliases' => ['Madiun Perluasan']],
            6 => ['sheet' => 'Wonogiri', 'aliases' => ['Marison Wonogiri']],
            7 => ['sheet' => 'Sragen', 'aliases' => ['Marison Sragen']],
            11 => ['sheet' => 'Wonosari', 'aliases' => ['Marison Karangrejek Wonosari']],
            14 => ['sheet' => 'Jonggrangan', 'aliases' => ['Marison Kalinegoro']],
            22 => ['sheet' => 'Mlonggo 2', 'aliases' => ['Marison Jepara Perluasan']],
            23 => ['sheet' => 'Kuwasen', 'aliases' => ['Marison Kuwasen']],
            25 => ['sheet' => 'Pati', 'aliases' => ['Marison Kedungbulus']],
            27 => ['sheet' => 'Kandeman Batang', 'aliases' => ['Marison Kandeman']],
            28 => ['sheet' => 'Tegal', 'aliases' => ['Marison Panggung Tegal', 'Marison Tegal']],
            32 => ['sheet' => 'Majalaya 2', 'aliases' => ['Marison Cipaku', 'Marison Cipaku Perluasan']],
        ];

        foreach ($aliases as $id => $mapping) {
            DB::table('lead_master')->where('id', $id)->update([
                'sheet_project_name' => $mapping['sheet'],
                'sheet_project_aliases' => json_encode($mapping['aliases'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }

        $branchId = DB::table('branches')->where('name', 'KC JEPARA')->value('id');
        if ($branchId !== null) {
            $project = DB::table('lead_master')->where('branch_id', $branchId)->where('project_name', 'Marison Mulyoharjo')->first();
            if (! $project) {
                DB::table('lead_master')->insert([
                    'branch_id' => $branchId,
                    'project_name' => 'Marison Mulyoharjo',
                    'sheet_project_name' => 'Mulyoharjo',
                    'sheet_project_aliases' => json_encode(['Marison Mulyoharjo'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('lead_master')->where('id', $project->id)->update([
                    'sheet_project_name' => 'Mulyoharjo',
                    'sheet_project_aliases' => json_encode(['Marison Mulyoharjo'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            }
        }

        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->updateOrInsert(
                ['version' => null, 'title' => 'Alias proyek spreadsheet Dana Talangan diperluas'],
                ['category' => 'changed', 'description' => 'OASIS kini membaca beberapa alias proyek spreadsheet per cabang untuk sinkronisasi Dana Talangan.', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lead_master', 'sheet_project_aliases')) {
            Schema::table('lead_master', function (Blueprint $table): void {
                $table->dropColumn('sheet_project_aliases');
            });
        }

        if (Schema::hasTable('changelogs')) {
            DB::table('changelogs')->whereNull('version')->where('title', 'Alias proyek spreadsheet Dana Talangan diperluas')->delete();
        }
    }
};
