<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::permissions() as $permission) {
            Permission::query()->updateOrCreate(['slug' => $permission['slug']], $permission);
        }

        $permissionIds = Permission::query()->pluck('id', 'slug');
        foreach (PermissionCatalog::rolePermissions() as $roleSlug => $slugs) {
            Role::query()->where('slug', $roleSlug)->first()?->permissions()->sync(
                collect($slugs)->map(fn (string $slug) => $permissionIds[$slug])->all()
            );
        }

        Role::query()->where('slug', 'superadmin')->first()?->permissions()->sync($permissionIds->values()->all());
    }
}
