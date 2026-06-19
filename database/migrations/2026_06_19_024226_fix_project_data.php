<?php

use App\Models\Branch;
use App\Models\LeadMaster;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Branch::where('name', 'Bandung')->update(['name' => 'Sumedang']);

        $branchId = function (string $code): ?int {
            return Branch::where('code', $code)->value('id');
        };

        $projects = [
            ['code' => 'MLG', 'projects' => ['Jabung Malang', 'Wagir']],
            ['code' => 'MDN', 'projects' => ['Madiun 2 Perluasan']],
            ['code' => 'SOL', 'projects' => ['Teras Boyolali', 'Jeruksawit 4', 'Wonogiri', 'Sragen', 'Wonosari Js', 'Rejosari Gondangrejo', 'Musuk Boyolali']],
            ['code' => 'MGL', 'projects' => ['Wonosari', 'Wonosari Kios', 'Piyungan', 'Jonggrangan', 'Dlimas Tegalrejo', 'Tampingan Tegalrejo', 'Sidoagung Tempuran', 'Piyaman Wonosari', 'Cawang']],
            ['code' => 'PWR', 'projects' => ['Mutiara Garden 5']],
            ['code' => 'JPR', 'projects' => ['Mlonggo 1', 'Mlonggo 2', 'Kuwasen', 'Mranggen', 'Pati']],
            ['code' => 'PKL', 'projects' => ['Kecepak Batang', 'Kandeman Batang', 'Tegal', 'Wonopringgo Pekalongan', 'Sarengat Batang']],
            ['code' => 'BDG', 'projects' => ['Majalaya', 'Majalaya 2', 'Sumedang']],
        ];

        LeadMaster::query()->delete();

        foreach ($projects as $entry) {
            $bid = $branchId($entry['code']);
            foreach ($entry['projects'] as $name) {
                LeadMaster::create([
                    'branch_id' => $bid,
                    'project_name' => $name,
                    'is_active' => true,
                ]);
            }
        }
    }

    public function down(): void
    {
        Branch::where('name', 'Sumedang')->update(['name' => 'Bandung']);

        LeadMaster::query()->delete();

        $branchId = function (string $code): ?int {
            return Branch::where('code', $code)->value('id');
        };

        $rows = [
            ['branch' => 'MLG', 'project' => 'Jabung Malang', 'source' => 'Event: CFD', 'category' => 'Event'],
            ['branch' => 'MDN', 'project' => 'Wagir', 'source' => 'Event: Pameran', 'category' => 'Brosur'],
            ['branch' => 'SOL', 'project' => 'Madiun 2 Perluasan', 'source' => 'Event: Lainnya', 'category' => 'Promo'],
            ['branch' => 'MGL', 'project' => 'Teras Boyolali', 'source' => 'Brosur', 'category' => 'Outdoor'],
            ['branch' => 'PWR', 'project' => 'Jeruksawit 4', 'source' => 'Promo: DP', 'category' => 'Lainnya'],
            ['branch' => null, 'project' => 'Wonogiri', 'source' => 'Promo: Elektronik', 'category' => null],
            ['branch' => null, 'project' => 'Sragen', 'source' => 'Promo: Kanopi', 'category' => null],
            ['branch' => null, 'project' => 'Wonosari Js', 'source' => 'Promo: Lainnya', 'category' => null],
            ['branch' => null, 'project' => 'Rejosari Gondangrejo', 'source' => 'Roundtag', 'category' => null],
            ['branch' => null, 'project' => 'Musuk Boyolali', 'source' => 'Baliho', 'category' => null],
            ['branch' => null, 'project' => 'Wonosari', 'source' => 'Umbul-umbul', 'category' => null],
            ['branch' => null, 'project' => 'Wonosari Kios', 'source' => 'Lainnya', 'category' => null],
            ['branch' => null, 'project' => 'Piyungan', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Jonggrangan', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Dlimas Tegalrejo', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Tampingan Tegalrejo', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Sidoagung Tempuran', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Piyaman Wonosari', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Cawang', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Mutiara Garden 5', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Sokawera Purwokerto', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Mlonggo 1', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Mlonggo 2', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Kuwasen', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Mranggen', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Pati', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Kecepak Batang', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Kandeman Batang', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Tegal', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Wonopringgo Pekalongan', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Sarengat Batang', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Majalaya', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Majalaya 2', 'source' => null, 'category' => null],
            ['branch' => null, 'project' => 'Sumedang', 'source' => null, 'category' => null],
        ];

        foreach ($rows as $row) {
            LeadMaster::create([
                'branch_id' => $row['branch'] ? $branchId($row['branch']) : null,
                'project_name' => $row['project'],
                'lead_source' => $row['source'],
                'category' => $row['category'],
                'is_active' => true,
            ]);
        }
    }
};
