<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProjectAdministrationService
{
    public function __construct(
        private readonly OptimisticLockService $locks,
        private readonly SalesLeadSheetOptionService $sheetOptions,
    ) {}

    public function create(array $data): LeadMaster
    {
        $data['project_name'] = Str::squish((string) $data['project_name']);
        $data['sheet_project_name'] = blank($data['sheet_project_name'] ?? null) ? null : (string) $data['sheet_project_name'];
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return DB::transaction(function () use ($data): LeadMaster {
            $branch = Branch::query()->where('is_active', true)->lockForUpdate()->find($data['branch_id']);
            if (! $branch) {
                throw ValidationException::withMessages(['branch_id' => 'Cabang tujuan harus aktif.']);
            }

            $collision = $this->identityCollision(null, $branch, $data['project_name'], $data['sheet_project_name']);
            if ($collision !== null) {
                throw ValidationException::withMessages([$collision[0] => $collision[1]]);
            }

            return LeadMaster::query()->create([
                'branch_id' => $branch->id,
                'project_name' => $data['project_name'],
                'sheet_project_name' => $data['sheet_project_name'],
                'is_active' => $data['is_active'],
            ]);
        });
    }

    public function update(Request $request, LeadMaster $project, array $data, User $actor): mixed
    {
        $data['project_name'] = Str::squish((string) $data['project_name']);
        $branch = Branch::query()->where('is_active', true)->find($data['branch_id']);
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Cabang tujuan harus aktif.']);
        }

        $renaming = $this->renamesCanonical($project, $data);
        $aliasChanged = array_key_exists('sheet_project_name', $data) && $this->changed($project->sheet_project_name, $data['sheet_project_name']);

        if ($aliasChanged && blank($data['sheet_project_name'])) {
            $data['sheet_project_name'] = null;
        } elseif ($aliasChanged) {
            try {
                $exact = $this->sheetOptions->exactOption(
                    $this->sheetOptions->forBranch($branch)['project'],
                    $data['sheet_project_name'],
                );
            } catch (Throwable) {
                throw ValidationException::withMessages([
                    'sheet_project_name' => 'Opsi proyek spreadsheet sedang tidak tersedia. Identitas spreadsheet tidak diubah.',
                ]);
            }
            if ($exact === null) {
                throw ValidationException::withMessages(['sheet_project_name' => 'Identitas proyek tidak tersedia pada data_kav cabang terpilih.']);
            }
            $data['sheet_project_name'] = $exact;
        } else {
            $data['sheet_project_name'] = $project->sheet_project_name;
        }

        if ($renaming && blank($data['sheet_project_name']) && filled($project->project_name)) {
            // Canonical rename must not silently change the wire identity: keep the
            // old project_name as the spreadsheet identity because it is the current
            // known wire value; verify it remotely only when a workbook exists.
            $data['sheet_project_name'] = $project->project_name;
            if (filled($branch->sheet_id)) {
                $exact = $this->exactRemoteOption($branch, (string) $project->project_name);
                if ($exact !== null) {
                    $data['sheet_project_name'] = $exact;
                } else {
                    throw ValidationException::withMessages(['sheet_project_name' => 'Nama proyek lama tidak tersedia pada data_kav cabang terpilih; identitas spreadsheet tidak diubah otomatis.']);
                }
            }
        }

        if ((int) $project->branch_id !== (int) $branch->id && filled($branch->sheet_id) && blank($data['sheet_project_name'])) {
            $exact = $this->exactRemoteOption($branch, (string) $data['project_name']);
            if ($exact === null) {
                throw ValidationException::withMessages([
                    'sheet_project_name' => 'Identitas proyek tidak tersedia pada data_kav cabang tujuan; pemindahan cabang dibatalkan.',
                ]);
            }
            $data['sheet_project_name'] = Str::squish($exact) === Str::squish($data['project_name']) ? null : $exact;
        }

        return $this->locks->execute($request, $project, $data['expected_updated_at'] ?? null, function (LeadMaster $locked) use ($data, $actor): LeadMaster {
            $branch = Branch::query()->where('is_active', true)->lockForUpdate()->find($data['branch_id']);
            if (! $branch) {
                throw ValidationException::withMessages(['branch_id' => 'Cabang tujuan harus aktif.']);
            }

            $collision = $this->identityCollision($locked, $branch, $data['project_name'], $data['sheet_project_name']);
            if ($collision !== null) {
                throw ValidationException::withMessages([$collision[0] => $collision[1]]);
            }

            if ((int) $locked->branch_id !== (int) $branch->id) {
                $dependencies = $this->moveDependencies($locked);
                if ($dependencies !== []) {
                    throw ValidationException::withMessages([
                        'branch_id' => 'Proyek tidak dapat dipindahkan karena masih memiliki dependensi: '.implode(', ', $dependencies).'.',
                    ]);
                }
            }

            $oldName = $locked->project_name;
            $oldBranchId = (int) $locked->branch_id;
            $locked->update([
                'branch_id' => $branch->id,
                'project_name' => $data['project_name'],
                'sheet_project_name' => $data['sheet_project_name'],
            ]);

            if ($oldName !== $locked->project_name) {
                ActivityLog::query()->create([
                    'causer_id' => $actor->id,
                    'subject_type' => LeadMaster::class,
                    'subject_id' => $locked->id,
                    'event' => 'project_renamed',
                    'description' => 'Nama proyek diubah dari '.$oldName.' menjadi '.$locked->project_name,
                    'properties' => [
                        'project_id' => $locked->id,
                        'old_name' => $oldName,
                        'new_name' => $locked->project_name,
                        'actor_id' => $actor->id,
                    ],
                ]);
            }

            if ($oldBranchId !== (int) $locked->branch_id) {
                $this->logProjectMoved($actor, $locked, $oldBranchId, (int) $locked->branch_id);
            }

            return $locked;
        });
    }

    public function archive(Request $request, LeadMaster $project, User $actor, ?string $expected)
    {
        return $this->locks->execute($request, $project, $expected, function (LeadMaster $locked) use ($actor): bool {
            if (! $locked->is_active) {
                return false;
            }

            $locked->update(['is_active' => false]);
            ActivityLog::query()->create([
                'causer_id' => $actor->id,
                'subject_type' => LeadMaster::class,
                'subject_id' => $locked->id,
                'event' => 'project_archived',
                'description' => 'Proyek '.$locked->project_name.' dinonaktifkan.',
                'properties' => [
                    'project_id' => $locked->id,
                    'project_name' => $locked->project_name,
                    'actor_id' => $actor->id,
                ],
            ]);

            return true;
        });
    }

    private function logProjectMoved(User $actor, LeadMaster $locked, int $oldBranchId, int $newBranchId): void
    {
        ActivityLog::query()->create([
            'causer_id' => $actor->id,
            'subject_type' => LeadMaster::class,
            'subject_id' => $locked->id,
            'event' => 'project_moved',
            'description' => 'Proyek '.$locked->project_name.' dipindahkan antar cabang.',
            'properties' => [
                'project_id' => $locked->id,
                'project_name' => $locked->project_name,
                'old_branch_id' => $oldBranchId,
                'new_branch_id' => $newBranchId,
                'actor_id' => $actor->id,
            ],
        ]);
    }

    /**
     * Reject canonical/alias identity collisions against other active projects in
     * the destination branch, in both directions, plus self alias==canonical.
     *
     * @return array{0: string, 1: string}|null
     */
    private function identityCollision(?LeadMaster $locked, Branch $branch, string $newName, ?string $newAlias): ?array
    {
        $newNameNorm = mb_strtolower($newName);
        $newAliasNorm = blank($newAlias) ? null : mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $newAlias)) ?? '');

        if ($newAliasNorm !== null && $newAliasNorm !== '' && $newAliasNorm === $newNameNorm) {
            return ['sheet_project_name', 'Identitas proyek spreadsheet tidak boleh sama dengan nama proyek.'];
        }

        $candidates = LeadMaster::query()
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->when($locked !== null, fn ($query) => $query->whereKeyNot($locked->id))
            ->lockForUpdate()
            ->get(['id', 'project_name', 'sheet_project_name']);

        foreach ($candidates as $other) {
            $otherName = mb_strtolower(Str::squish((string) $other->project_name));
            $otherAlias = blank($other->sheet_project_name)
                ? null
                : mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $other->sheet_project_name)) ?? '');

            if ($newNameNorm !== '' && ($newNameNorm === $otherName || $newNameNorm === $otherAlias)) {
                return ['project_name', 'Nama proyek aktif sudah digunakan pada cabang terpilih.'];
            }
            if ($newAliasNorm !== null && $newAliasNorm !== '' && ($newAliasNorm === $otherName || $newAliasNorm === $otherAlias)) {
                return ['sheet_project_name', 'Identitas proyek aktif sudah digunakan pada cabang terpilih.'];
            }
        }

        return null;
    }

    private function renamesCanonical(LeadMaster $project, array $data): bool
    {
        return mb_strtolower((string) ($data['project_name'] ?? '')) !== mb_strtolower(trim((string) $project->project_name));
    }

    private function exactRemoteOption(Branch $branch, string $value): ?string
    {
        try {
            $remote = $this->sheetOptions->forBranch($branch)['project'];
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'sheet_project_name' => 'Google tidak tersedia untuk memverifikasi identitas proyek spreadsheet. Ubah nama proyek dibatalkan.',
            ]);
        }

        return $this->sheetOptions->exactOption($remote, $value);
    }

    private function changed(?string $current, ?string $requested): bool
    {
        return ($current ?? '') !== ($requested ?? '');
    }

    private function moveDependencies(LeadMaster $project): array
    {
        $checks = [
            ['kavlings', 'project_id', 'kavling'],
            ['project_user', 'project_id', 'penugasan pengguna'],
            ['sales_leads', 'project_id', 'lead Sales'],
            ['expenses', 'project_id', 'pengeluaran'],
            ['consumer_applications', 'project_id', 'pengajuan konsumen'],
            ['content_items', 'sales_project_id', 'agenda Sales'],
            ['dana_talangans', 'project_id', 'Dana Talangan'],
            ['consumer_import_batches', 'project_id', 'batch impor konsumen'],
        ];
        $dependencies = [];
        foreach ($checks as [$table, $column, $label]) {
            $count = DB::table($table)->where($column, $project->id)->count();
            if ($count > 0) {
                $dependencies[] = $count.' '.$label;
            }
        }
        if (filled($project->sheet_project_name)) {
            $dependencies[] = 'identitas spreadsheet masih terikat';
        }

        // ponytail: full PHP-side normalize plucks one column; switch to SQL
        // expression index if these tables grow past comfortable pluck size.
        $labels = collect([$project->project_name, $project->sheet_project_name])
            ->filter(fn ($label) => filled($label))
            ->map(fn ($label) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $label)) ?? ''))
            ->unique()
            ->values();

        if ($labels->isNotEmpty()) {
            $matchCount = function (string $table, ?string $nullColumn) use ($labels): int {
                return DB::table($table)
                    ->when($nullColumn !== null, fn ($query) => $query->whereNull($nullColumn))
                    ->whereNotNull('project_name')
                    ->pluck('project_name')
                    ->filter(fn ($name) => $labels->contains(mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '')))
                    ->count();
            };

            $legacyContent = $matchCount('content_items', 'sales_project_id');
            if ($legacyContent > 0) {
                $dependencies[] = $legacyContent.' agenda Sales dengan nama proyek teks';
            }

            $legacyDana = $matchCount('dana_talangans', 'project_id');
            if ($legacyDana > 0) {
                $dependencies[] = $legacyDana.' Dana Talangan dengan nama proyek teks';
            }
        }

        $remoteLeads = DB::table('sales_leads')->where('project_id', $project->id)->where(function ($query): void {
            $query->whereNotNull('external_sync_id')
                ->orWhereNotNull('remote_target_branch_id')
                ->orWhereNotNull('last_synced_at')
                ->orWhereNotNull('delivery_attempted_at');
        })->count();
        if ($remoteLeads > 0) {
            $dependencies[] = $remoteLeads.' lead terikat atau pernah dikirim ke spreadsheet';
        }

        return $dependencies;
    }
}
