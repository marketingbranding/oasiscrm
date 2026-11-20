<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['slug' => 'sales', 'name' => 'Sales', 'description' => 'Tim penjualan dengan akses ke data sendiri.'],
            ['slug' => 'sales_coordinator', 'name' => 'Koordinator Sales', 'description' => 'Koordinator penjualan dengan akses ke data tim.'],
            ['slug' => 'supervisor', 'name' => 'Supervisor', 'description' => 'Supervisor dengan akses tim dan penugasan operasional.'],
            ['slug' => 'manager', 'name' => 'Manager', 'description' => 'Manager dengan akses pemantauan berdasarkan penugasan.'],
            ['slug' => 'ass_manager', 'name' => 'Ass. Manager', 'description' => 'Ass. Manager dengan akses pemantauan berdasarkan penugasan.', 'authority_level' => 40],
            ['slug' => 'branch_manager', 'name' => 'Branch Manager', 'description' => 'Pimpinan cabang dengan akses operasional tingkat cabang.'],
            ['slug' => 'sas', 'name' => 'Staff Admin · Sales', 'description' => 'Profil izin legacy untuk Staff Admin bertipe Sales.'],
            ['slug' => 'sam', 'name' => 'Staff Admin · Marketing', 'description' => 'Profil izin legacy untuk Staff Admin bertipe Marketing.'],
            ['slug' => 'pusat', 'name' => 'Tim Pusat', 'description' => 'Tim pusat dengan akses operasional lintas cabang.'],
            ['slug' => 'superadmin', 'name' => 'Super Admin', 'description' => 'Administrator sistem dengan seluruh izin.'],
            ['slug' => 'admin', 'name' => 'Staff Admin · Admin Cabang', 'description' => 'Profil izin legacy untuk Staff Admin bertipe Admin Cabang.'],
            ['slug' => 'staff', 'name' => 'Staff Admin · Umum', 'description' => 'Profil izin legacy untuk Staff Admin bertipe Umum.'],
        ];

        foreach ($roles as $role) {
            Role::query()->updateOrCreate(['slug' => $role['slug']], [
                ...$role,
                'is_superadmin' => $role['slug'] === 'superadmin',
                'is_active' => true,
            ]);
        }

        Role::query()->where('slug', '!=', 'superadmin')->update(['is_superadmin' => false]);
    }
}
