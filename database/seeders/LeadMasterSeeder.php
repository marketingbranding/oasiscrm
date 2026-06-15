<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\LeadMaster;
use Illuminate\Database\Seeder;

class LeadMasterSeeder extends Seeder
{
    public function run(): void
    {
        $branchMap = [];
        foreach (Branch::all() as $b) {
            $branchMap[strtoupper($b->name)] = $b->id;
            $branchMap[$b->code] = $b->id;
        }

        $rows = [
            ['branch' => 'MALANG', 'project' => 'JABUNG MALANG', 'source' => 'Event: CFD', 'category' => 'Event'],
            ['branch' => 'MADIUN', 'project' => 'WAGIR', 'source' => 'Event: Pameran', 'category' => 'Brosur'],
            ['branch' => 'SOLO', 'project' => 'MADIUN 2 PERLUASAN', 'source' => 'Event: Lainnya', 'category' => 'Promo'],
            ['branch' => 'MAGELANG', 'project' => 'TERAS BOYOLALI', 'source' => 'Brosur', 'category' => 'Outdoor'],
            ['branch' => 'PURWOREJO', 'project' => 'JERUKSAWIT 4', 'source' => 'Promo: DP', 'category' => 'Lainnya'],
            ['branch' => 'PURWOKERTO', 'project' => 'WONOGIRI', 'source' => 'Promo: Elektronik', 'category' => null],
            ['branch' => 'JEPARA', 'project' => 'SRAGEN', 'source' => 'Promo: Kanopi', 'category' => null],
            ['branch' => 'PEKALONGAN', 'project' => 'WONOSARI JS', 'source' => 'Promo: Lainnya', 'category' => null],
            ['branch' => 'SUMEDANG', 'project' => 'REJOSARI GONDANGREJO', 'source' => 'Roundtag', 'category' => null],
            ['branch' => null, 'project' => 'MUSUK BOYOLALI', 'source' => 'Baliho', 'category' => null],
            ['branch' => null, 'project' => 'WONOSARI', 'source' => 'Umbul-umbul', 'category' => null],
            ['branch' => null, 'project' => 'WONOSARI KIOS', 'source' => 'Lainnya', 'category' => null],
            ['branch' => null, 'project' => 'PIYUNGAN', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'JONGGRANGAN', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'DLIMAS TEGALREJO', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'TAMPINGAN TEGALREJO', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'SIDOAGUNG TEMPURAN', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'PIYAMAN WONOSARI', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'CAWANG', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'MUTIARA GARDEN 5', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'SOKAWERA PURWOKERTO', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'MLONGGO 1', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'MLONGGO 2', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'KUWASEN', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'MRANGGEN', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'PATI', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'KECEPAK BATANG', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'KANDEMAN BATANG', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'TEGAL', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'WONOPRINGGO PEKALONGAN', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'SARENGAT BATANG', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'MAJALAYA', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'MAJALAYA 2', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'SUMEDANG', 'source' => null, 'category' => null],
        ];

        foreach ($rows as $row) {
            LeadMaster::create([
                'branch_id' => $row['branch'] ? ($branchMap[$row['branch']] ?? null) : null,
                'project_name' => $row['project'],
                'lead_source' => $row['source'],
                'category' => $row['category'],
                'is_active' => true,
            ]);
        }
    }
}
