<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::firstOrCreate([
            'slug' => 'superadmin',
        ], [
            'name' => 'Super Admin',
            'description' => 'Full access to all system features',
            'is_superadmin' => true,
        ]);

        Role::firstOrCreate([
            'slug' => 'admin',
        ], [
            'name' => 'Admin',
            'description' => 'Administrative access with limited system configuration',
            'is_superadmin' => false,
        ]);

        Role::firstOrCreate([
            'slug' => 'manager',
        ], [
            'name' => 'Manager',
            'description' => 'Branch-level management access',
            'is_superadmin' => false,
        ]);

        Role::firstOrCreate([
            'slug' => 'staff',
        ], [
            'name' => 'Staff',
            'description' => 'Regular staff with basic CRM access',
            'is_superadmin' => false,
        ]);

        Role::firstOrCreate([
            'slug' => 'pusat',
        ], [
            'name' => 'Pusat',
            'description' => 'Head office staff with cross-branch content access',
            'is_superadmin' => false,
        ]);
    }
}
