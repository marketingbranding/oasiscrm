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
            ['slug' => 'branch_manager', 'name' => 'Branch Manager', 'description' => 'Pimpinan cabang dengan akses operasional tingkat cabang.'],
            ['slug' => 'pusat', 'name' => 'Tim Pusat', 'description' => 'Tim pusat dengan akses operasional lintas cabang.'],
            ['slug' => 'superadmin', 'name' => 'Super Admin', 'description' => 'Administrator sistem dengan seluruh izin.'],
            ['slug' => 'admin', 'name' => 'Admin', 'description' => 'Peran lama untuk administrasi operasional cabang.'],
            ['slug' => 'staff', 'name' => 'Staff', 'description' => 'Peran lama untuk pelaksana operasional cabang.'],
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
