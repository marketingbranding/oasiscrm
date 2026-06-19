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
            'MLG' => ['Jabung Malang', 'Wagir'],
            'MDN' => ['Madiun 2 Perluasan'],
            'SOL' => ['Teras Boyolali', 'Jeruksawit 4', 'Wonogiri', 'Sragen', 'Wonosari Js', 'Rejosari Gondangrejo', 'Musuk Boyolali'],
            'MGL' => ['Wonosari', 'Wonosari Kios', 'Piyungan', 'Jonggrangan', 'Dlimas Tegalrejo', 'Tampingan Tegalrejo', 'Sidoagung Tempuran', 'Piyaman Wonosari', 'Cawang'],
            'PWR' => ['Mutiara Garden 5'],
            'JPR' => ['Mlonggo 1', 'Mlonggo 2', 'Kuwasen', 'Mranggen', 'Pati'],
            'PKL' => ['Kecepak Batang', 'Kandeman Batang', 'Tegal', 'Wonopringgo Pekalongan', 'Sarengat Batang'],
            'BDG' => ['Majalaya', 'Majalaya 2', 'Sumedang'],
        ];

        foreach ($projectsByCode as $code => $projects) {
            $branchId = Branch::where('code', $code)->value('id');
            foreach ($projects as $name) {
                LeadMaster::create([
                    'branch_id' => $branchId,
                    'project_name' => $name,
                    'is_active' => true,
                ]);
            }
        }
    }
}
