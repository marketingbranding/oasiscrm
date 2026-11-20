<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('oasis:roles-v3-migrate {--dry-run}')]
#[Description('Memetakan role lama ke role staff PRD v3 secara idempoten.')]
class MigrateRolesV3 extends Command
{
    /** @var array<string, string> */
    private const STAFF_TYPES = [
        'admin' => 'admin_cabang',
        'sas' => 'sales',
        'sam' => 'marketing',
        'staff' => 'umum',
    ];

    public function handle(): int
    {
        try {
            $report = DB::transaction(function (): array {
                $roles = Role::query()
                    ->whereIn('slug', array_keys(self::STAFF_TYPES))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('slug');
                $staffRole = $roles->get('staff');
                $candidates = $this->candidates($roles);

                if ($candidates->isNotEmpty() && $staffRole === null && ! $this->option('dry-run')) {
                    throw new RuntimeException('Role staff tidak tersedia; tidak ada data yang diubah.');
                }

                $report = $this->report($candidates, $roles, $staffRole);
                if (! $this->option('dry-run')) {
                    foreach ($candidates as $candidate) {
                        DB::table('users')->where('id', $candidate->id)->update([
                            'staff_type' => $candidate->staff_type ?? self::STAFF_TYPES[$candidate->role_slug],
                            'scope_level' => 'cabang',
                            'role_id' => $staffRole->id,
                            'updated_at' => now(),
                        ]);
                    }

                    if ($report['differences'] !== []) {
                        throw new RuntimeException('Perbedaan permission atau isAdminCabang terdeteksi; transaksi dibatalkan.');
                    }

                    $legacyRoleIds = $roles
                        ->filter(fn (Role $role, string $slug): bool => $slug !== 'staff')
                        ->pluck('id')
                        ->all();
                    if ($legacyRoleIds !== []) {
                        Role::query()->whereIn('id', $legacyRoleIds)->update(['is_active' => false]);
                    }
                }

                return $report;
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: '.($this->option('dry-run') ? 'dry-run' : 'migrasi'));
        $this->line('Kandidat: '.$report['candidates']);
        $this->line('Perubahan role: '.$report['role_changes']);
        $this->line('Perubahan atribut: '.$report['attribute_changes']);
        $this->line('Role target staff: '.($report['staff_role_available'] ? 'tersedia' : 'hilang'));
        $this->line('Role lama tersedia: '.$report['legacy_roles'].'/'.count(self::STAFF_TYPES));
        $this->line('Konflik: '.($report['staff_role_available'] || $report['candidates'] === 0 ? 'tidak ada' : 'role target staff tidak tersedia'));
        foreach ($report['by_staff_type'] as $staffType => $ids) {
            $this->line($staffType.': '.count($ids).' ['.implode(',', $ids).']');
        }
        $this->line('Perbedaan: '.($report['differences'] === [] ? 'tidak ada' : json_encode($report['differences'], JSON_THROW_ON_ERROR)));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<string, Role>  $roles
     * @return Collection<int, object>
     */
    private function candidates(Collection $roles): Collection
    {
        $roleIds = $roles->pluck('id')->all();
        if ($roleIds === []) {
            return collect();
        }

        return User::query()
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereIn('users.role_id', $roleIds)
            ->where(function ($query): void {
                $query->where('roles.slug', '!=', 'staff')
                    ->orWhereNull('users.staff_type')
                    ->orWhere('users.scope_level', '!=', 'cabang')
                    ->orWhereNull('users.scope_level');
            })
            ->get([
                'users.id',
                'users.role_id',
                'users.staff_type',
                'users.scope_level',
                'roles.slug as role_slug',
            ]);
    }

    /**
     * @param  Collection<int, object>  $candidates
     * @param  Collection<string, Role>  $roles
     * @return array{candidates: int, role_changes: int, attribute_changes: int, legacy_roles: int, staff_role_available: bool, by_staff_type: array<string, array<int, int>>, differences: array<int, array<string, mixed>>}
     */
    private function report(Collection $candidates, Collection $roles, ?Role $staffRole): array
    {
        $byStaffType = array_fill_keys(array_values(self::STAFF_TYPES), []);
        $differences = [];
        $roleChanges = 0;
        $attributeChanges = 0;

        foreach ($candidates as $candidate) {
            $staffType = $candidate->staff_type ?? self::STAFF_TYPES[$candidate->role_slug];
            $byStaffType[$staffType][] = (int) $candidate->id;
            $roleChanges += (int) ($candidate->role_id !== $staffRole?->id);
            $attributeChanges += (int) ($candidate->staff_type === null || $candidate->scope_level !== 'cabang');

            $beforeRoleSlug = $candidate->role_slug;
            if ($beforeRoleSlug === 'staff') {
                $beforeRoleSlug = match ($staffType) {
                    'admin_cabang' => 'admin',
                    'sales' => 'sas',
                    'marketing' => 'sam',
                    'umum' => 'staff',
                    default => null,
                };
            }
            $beforeRoleId = $beforeRoleSlug === null ? null : $roles->get($beforeRoleSlug)?->id;
            $beforePermissions = $beforeRoleId === null ? [] : $this->permissionSlugs($beforeRoleId);
            $afterRoleSlug = match ($staffType) {
                'admin_cabang' => 'admin',
                'sales' => 'sas',
                'marketing' => 'sam',
                'umum' => 'staff',
                default => null,
            };
            $afterRoleId = $afterRoleSlug === null ? null : $roles->get($afterRoleSlug)?->id;
            $afterPermissions = $afterRoleId === null ? [] : $this->permissionSlugs($afterRoleId);
            $beforeAdminCabang = $beforeRoleSlug === 'admin';
            $afterAdminCabang = $staffType === 'admin_cabang';

            if ($beforePermissions !== $afterPermissions || $beforeAdminCabang !== $afterAdminCabang) {
                $differences[] = [
                    'id' => (int) $candidate->id,
                    'permission_before' => $beforePermissions,
                    'permission_after' => $afterPermissions,
                    'isAdminCabang_before' => $beforeAdminCabang,
                    'isAdminCabang_after' => $afterAdminCabang,
                ];
            }
        }

        return [
            'candidates' => $candidates->count(),
            'role_changes' => $roleChanges,
            'attribute_changes' => $attributeChanges,
            'legacy_roles' => $roles->filter(fn (Role $role, string $slug): bool => $slug !== 'staff')->count(),
            'staff_role_available' => $staffRole !== null,
            'by_staff_type' => $byStaffType,
            'differences' => $differences,
        ];
    }

    /** @return array<int, string> */
    private function permissionSlugs(int $roleId): array
    {
        return DB::table('role_permission')
            ->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('role_permission.role_id', $roleId)
            ->orderBy('permissions.slug')
            ->pluck('permissions.slug')
            ->all();
    }
}
