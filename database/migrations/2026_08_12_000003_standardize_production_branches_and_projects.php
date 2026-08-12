<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Standardisasi Cabang & Proyek Produksi';

    private const BRANCHES = [
        1 => ['old' => 'Malang', 'new' => 'KC MALANG'],
        2 => ['old' => 'Madiun', 'new' => 'KC MADIUN'],
        3 => ['old' => 'Solo', 'new' => 'KC SOLO'],
        4 => ['old' => 'Magelang', 'new' => 'KC MAGELANG'],
        5 => ['old' => 'Purworejo', 'new' => 'KC PURWOREJO'],
        6 => ['old' => 'Jepara', 'new' => 'KC JEPARA'],
        7 => ['old' => 'Batang', 'new' => 'KC BATANG'],
        8 => ['old' => 'Sumedang', 'new' => 'KC BANDUNG'],
        9 => ['old' => 'Kantor Pusat', 'new' => 'Kantor Pusat'],
    ];

    private const PROJECTS = [
        2 => ['old' => 'Wagir', 'new' => 'Marison Regency Wagir Malang'],
        3 => ['old' => 'Madiun 2 Perluasan', 'new' => 'Madiun Perluasan'],
        5 => ['old' => 'Jeruksawit 4', 'new' => 'Jeruksawit 4'],
        6 => ['old' => 'Wonogiri', 'new' => 'Marison Wonogiri'],
        7 => ['old' => 'Sragen', 'new' => 'Marison Sragen'],
        11 => ['old' => 'Wonosari', 'new' => 'Marison Karangrejek Wonosari'],
        12 => ['old' => 'Wonosari Kios', 'new' => 'Wonosari Kios'],
        14 => ['old' => 'Jonggrangan', 'new' => 'Marison Kalinegoro'],
        19 => ['old' => 'Cawang', 'new' => 'Cawang'],
        20 => ['old' => 'Mutiara Garden 5', 'new' => 'Mutiara Garden 5'],
        21 => ['old' => 'Mlonggo 1', 'new' => 'Marison Jepara'],
        22 => ['old' => 'Mlonggo 2', 'new' => 'Marison Jepara Perluasan'],
        23 => ['old' => 'Kuwasen', 'new' => 'Marison Kuwasen'],
        25 => ['old' => 'Pati', 'new' => 'Marison Kedungbulus'],
        27 => ['old' => 'Kandeman Batang', 'new' => 'Marison Kandeman'],
        28 => ['old' => 'Tegal', 'new' => 'Marison Tegal'],
        32 => ['old' => 'Majalaya 2', 'new' => 'Marison Cipaku Perluasan'],
        33 => ['old' => 'Sumedang', 'new' => 'Sumedang'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::BRANCHES as $id => $names) {
                $actual = DB::table('branches')->where('id', $id)->value('name');

                if ($actual === null || $actual === $names['new']) {
                    continue;
                }

                if ($actual !== $names['old']) {
                    throw new RuntimeException("Branch id {$id}: expected old '{$names['old']}', actual '{$actual}', target '{$names['new']}'.");
                }

                DB::table('branches')->where('id', $id)->update(['name' => $names['new']]);
            }

            foreach (self::PROJECTS as $id => $names) {
                $project = DB::table('lead_master')->where('id', $id)->first(['project_name', 'sheet_project_name']);

                if (! $project || $project->project_name === $names['new']) {
                    continue;
                }

                if ($project->project_name !== $names['old']) {
                    throw new RuntimeException("Project id {$id}: expected old '{$names['old']}', actual '{$project->project_name}', target '{$names['new']}'.");
                }

                DB::table('lead_master')->where('id', $id)->update([
                    'project_name' => $names['new'],
                    'sheet_project_name' => $project->sheet_project_name ?? $names['old'],
                ]);
            }

            DB::table('changelogs')->updateOrInsert(
                ['version' => null, 'title' => self::CHANGELOG_TITLE],
                [
                    'category' => 'changed',
                    'description' => 'Nama cabang dan proyek produksi distandardisasi berdasarkan ID tanpa mengubah identitas, status aktif, relasi cabang, kode, atau konfigurasi Google Sheet.',
                    'created_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });
    }

    public function down(): void
    {
        // Nomenclature data is intentionally not reverted to avoid damaging post-deploy data.
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
    }
};
