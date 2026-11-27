<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\ProjectTransferBatch;
use App\Models\ProjectTransferItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ProjectTransferService
{
    /** @var array<string, string> */
    private const PROJECT_TABLES = [
        'kavlings' => 'project_id',
        'sales_leads' => 'project_id',
        'consumer_applications' => 'project_id',
        'consumer_nups' => 'project_id',
        'consumer_import_batches' => 'project_id',
        'consumer_migration_reconciliations' => 'project_id',
        'content_items' => 'sales_project_id',
        'dana_talangans' => 'project_id',
        'expenses' => 'project_id',
        'sales_onboarding_proposals' => 'project_id',
        'marison_project_mappings' => 'oasis_project_id',
        'marison_import_transactions' => 'resolved_project_id',
    ];

    /** @return array<string, mixed> */
    public function preview(User $actor, Branch $source, Branch $target, array $projectIds, array $userMapping = []): array
    {
        $this->authorizeActor($actor);
        $this->validateBranches($source, $target);
        $projects = $this->projects($source, $projectIds);
        $leadIds = DB::table('sales_leads')->whereIn('project_id', $projects->pluck('id'))->pluck('id');
        $impact = [];

        foreach (self::PROJECT_TABLES as $table => $column) {
            $impact[$table] = Schema::hasTable($table) && Schema::hasColumn($table, $column)
                ? DB::table($table)->whereIn($column, $projects->pluck('id'))->count()
                : 0;
        }
        $impact['sales_lead_status_histories'] = Schema::hasTable('sales_lead_status_histories')
            ? DB::table('sales_lead_status_histories')->whereIn('sales_lead_id', $leadIds)->count()
            : 0;
        $impact['sales_lead_site_visits'] = Schema::hasTable('sales_lead_site_visits')
            ? DB::table('sales_lead_site_visits')->whereIn('sales_lead_id', $leadIds)->count()
            : 0;
        $impact['project_user'] = Schema::hasTable('project_user')
            ? DB::table('project_user')->whereIn('project_id', $projects->pluck('id'))->count()
            : 0;
        $impact['mapped_users'] = Schema::hasTable('branch_user')
            ? $this->sourceUserIds($source->id, $projects->pluck('id')->all())->count()
            : 0;
        $impact['organization_assignments'] = Schema::hasTable('organization_assignments')
            ? DB::table('organization_assignments')->whereIn('project_id', $projects->pluck('id'))->count()
            : 0;
        $impact['branch_only'] = [
            'sales_daily_plans' => DB::table('sales_daily_plans')->where('branch_id', $source->id)->count(),
            'promos' => DB::table('promos')->where('branch_id', $source->id)->count(),
            'sales_agenda_evidence_archives' => DB::table('sales_agenda_evidence_archives')->where('branch_id', $source->id)->count(),
        ];

        $blockers = [];
        if ($this->hasSentLead($projects->pluck('id')->all())) {
            $blockers[] = 'Lead proyek sudah terkirim ke spreadsheet.';
        }
        if ($this->hasAttemptedDelivery($projects->pluck('id')->all())) {
            $blockers[] = 'Lead memiliki delivery_attempted_at tanpa hasil akhir.';
        }
        if ($this->hasActiveOutbox($projects->pluck('id')->all())) {
            $blockers[] = 'Lead masih memiliki pengiriman atau rekonsiliasi aktif.';
        }
        if ($this->hasConflictingDana($projects->pluck('id')->all())) {
            $blockers[] = 'Dana Talangan proyek memiliki status conflict.';
        }
        if ($projects->isEmpty()) {
            $blockers[] = 'Proyek tidak ditemukan pada cabang asal.';
        }
        if ($this->missingUserMapping($projects->pluck('id')->all(), $userMapping, $source->id)) {
            $blockers[] = 'Mapping user proyek belum lengkap.';
        }

        return [
            'source_branch_id' => $source->id,
            'target_branch_id' => $target->id,
            'projects' => $projects->map(fn (LeadMaster $project): array => ['id' => $project->id, 'name' => $project->project_name])->all(),
            'impact' => $impact,
            'blockers' => $blockers,
            'warnings' => $this->warnings($source, $target, $userMapping),
        ];
    }

    /** @return array{batch: ProjectTransferBatch, impact: array<string, mixed>} */
    public function execute(User $actor, Branch $source, Branch $target, array $projectIds, array $userMapping): array
    {
        $this->authorizeActor($actor);
        $this->validateBranches($source, $target);
        $this->assertMapping($source->id, $projectIds, $userMapping);
        $maintenance = app(ModuleMaintenanceService::class);
        $alreadyMaintained = $maintenance->isUnderMaintenance('projects');
        if ($alreadyMaintained && ! $actor->isSuperadmin()) {
            throw new AuthorizationException('Pemindahan proyek tidak dapat dimulai saat modul sedang dalam pemeliharaan.');
        }
        $maintenanceStarted = $actor->isSuperadmin() && ! $alreadyMaintained;
        if ($maintenanceStarted) {
            $maintenance->enable('projects', $actor, ['message' => 'Pemindahan proyek antar cabang sedang berjalan.']);
        }

        try {
            $normalizedProjectIds = array_values(array_unique(array_map('intval', $projectIds)));
            sort($normalizedProjectIds);
            $userMapping = is_array($userMapping) ? array_map('strval', $userMapping) : [];
            ksort($userMapping);
            $existingBatch = ProjectTransferBatch::query()
                ->where('source_branch_id', $source->id)
                ->where('target_branch_id', $target->id)
                ->whereIn('status', ['committed', 'completed', 'cache_failed'])
                ->latest('id')
                ->get()
                ->first(function (ProjectTransferBatch $batch) use ($normalizedProjectIds, $userMapping): bool {
                    $batchProjectIds = array_map('intval', $batch->project_ids ?? []);
                    sort($batchProjectIds);
                    $batchMapping = array_map('strval', $batch->user_mapping ?? []);
                    ksort($batchMapping);

                    return $batchProjectIds === $normalizedProjectIds && $batchMapping === $userMapping;
                });
            if ($existingBatch !== null && $existingBatch->status !== 'committed') {
                return ['batch' => $existingBatch->load('items'), 'impact' => $existingBatch->impact_summary ?? []];
            }
            if ($existingBatch?->status === 'committed') {
                $this->rebuildBatchCaches($actor, $existingBatch);

                return ['batch' => $existingBatch->fresh('items'), 'impact' => $existingBatch->fresh()->impact_summary ?? []];
            }
            $preview = $this->preview($actor, $source, $target, $projectIds, $userMapping);
            if ($preview['blockers'] !== []) {
                throw ValidationException::withMessages(['transfer' => $preview['blockers']]);
            }

            $batch = DB::transaction(function () use ($actor, $source, $target, $projectIds, $userMapping, $preview): ProjectTransferBatch {
                $lockedSource = Branch::query()->lockForUpdate()->findOrFail($source->id);
                $lockedTarget = Branch::query()->lockForUpdate()->findOrFail($target->id);
                $projects = LeadMaster::query()->where('branch_id', $lockedSource->id)->whereIn('id', $projectIds)->lockForUpdate()->get();
                if ($projects->count() !== count(array_unique(array_map('intval', $projectIds)))) {
                    throw ValidationException::withMessages(['project_ids' => 'Semua proyek harus berada di cabang asal.']);
                }
                $this->assertTransferableLocked($projects->pluck('id')->all(), $lockedSource->id);

                $batch = ProjectTransferBatch::query()->create([
                    'source_branch_id' => $lockedSource->id,
                    'target_branch_id' => $lockedTarget->id,
                    'created_by' => $actor->id,
                    'project_ids' => $projects->pluck('id')->values()->all(),
                    'user_mapping' => $userMapping,
                    'status' => 'committed',
                    'executed_at' => now(),
                    'impact_summary' => $preview['impact'],
                ]);

                foreach ($projects as $project) {
                    $this->snapshotUpdate($batch, 'lead_master', $project->id, ['branch_id' => $lockedTarget->id]);
                }
                foreach (self::PROJECT_TABLES as $table => $projectColumn) {
                    if ($table === 'sales_leads') {
                        continue;
                    }
                    $this->moveProjectRows($batch, $table, $projectColumn, $projects->pluck('id')->all(), $lockedSource->id, $lockedTarget->id);
                }
                $this->moveProjectRows($batch, 'sales_leads', 'project_id', $projects->pluck('id')->all(), $lockedSource->id, $lockedTarget->id);
                $this->moveLeadChildren($batch, $projects->pluck('id')->all(), $lockedSource->id, $lockedTarget->id);
                $this->moveProjectMemberships($batch, $projects->pluck('id')->all(), $lockedTarget->id, $userMapping);
                $this->moveUsers($batch, $lockedSource->id, $lockedTarget->id, $projects->pluck('id')->all(), $userMapping);
                $this->copyMigrationState($batch, $lockedSource->id, $lockedTarget->id);
                $batch->update(['executed_at' => now()]);

                ActivityLog::query()->create([
                    'causer_id' => $actor->id,
                    'subject_type' => ProjectTransferBatch::class,
                    'subject_id' => $batch->id,
                    'event' => 'project_transfer_completed',
                    'description' => 'Pemindahan proyek antar cabang selesai.',
                    'properties' => ['source_branch_id' => $lockedSource->id, 'target_branch_id' => $lockedTarget->id, 'project_ids' => $projects->pluck('id')->all(), 'impact' => $preview['impact'], 'user_mapping' => $userMapping],
                ]);

                return $batch;
            }, 3);

            $batch = $batch->fresh('items');
            $cacheRebuild = $this->rebuildCaches($actor, $source, $target);
            $batch->impact_summary = [
                ...($batch->impact_summary ?? []),
                'cache_rebuild' => $cacheRebuild,
            ];
            $batch->status = collect($cacheRebuild)->every(fn (array $result): bool => $result['ok'] ?? false)
                ? 'completed'
                : 'cache_failed';
            $batch->save();

            return ['batch' => $batch->fresh('items'), 'impact' => $batch->impact_summary];
        } finally {
            if ($maintenanceStarted) {
                $maintenance->disable('projects', $actor);
            }
        }
    }

    public function rebuildBatchCaches(User $actor, ProjectTransferBatch $batch): array
    {
        $this->authorizeActor($actor);

        $locked = DB::transaction(function () use ($batch): ProjectTransferBatch {
            $locked = ProjectTransferBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! in_array($locked->status, ['committed', 'completed', 'cache_failed', 'rollback_cache_failed'], true)) {
                throw ValidationException::withMessages(['batch' => 'Batch belum siap untuk pembangunan ulang cache.']);
            }
            if ($locked->rolled_back_at !== null && $locked->status !== 'rollback_cache_failed') {
                throw ValidationException::withMessages(['batch' => 'Batch belum siap untuk pembangunan ulang cache.']);
            }

            return $locked->load(['sourceBranch', 'targetBranch']);
        });

        $result = $this->rebuildCaches($actor, $locked->sourceBranch, $locked->targetBranch);
        DB::transaction(function () use ($locked, $result): void {
            $current = ProjectTransferBatch::query()->lockForUpdate()->findOrFail($locked->id);
            $isRollback = $current->rolled_back_at !== null;
            $current->update([
                'status' => collect($result)->every(fn (array $item): bool => $item['ok'] ?? false)
                    ? ($isRollback ? 'rolled_back' : 'completed')
                    : ($isRollback ? 'rollback_cache_failed' : 'cache_failed'),
                'impact_summary' => [...($current->impact_summary ?? []), 'cache_rebuild' => $result],
            ]);
        });

        return $result;
    }

    public function rebuildCaches(User $actor, Branch $source, Branch $target): array
    {
        $this->authorizeActor($actor);
        $this->validateBranches($source, $target);
        $results = [];
        $branchIds = [$source->id, $target->id];
        sort($branchIds);

        try {
            app(SyncLockService::class)->runManyOrThrow(
                array_merge(
                    array_map(fn (int $branchId): string => 'database:branch:'.$branchId, $branchIds),
                    array_map(fn (int $branchId): string => 'konsumen-progress:branch:'.$branchId, $branchIds),
                ),
                function () use ($source, $target, $actor, &$results): void {
                    $results['database.source'] = app(DatabaseSheetSyncService::class)->syncBranch($source, $actor->id, false);
                    $results['database.target'] = app(DatabaseSheetSyncService::class)->syncBranch($target, $actor->id, false);
                    $results['consumer_progress.source'] = app(KonsumenProgressSyncService::class)->syncBranch($source, $actor->id, false);
                    $results['consumer_progress.target'] = app(KonsumenProgressSyncService::class)->syncBranch($target, $actor->id, false);
                },
            );
        } catch (Throwable $exception) {
            report($exception);
            $results['cache_lock'] = ['ok' => false, 'status' => 'syncing', 'message' => 'Cache sedang dibangun ulang.'];
        }

        return $results;
    }

    public function rollback(User $actor, ProjectTransferBatch $batch): void
    {
        $this->authorizeActor($actor);
        $maintenance = app(ModuleMaintenanceService::class);
        $alreadyMaintained = $maintenance->isUnderMaintenance('projects');
        if ($alreadyMaintained && ! $actor->isSuperadmin()) {
            throw new AuthorizationException('Pembatalan pemindahan proyek tidak dapat dimulai saat modul sedang dalam pemeliharaan.');
        }
        $maintenanceStarted = $actor->isSuperadmin() && ! $alreadyMaintained;
        if ($maintenanceStarted) {
            $maintenance->enable('projects', $actor, ['message' => 'Pembatalan pemindahan proyek sedang berjalan.']);
        }

        try {
            DB::transaction(function () use ($actor, $batch): void {
                $locked = ProjectTransferBatch::query()->lockForUpdate()->findOrFail($batch->id);
                if ($locked->rolled_back_at !== null) {
                    return;
                }
                if (! in_array($locked->status, ['committed', 'completed', 'cache_failed'], true)) {
                    throw ValidationException::withMessages(['rollback' => 'Batch tidak berada pada status yang dapat dibatalkan.']);
                }
                $changed = [];
                foreach ($locked->items as $item) {
                    $row = DB::table($item->table_name)->where('id', $item->row_id)->lockForUpdate()->first();
                    $expected = $item->new_values;
                    if ($row === null || $expected === null || (array) $row !== $expected) {
                        $changed[] = ['table' => $item->table_name, 'id' => $item->row_id];
                    }
                }
                if ($changed !== []) {
                    throw ValidationException::withMessages(['rollback' => ['Baris berubah setelah batch: '.json_encode($changed)]]);
                }
                foreach ($locked->items as $item) {
                    if ($item->was_created) {
                        DB::table($item->table_name)->where('id', $item->row_id)->delete();
                    } else {
                        DB::table($item->table_name)->where('id', $item->row_id)->update($item->old_values ?? []);
                    }
                }
                $locked->update(['status' => 'rolled_back', 'rolled_back_at' => now()]);
                ActivityLog::query()->create(['causer_id' => $actor->id, 'subject_type' => ProjectTransferBatch::class, 'subject_id' => $locked->id, 'event' => 'project_transfer_rolled_back', 'description' => 'Batch pemindahan proyek dibatalkan.', 'properties' => ['batch_id' => $locked->id]]);
            }, 3);

            $cacheRebuild = $this->rebuildCaches($actor, $batch->sourceBranch()->firstOrFail(), $batch->targetBranch()->firstOrFail());
            DB::transaction(function () use ($batch, $cacheRebuild): void {
                $current = ProjectTransferBatch::query()->lockForUpdate()->findOrFail($batch->id);
                $current->update([
                    'status' => collect($cacheRebuild)->every(fn (array $item): bool => $item['ok'] ?? false) ? 'rolled_back' : 'rollback_cache_failed',
                    'impact_summary' => [...($current->impact_summary ?? []), 'rollback_cache_rebuild' => $cacheRebuild],
                ]);
            });
        } finally {
            if ($maintenanceStarted) {
                $maintenance->disable('projects', $actor);
            }
        }
    }

    private function authorizeActor(User $actor): void
    {
        $actor->loadMissing('role');
        if (! $actor->isSuperadmin()
            || (bool) session('impersonating_user_id')
            || ! $actor->is_active
            || $actor->email_verified_at === null
            || $actor->password_changed_at === null
            || (bool) $actor->must_change_password
            || ! $actor->hasPermission('projects.manage')) {
            throw new AuthorizationException('Hanya Super Admin utama yang aktif, terverifikasi, dan berizin yang dapat memindahkan proyek antar cabang.');
        }
    }

    private function validateBranches(Branch $source, Branch $target): void
    {
        if (! $source->is_active || ! $target->is_active || blank($source->sheet_id) || blank($target->sheet_id) || $source->id === $target->id || $source->sheet_id === $target->sheet_id) {
            throw ValidationException::withMessages(['branch_id' => 'Cabang asal dan tujuan harus aktif, memiliki Sheet berbeda, dan bukan cabang yang sama.']);
        }
    }

    private function projects(Branch $source, array $ids): Collection
    {
        return LeadMaster::query()->where('branch_id', $source->id)->whereIn('id', $ids)->where('is_active', true)->get();
    }

    private function moveProjectRows(ProjectTransferBatch $batch, string $table, string $column, array $projectIds, int $sourceBranchId, int $targetBranchId): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        $query = DB::table($table)->whereIn($column, $projectIds);
        if (Schema::hasColumn($table, 'branch_id')) {
            $query->where('branch_id', $sourceBranchId);
        }
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        $rows = $query->lockForUpdate()->get();
        if (Schema::hasColumn($table, 'branch_id') && DB::table($table)->whereIn($column, $projectIds)->where(function ($query) use ($sourceBranchId): void {
            $query->whereNull('branch_id')->orWhere('branch_id', '!=', $sourceBranchId);
        })->exists()) {
            throw ValidationException::withMessages(['transfer' => "Data {$table} memiliki branch_id yang tidak sesuai cabang asal."]);
        }
        foreach ($rows as $row) {
            $values = (array) $row;
            if (array_key_exists('branch_id', $values)) {
                $updates = ['branch_id' => $targetBranchId];
                if (array_key_exists('updated_at', $values)) {
                    $updates['updated_at'] = now();
                }
                $this->snapshotAndUpdate($batch, $table, (int) $row->id, $values, $updates);
            } else {
                $this->snapshot($batch, $table, (int) $row->id, $values);
            }
        }
    }

    private function moveLeadChildren(ProjectTransferBatch $batch, array $projectIds, int $sourceBranchId, int $targetBranchId): void
    {
        $leadIds = DB::table('sales_leads')->whereIn('project_id', $projectIds)->lockForUpdate()->pluck('id');
        foreach (['sales_lead_status_histories', 'sales_lead_stage_histories', 'sales_lead_site_visits', 'sales_lead_slik_attempts', 'sales_lead_consumer_links', 'sales_lead_freelance_links', 'sales_lead_akad_links', 'sales_lead_lifecycle_reconciliation_items'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'sales_lead_id')) {
                continue;
            }
            $query = DB::table($table)->whereIn('sales_lead_id', $leadIds);
            foreach ($query->lockForUpdate()->get() as $row) {
                $values = (array) $row;
                if (array_key_exists('branch_id', $values)) {
                    if ($row->branch_id === null || (int) $row->branch_id !== $sourceBranchId) {
                        throw ValidationException::withMessages(['transfer' => "Data {$table} memiliki branch_id yang tidak sesuai cabang asal."]);
                    }
                    $updates = ['branch_id' => $targetBranchId];
                    if (array_key_exists('updated_at', $values)) {
                        $updates['updated_at'] = now();
                    }
                    $this->snapshotAndUpdate($batch, $table, (int) $row->id, $values, $updates);
                } else {
                    $this->snapshot($batch, $table, (int) $row->id, $values);
                }
            }
        }
    }

    private function moveProjectMemberships(ProjectTransferBatch $batch, array $projectIds, int $targetBranchId, array $mapping): void
    {
        if (! Schema::hasTable('project_user')) {
            return;
        }

        $assignments = DB::table('project_user')->whereIn('project_id', $projectIds)->lockForUpdate()->get();
        foreach ($assignments as $assignment) {
            $decision = $mapping[(string) $assignment->user_id] ?? $mapping[$assignment->user_id] ?? null;
            if (! in_array($decision, ['pindah', 'tetap', 'keduanya'], true)) {
                throw ValidationException::withMessages(['user_mapping' => 'Mapping user tidak valid.']);
            }
            if ($decision !== 'tetap') {
                continue;
            }

            $old = (array) $assignment;
            $updates = ['is_active' => false];
            if (Schema::hasColumn('project_user', 'assignment_end_date')) {
                $updates['assignment_end_date'] = today()->toDateString();
            }
            if (Schema::hasColumn('project_user', 'is_primary')) {
                $updates['is_primary'] = false;
            }
            if (Schema::hasColumn('project_user', 'updated_at')) {
                $updates['updated_at'] = now();
            }
            $this->snapshotAndUpdate($batch, 'project_user', (int) $assignment->id, $old, $updates);
        }
    }

    private function moveUsers(ProjectTransferBatch $batch, int $sourceId, int $targetId, array $projectIds, array $mapping): void
    {
        if (! Schema::hasTable('branch_user')) {
            return;
        }
        $userIds = $this->sourceUserIds($sourceId, $projectIds);
        foreach ($userIds as $userId) {
            $decision = $mapping[(string) $userId] ?? $mapping[$userId] ?? 'tetap';
            if (! in_array($decision, ['pindah', 'tetap', 'keduanya'], true)) {
                throw ValidationException::withMessages(['user_mapping' => 'Mapping user tidak valid.']);
            }
            $membership = DB::table('branch_user')->where('user_id', $userId)->where('branch_id', $sourceId)->lockForUpdate()->first();
            if ($membership !== null) {
                if ($decision === 'pindah') {
                    $this->snapshotAndUpdate($batch, 'branch_user', $membership->id, (array) $membership, ['is_current' => false, 'updated_at' => now()]);
                } else {
                    $this->snapshot($batch, 'branch_user', $membership->id, (array) $membership);
                }
            }
            if ($decision !== 'tetap' && $membership !== null) {
                $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();
                if ($decision === 'pindah') {
                    $this->snapshotAndUpdate($batch, 'users', $userId, (array) $user, ['branch_id' => $targetId, 'updated_at' => now()]);
                } else {
                    $this->snapshot($batch, 'users', $userId, (array) $user);
                }
                $targetMembership = DB::table('branch_user')->where('user_id', $userId)->where('branch_id', $targetId)->lockForUpdate()->first();
                if ($targetMembership === null) {
                    $membershipId = DB::table('branch_user')->insertGetId([
                        'user_id' => $userId,
                        'branch_id' => $targetId,
                        'membership_role' => $membership->membership_role ?? null,
                        'is_current' => true,
                        'can_view' => (bool) ($membership->can_view ?? true),
                        'can_edit' => (bool) ($membership->can_edit ?? false),
                        'can_sync' => (bool) ($membership->can_sync ?? false),
                        'can_manage_members' => (bool) ($membership->can_manage_members ?? false),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    ProjectTransferItem::query()->create([
                        'batch_id' => $batch->id,
                        'table_name' => 'branch_user',
                        'row_id' => $membershipId,
                        'old_values' => [],
                        'new_values' => (array) DB::table('branch_user')->where('id', $membershipId)->first(),
                        'was_created' => true,
                    ]);
                } else {
                    $this->snapshotAndUpdate($batch, 'branch_user', $targetMembership->id, (array) $targetMembership, [
                        'membership_role' => $membership->membership_role ?? null,
                        'is_current' => true,
                        'can_view' => (bool) ($membership->can_view ?? true),
                        'can_edit' => (bool) ($membership->can_edit ?? false),
                        'can_sync' => (bool) ($membership->can_sync ?? false),
                        'can_manage_members' => (bool) ($membership->can_manage_members ?? false),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function copyMigrationState(ProjectTransferBatch $batch, int $sourceId, int $targetId): void
    {
        if (! Schema::hasTable('branch_migration_states')) {
            return;
        }
        $state = DB::table('branch_migration_states')->where('branch_id', $sourceId)->lockForUpdate()->first();
        if ($state === null) {
            return;
        }
        $target = DB::table('branch_migration_states')->where('branch_id', $targetId)->first();
        if ($target === null) {
            $targetIdValue = DB::table('branch_migration_states')->insertGetId([
                'branch_id' => $targetId,
                'state' => $state->state,
                'changed_by' => $state->changed_by,
                'changed_at' => $state->changed_at,
                'notes' => $state->notes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            ProjectTransferItem::query()->create([
                'batch_id' => $batch->id,
                'table_name' => 'branch_migration_states',
                'row_id' => $targetIdValue,
                'old_values' => [],
                'new_values' => (array) DB::table('branch_migration_states')->where('id', $targetIdValue)->first(),
                'was_created' => true,
            ]);

            return;
        }

        $this->snapshotAndUpdate($batch, 'branch_migration_states', (int) $target->id, (array) $target, [
            'state' => $state->state,
            'changed_by' => $state->changed_by,
            'changed_at' => $state->changed_at,
            'notes' => $state->notes,
            'updated_at' => now(),
        ]);
    }

    private function snapshotUpdate(ProjectTransferBatch $batch, string $table, int $id, array $new): void
    {
        $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
        $this->snapshotAndUpdate($batch, $table, $id, (array) $row, $new);
    }

    private function snapshotAndUpdate(ProjectTransferBatch $batch, string $table, int $id, array $old, array $updates): void
    {
        $item = $this->snapshot($batch, $table, $id, $old);
        DB::table($table)->where('id', $id)->update($updates);
        $item->update(['new_values' => (array) DB::table($table)->where('id', $id)->first()]);
    }

    private function snapshot(ProjectTransferBatch $batch, string $table, int $id, array $old): ProjectTransferItem
    {
        return ProjectTransferItem::query()->create([
            'batch_id' => $batch->id,
            'table_name' => $table,
            'row_id' => $id,
            'old_values' => $old,
            'new_values' => $old,
            'was_created' => false,
            'old_updated_at' => $old['updated_at'] ?? null,
        ]);
    }

    private function missingUserMapping(array $projectIds, array $mapping, ?int $sourceBranchId = null): bool
    {
        if (! Schema::hasTable('branch_user')) {
            return false;
        }
        $userIds = $this->sourceUserIds((int) $sourceBranchId, $projectIds);

        return $userIds->contains(fn ($userId): bool => ! array_key_exists((string) $userId, $mapping) && ! array_key_exists($userId, $mapping));
    }

    private function assertMapping(int $sourceBranchId, array $projectIds, array $mapping): void
    {
        if (! Schema::hasTable('branch_user')) {
            return;
        }
        $userIds = $this->sourceUserIds($sourceBranchId, $projectIds);
        $allowed = $userIds->mapWithKeys(fn ($userId): array => [(string) $userId => true]);
        foreach ($mapping as $userId => $decision) {
            if (! $allowed->has((string) $userId) || ! in_array($decision, ['pindah', 'tetap', 'keduanya'], true)) {
                throw ValidationException::withMessages(['user_mapping' => 'Mapping user tidak valid.']);
            }
        }
        foreach ($userIds as $userId) {
            $decision = $mapping[(string) $userId] ?? $mapping[$userId] ?? 'tetap';
            if (! in_array($decision, ['pindah', 'tetap', 'keduanya'], true)) {
                throw ValidationException::withMessages(['user_mapping' => 'Mapping user tidak valid.']);
            }
        }
    }

    private function sourceUserIds(int $sourceBranchId, array $projectIds): \Illuminate\Support\Collection
    {
        return DB::table('project_user')
            ->join('branch_user', function ($join) use ($sourceBranchId): void {
                $join->on('branch_user.user_id', '=', 'project_user.user_id')->where('branch_user.branch_id', $sourceBranchId)->where('branch_user.is_current', true);
            })
            ->whereIn('project_user.project_id', $projectIds)
            ->where('project_user.is_active', true)
            ->distinct()
            ->pluck('project_user.user_id');
    }

    private function hasSentLead(array $projectIds): bool
    {
        return DB::table('sales_leads')->whereIn('project_id', $projectIds)->whereNotNull('last_synced_at')->exists();
    }

    private function hasAttemptedDelivery(array $projectIds): bool
    {
        return DB::table('sales_leads')->whereIn('project_id', $projectIds)->whereNull('deleted_at')->whereNotNull('delivery_attempted_at')->whereNull('last_synced_at')->exists();
    }

    private function hasConflictingDana(array $projectIds): bool
    {
        return Schema::hasTable('dana_talangans') && DB::table('dana_talangans')->whereIn('project_id', $projectIds)->where('sync_status', 'conflict')->exists();
    }

    private function hasActiveOutbox(array $projectIds): bool
    {
        if (! Schema::hasTable('sales_leads')) {
            return false;
        }

        return DB::table('sales_leads')
            ->whereIn('project_id', $projectIds)
            ->whereNull('deleted_at')
            ->whereIn('sync_status', ['pending_update', 'pending_delete', 'sync_failed', 'syncing', 'conflict'])
            ->exists();
    }

    private function assertTransferableLocked(array $projectIds, int $sourceBranchId): void
    {
        $leads = DB::table('sales_leads')->whereIn('project_id', $projectIds)->lockForUpdate()->get();
        if ($leads->contains(fn ($lead): bool => $lead->last_synced_at !== null)) {
            throw ValidationException::withMessages(['transfer' => ['Lead proyek sudah terkirim ke spreadsheet.']]);
        }
        if ($leads->contains(fn ($lead): bool => $lead->delivery_attempted_at !== null && $lead->last_synced_at === null)) {
            throw ValidationException::withMessages(['transfer' => ['Lead memiliki delivery_attempted_at tanpa hasil akhir.']]);
        }
        if ($leads->contains(fn ($lead): bool => in_array($lead->sync_status, ['pending_update', 'pending_delete', 'sync_failed', 'syncing', 'conflict'], true))) {
            throw ValidationException::withMessages(['transfer' => ['Lead masih memiliki pengiriman atau rekonsiliasi aktif.']]);
        }
        foreach ($leads as $lead) {
            if ((int) $lead->branch_id !== $sourceBranchId) {
                throw ValidationException::withMessages(['transfer' => ['Data sales_leads memiliki branch_id yang tidak sesuai cabang asal.']]);
            }
        }
    }

    /** @return list<string> */
    private function warnings(Branch $source, Branch $target, array $mapping): array
    {
        $warnings = ['Data branch-only tetap di cabang asal.'];
        $mappedUserIds = array_keys($mapping);
        if (! Schema::hasTable('roles') || ! DB::table('users')->where('branch_id', $target->id)->whereIn('role_id', DB::table('roles')->whereIn('slug', ['supervisor', 'sales_coordinator'])->pluck('id'))->exists()) {
            $warnings[] = 'Cabang tujuan belum memiliki supervisor atau sales_coordinator.';
        }
        if ($mappedUserIds === []) {
            $warnings[] = 'Mapping user belum diisi; default Tetap akan digunakan.';
        }

        return $warnings;
    }
}
