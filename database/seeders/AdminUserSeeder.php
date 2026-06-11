<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $superadmin = Role::where('slug', 'superadmin')->first();
        $admin = Role::where('slug', 'admin')->first();

        if ($superadmin) {
            User::updateOrCreate([
                'email' => 'admin@oasis.com',
            ], [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'role_id' => $superadmin->id,
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        }

        if ($admin) {
            $branches = Branch::all();
            $users = [
                ['name' => 'Admin Malang', 'email' => 'malang@oasis.com'],
                ['name' => 'Admin Madiun', 'email' => 'madiun@oasis.com'],
                ['name' => 'Admin Solo', 'email' => 'solo@oasis.com'],
                ['name' => 'Admin Magelang', 'email' => 'magelang@oasis.com'],
                ['name' => 'Admin Purworejo', 'email' => 'purworejo@oasis.com'],
                ['name' => 'Admin Jepara', 'email' => 'jepara@oasis.com'],
                ['name' => 'Admin Pekalongan', 'email' => 'pekalongan@oasis.com'],
                ['name' => 'Admin Bandung', 'email' => 'bandung@oasis.com'],
            ];

            foreach ($users as $i => $userData) {
                $branch = $branches[$i] ?? null;
                if ($branch) {
                    User::updateOrCreate(
                        ['email' => $userData['email']],
                        [
                            'name' => $userData['name'],
                            'password' => Hash::make('password'),
                            'role_id' => $admin->id,
                            'branch_id' => $branch->id,
                            'is_active' => true,
                            'email_verified_at' => now(),
                        ]
                    );
                }
            }
        }
    }
}
