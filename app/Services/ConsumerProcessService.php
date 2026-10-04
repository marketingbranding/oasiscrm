<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ConsumerApplication;
use App\Models\ConsumerIssue;
use App\Models\ConsumerWarranty;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsumerProcessService
{
    public const WARRANTY_COMPLAINT_STATUSES = ['Belum Dipilih', 'Ada Komplain', 'Tidak Ada Komplain'];

    public const WARRANTY_STATUSES = ['Belum Dipilih', 'Proses', 'Tidak Ada Komplain', 'Selesai'];

    public const ISSUE_PROCESSES = ['data_konsumen', 'psjb', 'slik', 'pemberkasan', 'proses_bank', 'sp3k', 'ppjb', 'akad', 'bast', 'garansi', 'other'];

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly ConsumerOperationalService $operational,
    ) {}

    public function recordWarranty(ConsumerApplication $application, array $data, User $actor): ConsumerWarranty
    {
        $this->authorize($actor, $application);
        if (! $application->bastRecords()->exists()) {
            throw new DomainException('Form Garansi hanya dapat dicatat setelah BAST.');
        }

        return DB::transaction(function () use ($application, $data, $actor): ConsumerWarranty {
            $application = ConsumerApplication::query()->lockForUpdate()->findOrFail($application->id);
            $data['consumer_bast_record_id'] ??= $application->bastRecords()->latest('tanggal_bast')->latest('id')->value('id');
            if (! $application->bastRecords()->whereKey($data['consumer_bast_record_id'])->exists()) {
                throw ValidationException::withMessages([
                    'consumer_bast_record_id' => 'BAST harus berasal dari aplikasi konsumen yang sama.',
                ]);
            }
            $warranty = $application->warranties()->create([
                ...array_intersect_key($data, array_flip([
                    'consumer_bast_record_id', 'status_komplain', 'tgl_sales_ke_sam', 'tgl_sam_ke_sat',
                    'tgl_sat_ke_sam', 'tgl_sam_ke_sales', 'tgl_sales_ke_kons', 'detail_garansi',
                    'tanggal_selesai', 'status_garansi',
                ])),
                'source' => $data['source'] ?? 'manual',
                'metadata' => $data['metadata'] ?? null,
            ]);
            $application->stageEvents()->create([
                'stage' => 'garansi',
                'source' => 'manual',
                'event_date' => $data['tanggal_selesai'] ?? now()->toDateString(),
                'occurred_at' => now(),
                'status' => $warranty->status_garansi,
                'notes' => $warranty->detail_garansi,
                'actor_id' => $actor->id,
                'metadata' => ['warranty_id' => $warranty->id],
            ]);
            $updates = ['current_process' => 'garansi', 'current_process_source' => 'operational'];
            if (in_array($warranty->status_garansi, ['Selesai', 'Tidak Ada Komplain'], true)) {
                $updates['current_process'] = 'selesai';
                $updates['transaction_status'] = 'SELESAI';
                $updates['consumer_status'] = 'Selesai';
            }
            $application->update($updates);
            $this->audit($actor, $application, 'consumer_warranty_recorded', ['warranty_id' => $warranty->id], ['status_garansi' => $warranty->status_garansi], 'manual');

            return $warranty->fresh();
        });
    }

    public function createIssue(ConsumerApplication $application, array $data, User $actor): ConsumerIssue
    {
        $this->authorize($actor, $application);
        $this->assertPicScope($application, $data, $actor);

        return DB::transaction(function () use ($application, $data, $actor): ConsumerIssue {
            $issue = $application->issues()->create([
                ...array_intersect_key($data, array_flip(['process_key', 'category', 'description', 'opened_at', 'pic_user_id', 'status', 'source', 'source_id', 'metadata'])),
                'opened_at' => $data['opened_at'] ?? now(),
                'status' => $data['status'] ?? 'open',
            ]);
            $this->audit($actor, $application, 'consumer_issue_created', null, ['issue_id' => $issue->id, 'process_key' => $issue->process_key], 'manual');

            return $issue->fresh(['pic']);
        });
    }

    public function resolveIssue(ConsumerIssue $issue, array $data, User $actor): ConsumerIssue
    {
        $issue->loadMissing('application');
        $this->authorize($actor, $issue->application);
        $issue->update(['status' => 'resolved', 'resolution' => $data['resolution'], 'resolved_at' => now(), 'resolved_by' => $actor->id]);
        $this->audit($actor, $issue->application, 'consumer_issue_resolved', ['issue_id' => $issue->id], ['resolution' => $issue->resolution], 'manual');

        return $issue->fresh(['pic', 'resolvedBy']);
    }

    public function completedQuery(User $user): Builder
    {
        $branchIds = $this->scope->branchIds($user, 'consumer_progress');
        $projectIds = $this->scope->projectIds($user, 'consumer_progress');

        return ConsumerApplication::query()
            ->with(['customer:id,name,phone', 'branch:id,name', 'project:id,project_name', 'kavling:id,kavling_code,name', 'akadRecords', 'bastRecords', 'warranties'])
            ->whereIn('branch_id', $branchIds)
            ->when($this->scope->requiresProjectScope($user, 'consumer_progress'), fn (Builder $query): Builder => $query->whereIn('project_id', $projectIds))
            ->when(! $this->scope->requiresProjectScope($user, 'consumer_progress'), fn (Builder $query): Builder => $query->where(fn (Builder $nested): Builder => $nested->whereNull('project_id')->orWhereIn('project_id', $projectIds)))
            ->where(function (Builder $query): void {
                $query->where('transaction_status', 'SELESAI')
                    ->orWhere(function (Builder $nested): void {
                        $nested->whereHas('bastRecords')->whereHas('warranties', fn (Builder $warranty): Builder => $warranty->whereIn('status_garansi', ['Selesai', 'Tidak Ada Komplain']));
                    });
            });
    }

    private function authorize(User $actor, ConsumerApplication $application): void
    {
        $hasManage = $actor->hasPermission('consumer_progress.manage') || $actor->hasScopedPermission('consumer_progress', 'manage');
        if (! $hasManage || ! $this->scope->allowsProjectRecord($actor, 'consumer_progress', 'manage', (int) $application->branch_id, $application->project_id)) {
            throw new AuthorizationException('Anda tidak berwenang melakukan tindakan pada transaksi ini.');
        }
    }

    private function assertPicScope(ConsumerApplication $application, array $data, User $actor): void
    {
        if (! filled($data['pic_user_id'] ?? null)) {
            return;
        }

        $picId = (int) $data['pic_user_id'];
        $visibleUserIds = $this->scope->visibleUserIds($actor, 'consumer_progress', 'manage');
        $isInWorkspace = User::query()
            ->whereKey($picId)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($application): void {
                $query->where('branch_id', $application->branch_id)
                    ->orWhereHas('branches', fn (Builder $branch): Builder => $branch
                        ->whereKey($application->branch_id)
                        ->where('branch_user.can_view', true))
                    ->orWhereHas('assignedProjects', function (Builder $project) use ($application): void {
                        $project->whereKey($application->project_id)
                            ->where('project_user.is_active', true)
                            ->where(fn (Builder $dates): Builder => $dates
                                ->whereNull('project_user.assignment_start_date')
                                ->orWhereDate('project_user.assignment_start_date', '<=', today()->toDateString()))
                            ->where(fn (Builder $dates): Builder => $dates
                                ->whereNull('project_user.assignment_end_date')
                                ->orWhereDate('project_user.assignment_end_date', '>=', today()->toDateString()));
                    });
            })
            ->exists();

        if (! $isInWorkspace || ! in_array($picId, $visibleUserIds, true)) {
            throw new AuthorizationException('PIC kendala harus berada dalam lingkup cabang atau proyek transaksi.');
        }
    }

    private function audit(User $actor, ConsumerApplication $application, string $event, ?array $before, ?array $after, string $source): void
    {
        ActivityLog::create([
            'causer_id' => $actor->id,
            'subject_type' => ConsumerApplication::class,
            'subject_id' => $application->id,
            'event' => $event,
            'description' => 'Perubahan proses konsumen dicatat.',
            'properties' => compact('before', 'after', 'source'),
        ]);
    }
}
