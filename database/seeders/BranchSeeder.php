<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            ['code' => 'MLG', 'name' => 'KC MALANG', 'sheet_id' => '1tUbKZwOQ70nDuFZlYGN0A9hQg9I77_LUldxxYTf8BNA'],
            ['code' => 'MDN', 'name' => 'KC MADIUN', 'sheet_id' => '12su1i6R_xHM2mYkO-wwTW8zoSJgoAAGz6Fd_hU8lyhM'],
            ['code' => 'SOL', 'name' => 'KC SOLO', 'sheet_id' => '1YqfRUgXZGX87UxLIrv6Ebg9LBqVyV_Gmy8RZ5ziMwQ0'],
            ['code' => 'MGL', 'name' => 'KC MAGELANG', 'sheet_id' => '1EqSNyj29bxXd1fLdkH1JmBfzQttTIqTlRFgpiSd6R1M'],
            ['code' => 'PWR', 'name' => 'KC PURWOREJO', 'sheet_id' => '1nqQ4P0NC-pcFtNJvfa93yw-LnB5qFlGVaZP12pJmQR4'],
            ['code' => 'JPR', 'name' => 'KC JEPARA', 'sheet_id' => '1Gn1k0L7WWCoD0GsbSQJuRcxxvoUr16MIjFe7Le3rAg4'],
            ['code' => 'PKL', 'name' => 'KC BATANG', 'sheet_id' => '13Lum588xQcU0ySGlwDkbH5zBFqqFbhgS3TTfLWJrAVM'],
            ['code' => 'BDG', 'name' => 'KC BANDUNG', 'sheet_id' => '1AdsQAaWpOTKl6n5s5djiTKyg04HI2gBdMsib6TsggR8'],
            ['code' => 'PST', 'name' => 'Kantor Pusat', 'sheet_id' => null],
        ];

        foreach ($branches as $branch) {
            Branch::updateOrCreate(
                ['code' => $branch['code']],
                [
                    'name' => $branch['name'],
                    'sheet_id' => $branch['sheet_id'],
                    'is_active' => true,
                ]
            );
        }
    }
}
