<?php

namespace Tests\Feature;

use Database\Seeders\BranchSeeder;
use Database\Seeders\LeadMasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionBranchProjectSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_use_canonical_names_and_preserve_renamed_sheet_identities(): void
    {
        DB::table('lead_master')->delete();

        $this->seed(BranchSeeder::class);
        $this->seed(LeadMasterSeeder::class);

        $this->assertSame([
            'BDG' => 'KC BANDUNG',
            'JPR' => 'KC JEPARA',
            'MDN' => 'KC MADIUN',
            'MGL' => 'KC MAGELANG',
            'MLG' => 'KC MALANG',
            'PKL' => 'KC BATANG',
            'PST' => 'Kantor Pusat',
            'PWR' => 'KC PURWOREJO',
            'SOL' => 'KC SOLO',
        ], DB::table('branches')->orderBy('code')->pluck('name', 'code')->all());

        $this->assertSame(33, DB::table('lead_master')->count());
        $this->assertDatabaseHas('lead_master', ['project_name' => 'Marison Regency Wagir Malang', 'sheet_project_name' => 'Wagir']);
        $this->assertDatabaseHas('lead_master', ['project_name' => 'Marison Kalinegoro', 'sheet_project_name' => 'Jonggrangan']);
        $this->assertDatabaseHas('lead_master', ['project_name' => 'Jeruksawit 4', 'sheet_project_name' => null]);
        $this->assertDatabaseMissing('lead_master', ['project_name' => 'Wagir']);
        $this->assertDatabaseMissing('lead_master', ['project_name' => 'Jonggrangan']);
    }
}
