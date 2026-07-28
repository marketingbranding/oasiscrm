<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Exports\UserImportTemplateExport;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Models\UserImportRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserImportValidationService
{
    public function __construct(
        private readonly UserAdministrationService $administration,
        private readonly OrganizationScopeService $scope,
        private readonly ReportingHierarchyService $hierarchy,
    ) {}

    /** @param array<int, array{row_number:int, raw_data:array<string, string>, parser_errors:array<int, string>}> $parsedRows */
    public function validate(array $parsedRows, User $actor): array
    {
        $roles = Role::query()->with('permissions')->whereIn('slug', UserImportTemplateExport::ROLE_SLUGS)->get();
        $branches = Branch::query()->get();
        $projects = LeadMaster::query()->with('branch')->get();
        $allowedRoleIds = $this->administration->availableRoles($actor)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $allowedBranchIds = $this->allowedBranchIds($actor);
        $allowedProjectIds = $this->allowedProjectIds($actor);

        $fileEmails = collect($parsedRows)->map(fn (array $row) => $this->key($row['raw_data']['email']))->filter()->values();
        $supervisorEmails = collect($parsedRows)->map(fn (array $row) => $this->key($row['raw_data']['supervisor_email']))->filter()->values();
        $lookupEmails = $fileEmails->merge($supervisorEmails)->unique()->values()->all();
        $existingUsers = User::query()
            ->with(['role.permissions', 'branch', 'branches', 'assignedProjects'])
            ->when($lookupEmails !== [], fn ($query) => $query->whereIn(DB::raw('LOWER(email)'), $lookupEmails), fn ($query) => $query->whereRaw('1 = 0'))
            ->get()->keyBy(fn (User $user) => $this->key($user->email));
        $emailCounts = $fileEmails->countBy();

        $rows = [];
        foreach ($parsedRows as $parsed) {
            $raw = $parsed['raw_data'];
            $errors = $parsed['parser_errors'];
            $warnings = [];
            $name = $this->clean($raw['name']);
            $email = $this->key($raw['email']);
            $roleSlug = $this->key($raw['role']);
            $role = $roles->first(fn (Role $candidate) => $this->key($candidate->slug) === $roleSlug);

            if ($name === '') {
                $errors[] = 'Nama wajib diisi.';
            } elseif (mb_strlen($name) > 255) {
                $errors[] = 'Nama maksimal 255 karakter.';
            }
            if ($email === '') {
                $errors[] = 'Email wajib diisi.';
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
                $errors[] = 'Email harus berupa alamat email yang valid dan maksimal 255 karakter.';
            }
            if ($email !== '' && ($emailCounts[$email] ?? 0) > 1) {
                $errors[] = 'Email muncul lebih dari satu kali dalam file.';
            }
            if ($email !== '' && $existingUsers->has($email)) {
                $errors[] = 'Email sudah digunakan oleh akun lain.';
            }

            if ($role === null || ! $role->is_active || ! in_array($roleSlug, UserImportTemplateExport::ROLE_SLUGS, true)) {
                $errors[] = 'Role harus salah satu dari enam role aktif yang diizinkan.';
            } elseif ($role->is_superadmin || ! in_array((int) $role->id, $allowedRoleIds, true)) {
                $errors[] = 'Anda tidak berwenang menetapkan role tersebut.';
            }

            $primaryBranch = $this->resolveBranch($raw['primary_branch'], $branches);
            if ($primaryBranch === null || ! $primaryBranch->is_active) {
                $errors[] = 'Cabang utama wajib diisi dengan nama atau kode cabang aktif yang valid.';
            } elseif (! in_array((int) $primaryBranch->id, $allowedBranchIds, true)) {
                $errors[] = 'Anda tidak berwenang menetapkan cabang utama tersebut.';
            }

            $branchIds = $primaryBranch?->is_active ? [(int) $primaryBranch->id] : [];
            $additionalBranchLabels = $this->list($raw['additional_branches']);
            foreach ($additionalBranchLabels as $label) {
                $branch = $this->resolveBranch($label, $branches);
                if ($branch === null || ! $branch->is_active) {
                    $errors[] = "Cabang tambahan '{$label}' tidak ditemukan atau tidak aktif.";

                    continue;
                }
                if (! in_array((int) $branch->id, $allowedBranchIds, true)) {
                    $errors[] = "Anda tidak berwenang menetapkan cabang '{$label}'.";

                    continue;
                }
                $branchIds[] = (int) $branch->id;
            }
            $branchIds = array_values(array_unique($branchIds));

            $primaryProject = null;
            if ($this->clean($raw['primary_project']) !== '') {
                $primaryProject = $this->resolveProject($raw['primary_project'], $projects, $branchIds);
                if ($primaryProject === null || ! $primaryProject->is_active) {
                    $errors[] = 'Proyek utama tidak ditemukan, ambigu, atau tidak aktif pada cabang yang ditetapkan.';
                } elseif (! in_array((int) $primaryProject->id, $allowedProjectIds, true)) {
                    $errors[] = 'Anda tidak berwenang menetapkan proyek utama tersebut.';
                } elseif (! in_array((int) $primaryProject->branch_id, $branchIds, true)) {
                    $errors[] = 'Proyek utama harus berada dalam cabang yang ditetapkan.';
                }
            }

            $projectIds = $primaryProject?->is_active ? [(int) $primaryProject->id] : [];
            $additionalProjectLabels = $this->list($raw['additional_projects']);
            foreach ($additionalProjectLabels as $label) {
                $project = $this->resolveProject($label, $projects, $branchIds);
                if ($project === null || ! $project->is_active) {
                    $errors[] = "Proyek tambahan '{$label}' tidak ditemukan, ambigu, atau tidak aktif pada cabang yang ditetapkan.";

                    continue;
                }
                if (! in_array((int) $project->id, $allowedProjectIds, true)) {
                    $errors[] = "Anda tidak berwenang menetapkan proyek '{$label}'.";

                    continue;
                }
                if (! in_array((int) $project->branch_id, $branchIds, true)) {
                    $errors[] = "Proyek '{$label}' harus berada dalam cabang yang ditetapkan.";

                    continue;
                }
                $projectIds[] = (int) $project->id;
            }
            $projectIds = array_values(array_unique($projectIds));

            if ($roleSlug === 'sales' && $primaryProject === null) {
                $errors[] = 'Role sales wajib memiliki satu proyek utama aktif.';
            } elseif ($projectIds !== [] && $primaryProject === null) {
                $errors[] = 'Pilih satu proyek utama untuk penugasan proyek pengguna.';
            }

            $status = $this->key($raw['status']) ?: AccountStatus::PendingInvitation->value;
            if ($status === AccountStatus::Active->value) {
                $errors[] = 'Status active tidak didukung untuk impor. Gunakan pending_invitation atau invited.';
            } elseif (in_array($status, [AccountStatus::Suspended->value, AccountStatus::Inactive->value], true)) {
                $errors[] = "Status {$status} tidak dapat digunakan untuk impor pengguna baru.";
            } elseif (! in_array($status, [AccountStatus::PendingInvitation->value, AccountStatus::Invited->value], true)) {
                $errors[] = 'Status harus pending_invitation atau invited.';
            }

            $storageKey = $email !== '' && ($emailCounts[$email] ?? 0) === 1 ? $email : '#'.$parsed['row_number'];
            $rows[$storageKey] = [
                'row_number' => $parsed['row_number'],
                'raw_data' => $raw,
                'normalized_data' => [
                    'name' => $name,
                    'email' => $email,
                    'role_slug' => $roleSlug,
                    'role_id' => $role?->id,
                    'primary_branch_id' => $primaryBranch?->id,
                    'additional_branches' => $additionalBranchLabels,
                    'branch_ids' => $branchIds,
                    'primary_project_id' => $primaryProject?->id,
                    'additional_projects' => $additionalProjectLabels,
                    'project_ids' => $projectIds,
                    'supervisor_email' => $this->key($raw['supervisor_email']),
                    'status' => $status,
                ],
                'errors' => array_values(array_unique($errors)),
                'warnings' => $warnings,
                'role' => $role,
                'preflight_valid' => $role?->is_active
                    && in_array((int) $role?->id, $allowedRoleIds, true)
                    && $primaryBranch?->is_active
                    && $branchIds !== []
                    && array_diff($branchIds, $allowedBranchIds) === []
                    && array_diff($projectIds, $allowedProjectIds) === []
                    && ($roleSlug !== 'sales' || $primaryProject?->is_active),
            ];
        }

        $fileRowsByEmail = collect($rows)->filter(fn (array $row, string $key) => ! str_starts_with($key, '#'));
        foreach ($rows as $key => &$row) {
            $supervisorEmail = $row['normalized_data']['supervisor_email'];
            if ($supervisorEmail === '') {
                continue;
            }
            if ($supervisorEmail === $row['normalized_data']['email']) {
                $row['errors'][] = 'Pengguna tidak dapat menjadi atasan dirinya sendiri.';

                continue;
            }

            if ($fileRowsByEmail->has($supervisorEmail)) {
                $supervisorRow = $fileRowsByEmail->get($supervisorEmail);
                if (! $supervisorRow['preflight_valid']) {
                    $row['errors'][] = 'Atasan dalam file memiliki role, cabang, atau proyek yang tidak valid.';

                    continue;
                }
                if ($this->hierarchy->roleRank($supervisorRow['normalized_data']['role_slug']) < $this->hierarchy->roleRank($row['normalized_data']['role_slug'])) {
                    $row['errors'][] = 'Atasan harus memiliki tingkat kewenangan yang setara atau lebih tinggi.';

                    continue;
                }
                if (! $this->isGlobalRole($supervisorRow['role']) && ! $this->sharesAssignment($row['normalized_data'], $supervisorRow['normalized_data'])) {
                    $row['errors'][] = 'Atasan harus berbagi cabang atau proyek dengan pengguna.';

                    continue;
                }
                $row['warnings'][] = 'Atasan juga berasal dari file ini dan akan ditautkan setelah akun dibuat.';

                continue;
            }

            /** @var User|null $supervisor */
            $supervisor = $existingUsers->get($supervisorEmail);
            if ($supervisor === null) {
                $row['errors'][] = 'Email atasan tidak ditemukan.';
            } elseif (! $supervisor->is_active || ! $supervisor->isAccountActive()) {
                $row['errors'][] = 'Atasan harus merupakan pengguna aktif.';
            } elseif ($this->hierarchy->roleRank($supervisor) < $this->hierarchy->roleRank($row['normalized_data']['role_slug'])) {
                $row['errors'][] = 'Atasan harus memiliki tingkat kewenangan yang setara atau lebih tinggi.';
            } elseif (! $this->isGlobalUser($supervisor) && ! $this->sharesExistingAssignment($row['normalized_data'], $supervisor)) {
                $row['errors'][] = 'Atasan harus berbagi cabang atau proyek dengan pengguna.';
            } else {
                $row['normalized_data']['supervisor_user_id'] = $supervisor->id;
            }
        }
        unset($row);

        $this->markCycles($rows);

        return collect($rows)->map(function (array $row) {
            $row['errors'] = array_values(array_unique($row['errors']));
            $row['warnings'] = array_values(array_unique($row['warnings']));
            $row['validation_status'] = $row['errors'] !== []
                ? UserImportRow::VALIDATION_ERROR
                : ($row['warnings'] !== [] ? UserImportRow::VALIDATION_WARNING : UserImportRow::VALIDATION_VALID);
            unset($row['role'], $row['preflight_valid']);

            return $row;
        })->sortBy('row_number')->values()->all();
    }

    private function allowedBranchIds(User $actor): array
    {
        return ($actor->isSuperadmin() || $actor->hasPrimaryRole('pusat'))
            ? Branch::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->scope->branchIds($actor);
    }

    private function allowedProjectIds(User $actor): array
    {
        return ($actor->isSuperadmin() || $actor->hasPrimaryRole('pusat'))
            ? LeadMaster::query()->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->scope->projectIds($actor);
    }

    private function resolveBranch(string $label, Collection $branches): ?Branch
    {
        $key = $this->key($label);
        $matches = $branches->filter(fn (Branch $branch) => $branch->is_active
            && in_array($key, [$this->key($branch->name), $this->key($branch->code)], true));

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function resolveProject(string $label, Collection $projects, array $branchIds): ?LeadMaster
    {
        $key = $this->key($label);
        $matches = $projects->filter(function (LeadMaster $project) use ($key, $branchIds) {
            if (! $project->is_active || ! in_array((int) $project->branch_id, $branchIds, true)) {
                return false;
            }
            $labels = [
                $this->key($project->project_name),
                $this->key($project->project_name.' - '.$project->branch?->name),
            ];

            return in_array($key, $labels, true);
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function sharesAssignment(array $employee, array $supervisor): bool
    {
        return array_intersect($employee['branch_ids'], $supervisor['branch_ids']) !== []
            || array_intersect($employee['project_ids'], $supervisor['project_ids']) !== [];
    }

    private function sharesExistingAssignment(array $employee, User $supervisor): bool
    {
        $branchIds = $supervisor->branches->filter(fn (Branch $branch) => $branch->is_active && $branch->pivot->can_view)
            ->pluck('id');
        if ($supervisor->branch?->is_active) {
            $branchIds->push($supervisor->branch_id);
        }
        $branchIds = $branchIds->filter()->map(fn ($id) => (int) $id)->unique()->all();
        $today = today()->toDateString();
        $projectIds = $supervisor->assignedProjects->filter(fn (LeadMaster $project) => $project->pivot->is_active
            && $project->is_active
            && ($project->pivot->assignment_start_date === null || $project->pivot->assignment_start_date->toDateString() <= $today)
            && ($project->pivot->assignment_end_date === null || $project->pivot->assignment_end_date->toDateString() >= $today))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_intersect($employee['branch_ids'], $branchIds) !== []
            || array_intersect($employee['project_ids'], $projectIds) !== [];
    }

    private function isGlobalUser(User $user): bool
    {
        return $user->isSuperadmin() || ($user->hasPrimaryRole('pusat') && $user->canViewAllBranches());
    }

    private function isGlobalRole(?Role $role): bool
    {
        return $role?->is_superadmin || ($role?->slug === 'pusat'
            && $role->permissions->contains(fn ($permission) => str_ends_with($permission->slug, '.view_all')));
    }

    private function markCycles(array &$rows): void
    {
        $edges = [];
        foreach ($rows as $key => $row) {
            $supervisor = $row['normalized_data']['supervisor_email'];
            if ($supervisor !== '' && isset($rows[$supervisor]) && ! str_starts_with($key, '#')) {
                $edges[$key] = $supervisor;
            }
        }

        foreach (array_keys($edges) as $start) {
            $path = [];
            $positions = [];
            $current = $start;
            while (isset($edges[$current])) {
                if (isset($positions[$current])) {
                    foreach (array_slice($path, $positions[$current]) as $email) {
                        $rows[$email]['errors'][] = 'Penugasan atasan dalam file membentuk siklus pelaporan.';
                    }
                    break;
                }
                $positions[$current] = count($path);
                $path[] = $current;
                $current = $edges[$current];
            }
        }
    }

    private function list(string $value): array
    {
        return collect(explode(';', $value))->map($this->clean(...))->filter()
            ->unique(fn (string $item) => $this->key($item))->values()->all();
    }

    private function clean(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    }

    private function key(string $value): string
    {
        return mb_strtolower($this->clean($value));
    }
}
