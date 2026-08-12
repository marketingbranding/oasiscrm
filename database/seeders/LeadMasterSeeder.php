<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\LeadMaster;
use Illuminate\Database\Seeder;

class LeadMasterSeeder extends Seeder
{
    public function run(): void
    {
        $projectsByCode = [
            'MLG' => [['Jabung Malang'], ['Marison Regency Wagir Malang', 'Wagir']],
            'MDN' => [['Madiun Perluasan', 'Madiun 2 Perluasan']],
            'SOL' => [['Teras Boyolali'], ['Jeruksawit 4'], ['Marison Wonogiri', 'Wonogiri'], ['Marison Sragen', 'Sragen'], ['Wonosari Js'], ['Rejosari Gondangrejo'], ['Musuk Boyolali']],
            'MGL' => [['Marison Karangrejek Wonosari', 'Wonosari'], ['Wonosari Kios'], ['Piyungan'], ['Marison Kalinegoro', 'Jonggrangan'], ['Dlimas Tegalrejo'], ['Tampingan Tegalrejo'], ['Sidoagung Tempuran'], ['Piyaman Wonosari'], ['Cawang']],
            'PWR' => [['Mutiara Garden 5']],
            'JPR' => [['Marison Jepara', 'Mlonggo 1'], ['Marison Jepara Perluasan', 'Mlonggo 2'], ['Marison Kuwasen', 'Kuwasen'], ['Mranggen'], ['Marison Kedungbulus', 'Pati']],
            'PKL' => [['Kecepak Batang'], ['Marison Kandeman', 'Kandeman Batang'], ['Marison Tegal', 'Tegal'], ['Wonopringgo Pekalongan'], ['Sarengat Batang']],
            'BDG' => [['Majalaya'], ['Marison Cipaku Perluasan', 'Majalaya 2'], ['Sumedang']],
        ];

        foreach ($projectsByCode as $code => $projects) {
            $branchId = Branch::where('code', $code)->value('id');
            foreach ($projects as $project) {
                LeadMaster::create([
                    'branch_id' => $branchId,
                    'project_name' => $project[0],
                    'sheet_project_name' => $project[1] ?? null,
                    'is_active' => true,
                ]);
            }
        }
    }
}
