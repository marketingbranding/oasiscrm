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
        if ((bool) $this->option('dry-run')) {
            $roles = Role::query()->whereIn('slug', array_keys(self::STAFF_TYPES))->get()->keyBy('slug');
            $staffRole = $roles->get('staff');
            $candidates = $this->candidates($roles);

            $roleChanges = $staffRole === null
                ? $candidates->count()
                : $candidates->where('role_id', '!=', $staffRole->id)->count();
            $attributeChanges = $candidates->filter(
                fn (object $candidate): bool => $candidate->staff_type === null || $candidate->scope_level === null,
            )->count();
            $targetStatus = $staffRole === null ? 'hilang' : 'tersedia';
            $conflict = $candidates->isNotEmpty() && $staffRole === null
                ? 'role target staff tidak tersedia'
                : 'tidak ada';

            $this->line('Mode: dry-run');
            $this->line('Kandidat: '.$candidates->count());
            $this->line('Perubahan role: '.$roleChanges);
            $this->line('Perubahan atribut: '.$attributeChanges);
            $this->line('Role target staff: '.$targetStatus);
            $this->line('Konflik: '.$conflict);
            $this->line('Role lama tersedia: '.$roles->count().'/'.count(self::STAFF_TYPES));

            return self::SUCCESS;
        }

        try {
            $mapped = 0;
            $result = DB::transaction(function () use (&$mapped): array {
                $roles = Role::query()
                    ->whereIn('slug', array_keys(self::STAFF_TYPES))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('slug');
                $staffRole = $roles->get('staff');
                $candidates = $this->candidates($roles);

                if ($candidates->isNotEmpty() && $staffRole === null) {
                    throw new RuntimeException('Role staff tidak tersedia; tidak ada data yang diubah.');
                }

                foreach ($candidates as $candidate) {
                    $values = [
                        'staff_type' => $candidate->staff_type ?? self::STAFF_TYPES[$candidate->role_slug],
                        'scope_level' => $candidate->scope_level ?? ($candidate->branch_id === null ? 'pusat' : 'cabang'),
                    ];
                    DB::table('users')->where('id', $candidate->id)->update($values);
                    DB::table('users')->where('id', $candidate->id)->update(['role_id' => $staffRole->id]);
                    $mapped++;
                }

                $legacyRoleIds = $roles
                    ->filter(fn (Role $role, string $slug): bool => $slug !== 'staff')
                    ->pluck('id')
                    ->all();
                if ($legacyRoleIds !== []) {
                    Role::query()->whereIn('id', $legacyRoleIds)->update(['is_active' => false]);
                }

                return [
                    'candidates' => $candidates->count(),
                    'legacy_roles' => $roles->filter(fn (Role $role, string $slug): bool => $slug !== 'staff')->count(),
                ];
            });
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Mode: migrasi');
        $this->line('Kandidat: '.$result['candidates']);
        $this->line('Dipetakan: '.$mapped);
        $this->line('Role lama dinonaktifkan: '.$result['legacy_roles']);

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
                    ->orWhereNull('users.scope_level');
            })
            ->get([
                'users.id',
                'users.role_id',
                'users.staff_type',
                'users.scope_level',
                'users.branch_id',
                'roles.slug as role_slug',
            ]);
    }
}
