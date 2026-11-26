<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\ProjectTransferBatch;
use App\Models\ProjectTransferItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        'organization_assignments' => 'project_id',
    ];

    /** @return array<string, mixed> */
    public function preview(Branch $source, Branch $target, array $projectIds, array $userMapping = []): array
    {
        $this->validateBranches($source, $target);
        $projects = $this->projects($source, $projectIds);
        $leadIds = DB::table('sales_leads')->whereIn('project_id', $projects->pluck('id'))->pluck('id');
        $impact = [];

        foreach (self::PROJECT_TABLES as $table => $column) {
            $query = DB::table($table)->whereIn($column, $projects->pluck('id'));
            $impact[$table] = $query->count();
        }
        $impact['sales_lead_status_histories'] = DB::table('sales_lead_status_histories')->whereIn('sales_lead_id', $leadIds)->count();
        $impact['sales_lead_site_visits'] = DB::table('sales_lead_site_visits')->whereIn('sales_lead_id', $leadIds)->count();
        $impact['project_user'] = DB::table('project_user')->whereIn('project_id', $projects->pluck('id'))->count();
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
        if ($this->hasConflictingDana($projects->pluck('id')->all())) {
            $blockers[] = 'Dana Talangan proyek memiliki status conflict.';
        }
        if ($projects->isEmpty()) {
            $blockers[] = 'Proyek tidak ditemukan pada cabang asal.';
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
        $this->validateBranches($source, $target);
        $preview = $this->preview($source, $target, $projectIds, $userMapping);
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

            $batch = ProjectTransferBatch::query()->create([
                'source_branch_id' => $lockedSource->id,
                'target_branch_id' => $lockedTarget->id,
                'created_by' => $actor->id,
                'project_ids' => $projects->pluck('id')->values()->all(),
                'user_mapping' => $userMapping,
                'status' => 'completed',
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
                $this->moveProjectRows($batch, $table, $projectColumn, $projects->pluck('id')->all(), $lockedTarget->id);
            }
            $this->moveProjectRows($batch, 'sales_leads', 'project_id', $projects->pluck('id')->all(), $lockedTarget->id);
            $this->moveLeadChildren($batch, $projects->pluck('id')->all(), $lockedTarget->id);
            $this->moveUsers($batch, $lockedSource->id, $lockedTarget->id, $projects->pluck('id')->all(), $userMapping);
            $this->copyMigrationState($batch, $lockedSource->id, $lockedTarget->id);

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

        return ['batch' => $batch->fresh('items'), 'impact' => $preview['impact']];
    }

    public function rollback(User $actor, ProjectTransferBatch $batch): void
    {
        DB::transaction(function () use ($actor, $batch): void {
            $locked = ProjectTransferBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($locked->rolled_back_at !== null) {
                return;
            }
            $changed = [];
            foreach ($locked->items as $item) {
                $row = DB::table($item->table_name)->where('id', $item->row_id)->first();
                if ($row === null || ($item->old_updated_at !== null && $row->updated_at !== $item->created_at && $row->updated_at > $locked->executed_at)) {
                    $changed[] = ['table' => $item->table_name, 'id' => $item->row_id];
                }
            }
            if ($changed !== []) {
                throw ValidationException::withMessages(['rollback' => ['Baris berubah setelah batch: '.json_encode($changed)]]);
            }
            foreach ($locked->items as $item) {
                DB::table($item->table_name)->where('id', $item->row_id)->update($item->old_values);
            }
            $locked->update(['status' => 'rolled_back', 'rolled_back_at' => now()]);
            ActivityLog::query()->create(['causer_id' => $actor->id, 'subject_type' => ProjectTransferBatch::class, 'subject_id' => $locked->id, 'event' => 'project_transfer_rolled_back', 'description' => 'Batch pemindahan proyek dibatalkan.', 'properties' => ['batch_id' => $locked->id]]);
        }, 3);
    }

    private function validateBranches(Branch $source, Branch $target): void
    {
        if (! $source->is_active || ! $target->is_active || blank($target->sheet_id) || $source->id === $target->id || $source->sheet_id === $target->sheet_id) {
            throw ValidationException::withMessages(['branch_id' => 'Cabang asal dan tujuan harus aktif, memiliki Sheet berbeda, dan bukan cabang yang sama.']);
        }
    }

    private function projects(Branch $source, array $ids): Collection
    {
        return LeadMaster::query()->where('branch_id', $source->id)->whereIn('id', $ids)->get();
    }

    private function moveProjectRows(ProjectTransferBatch $batch, string $table, string $column, array $projectIds, int $targetBranchId): void
    {
        $rows = DB::table($table)->whereIn($column, $projectIds)->get();
        foreach ($rows as $row) {
            $values = (array) $row;
            $this->snapshot($batch, $table, $row->id, (array) $row);
            if (array_key_exists('branch_id', $values)) {
                DB::table($table)->where('id', $row->id)->update(['branch_id' => $targetBranchId]);
            }
        }
    }

    private function moveLeadChildren(ProjectTransferBatch $batch, array $projectIds, int $targetBranchId): void
    {
        $leadIds = DB::table('sales_leads')->whereIn('project_id', $projectIds)->pluck('id');
        foreach (['sales_lead_status_histories', 'sales_lead_stage_histories', 'sales_lead_site_visits', 'sales_lead_slik_attempts', 'sales_lead_consumer_links', 'sales_lead_freelance_links', 'sales_lead_akad_links'] as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->whereIn('sales_lead_id', $leadIds)->get() as $row) {
                $values = (array) $row;
                $this->snapshot($batch, $table, $row->id, $values);
                if (array_key_exists('branch_id', $values)) {
                    DB::table($table)->where('id', $row->id)->update(['branch_id' => $targetBranchId]);
                }
            }
        }
    }

    private function moveUsers(ProjectTransferBatch $batch, int $sourceId, int $targetId, array $projectIds, array $mapping): void
    {
        $userIds = DB::table('project_user')->whereIn('project_id', $projectIds)->pluck('user_id')->unique();
        foreach ($userIds as $userId) {
            $decision = $mapping[(string) $userId] ?? $mapping[$userId] ?? 'tetap';
            if (! in_array($decision, ['pindah', 'tetap', 'keduanya'], true)) {
                throw ValidationException::withMessages(['user_mapping' => 'Mapping user tidak valid.']);
            }
            if ($decision === 'tetap') {
                foreach (DB::table('project_user')->whereIn('project_id', $projectIds)->where('user_id', $userId)->get() as $assignment) {
                    $this->snapshot($batch, 'project_user', $assignment->id, (array) $assignment);
                }
                DB::table('project_user')->whereIn('project_id', $projectIds)->where('user_id', $userId)->update(['is_active' => false, 'updated_at' => now()]);
            }
            if ($decision !== 'tetap') {
                $user = DB::table('users')->where('id', $userId)->first();
                $this->snapshot($batch, 'users', $userId, (array) $user);
                if ($decision === 'pindah') {
                    DB::table('users')->where('id', $userId)->update(['branch_id' => $targetId]);
                }
                DB::table('branch_user')->updateOrInsert(['user_id' => $userId, 'branch_id' => $targetId], ['is_current' => true, 'updated_at' => now(), 'created_at' => now()]);
            }
        }
    }

    private function copyMigrationState(ProjectTransferBatch $batch, int $sourceId, int $targetId): void
    {
        $state = DB::table('branch_migration_states')->where('branch_id', $sourceId)->first();
        if ($state === null) {
            return;
        }
        $target = DB::table('branch_migration_states')->where('branch_id', $targetId)->first();
        if ($target !== null) {
            $this->snapshot($batch, 'branch_migration_states', $target->id, (array) $target);
        }
        DB::table('branch_migration_states')->updateOrInsert(['branch_id' => $targetId], ['state' => $state->state, 'changed_by' => $state->changed_by, 'changed_at' => $state->changed_at, 'notes' => $state->notes, 'updated_at' => now(), 'created_at' => $target?->created_at ?? now()]);
    }

    private function snapshotUpdate(ProjectTransferBatch $batch, string $table, int $id, array $new): void
    {
        $row = DB::table($table)->where('id', $id)->first();
        $this->snapshot($batch, $table, $id, (array) $row);
        DB::table($table)->where('id', $id)->update($new);
    }

    private function snapshot(ProjectTransferBatch $batch, string $table, int $id, array $old): void
    {
        ProjectTransferItem::query()->create(['batch_id' => $batch->id, 'table_name' => $table, 'row_id' => $id, 'old_values' => $old, 'old_updated_at' => $old['updated_at'] ?? null]);
    }

    private function hasSentLead(array $projectIds): bool
    {
        return DB::table('sales_leads')->whereIn('project_id', $projectIds)->whereNotNull('last_synced_at')->exists();
    }

    private function hasAttemptedDelivery(array $projectIds): bool
    {
        return DB::table('sales_leads')->whereIn('project_id', $projectIds)->whereNotNull('delivery_attempted_at')->whereNull('last_synced_at')->exists();
    }

    private function hasConflictingDana(array $projectIds): bool
    {
        return DB::table('dana_talangans')->whereIn('project_id', $projectIds)->where('sync_status', 'conflict')->exists();
    }

    /** @return list<string> */
    private function warnings(Branch $source, Branch $target, array $mapping): array
    {
        $warnings = ['Data branch-only tetap di cabang asal.'];
        if (! DB::table('users')->where('branch_id', $target->id)->whereIn('role_id', DB::table('roles')->whereIn('slug', ['supervisor', 'sales_coordinator'])->pluck('id'))->exists()) {
            $warnings[] = 'Cabang tujuan belum memiliki supervisor atau sales_coordinator.';
        }

        return $warnings;
    }
}
