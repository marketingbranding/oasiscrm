<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANGELOG_TITLE = 'Standardisasi Cabang & Proyek Produksi';

    private const BRANCHES = [
        1 => 'KC MALANG',
        2 => 'KC MADIUN',
        3 => 'KC SOLO',
        4 => 'KC MAGELANG',
        5 => 'KC PURWOREJO',
        6 => 'KC JEPARA',
        7 => 'KC BATANG',
        8 => 'KC BANDUNG',
        9 => 'Kantor Pusat',
    ];

    private const PROJECTS = [
        2 => 'Marison Regency Wagir Malang',
        3 => 'Madiun Perluasan',
        5 => 'Jeruksawit 4',
        6 => 'Marison Wonogiri',
        7 => 'Marison Sragen',
        11 => 'Marison Karangrejek Wonosari',
        12 => 'Wonosari Kios',
        14 => 'Marison Kalinegoro',
        19 => 'Cawang',
        20 => 'Mutiara Garden 5',
        21 => 'Marison Jepara',
        22 => 'Marison Jepara Perluasan',
        23 => 'Marison Kuwasen',
        25 => 'Marison Kedungbulus',
        27 => 'Marison Kandeman',
        28 => 'Marison Tegal',
        32 => 'Marison Cipaku Perluasan',
        33 => 'Sumedang',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::BRANCHES as $id => $name) {
                DB::table('branches')->where('id', $id)->where('name', '!=', $name)->update(['name' => $name]);
            }

            foreach (self::PROJECTS as $id => $name) {
                $project = DB::table('lead_master')->where('id', $id)->first(['project_name', 'sheet_project_name']);

                if (! $project || $project->project_name === $name) {
                    continue;
                }

                DB::table('lead_master')->where('id', $id)->update([
                    'project_name' => $name,
                    'sheet_project_name' => $project->sheet_project_name ?? $project->project_name,
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
        DB::table('changelogs')->whereNull('version')->where('title', self::CHANGELOG_TITLE)->delete();
    }
};
