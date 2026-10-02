<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerStageEvent;
use App\Models\Customer;
use App\Models\LeadMaster;
use App\Models\User;
use App\Support\ConsumerIdentity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class ConsumerDatabaseWorkspaceService
{
    public function __construct(
        private readonly OrganizationScopeService $organizationScope,
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly KonsumenPipelineService $pipeline,
    ) {}

    /** @return array<string, mixed> */
    public function index(User $user, Request $request): array
    {
        $branchIds = $this->organizationScope->branchIds($user, 'consumer_progress');
        $projectIds = $this->organizationScope->projectIds($user, 'consumer_progress');
        $branches = $this->workspaceAccess->accessibleBranches($user)
            ->whereIn('id', $branchIds)
            ->values();
        $selectedBranch = $this->selectedBranch($user, $request, $branchIds);
        $selectedProject = $this->selectedProject($request, $projectIds, $selectedBranch);
        $projects = $this->workspaceAccess->accessibleProjects($user)
            ->whereIn('id', $projectIds)
            ->when($selectedBranch, fn (Collection $items): Collection => $items->where('branch_id', $selectedBranch->id))
            ->values();

        $applications = $this->query($user, $branchIds, $projectIds, $selectedBranch, $selectedProject, $request)
            ->paginate(25)
            ->withQueryString();
        $salesOptions = $this->salesOptions($user, $branchIds, $projectIds, $selectedBranch, $selectedProject);
        $bankOptions = $this->bankOptions($branchIds, $projectIds, $selectedBranch, $selectedProject, $user);
        $selectedSales = $request->integer('sales_id') ?: null;
        $selectedBank = trim($request->string('bank')->toString());

        if ($selectedSales !== null && ! $salesOptions->contains('id', $selectedSales)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        if ($selectedBank !== '' && ! $bankOptions->contains($selectedBank)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return [
            'branches' => $branches,
            'showBranchFilter' => $branches->count() > 1,
            'projects' => $projects,
            'salesOptions' => $salesOptions,
            'bankOptions' => $bankOptions,
            'selectedBranch' => $selectedBranch,
            'selectedProject' => $selectedProject,
            'selectedSales' => $selectedSales,
            'selectedBank' => $selectedBank,
            'applications' => $applications,
            'search' => trim($request->string('search')->toString()),
            'selectedStatus' => $request->string('status')->toString(),
            'selectedStage' => $request->string('stage')->toString(),
            'selectedSort' => $this->sortValue($request),
            'selectedPaymentMethod' => $request->string('payment_method')->toString(),
            'statusOptions' => [
                'active' => 'Aktif',
                'draft' => 'Draft',
                'Lanjut' => 'Lanjut',
                'Mundur' => 'Mundur',
                'Pindah Kavling' => 'Pindah Kavling',
                'REPLACED' => 'Diganti',
            ],
            'paymentOptions' => ['kpr' => 'KPR', 'cash' => 'Cash', 'cash_bertahap' => 'Cash Bertahap', 'other' => 'Lainnya'],
            'stageOptions' => [
                'data_konsumen' => 'Data Konsumen', 'psjb' => 'PSJB', 'slik' => 'SLIK',
                'pemberkasan' => 'Pemberkasan', 'proses_bank' => 'Proses Bank', 'sp3k' => 'SP3K',
                'ppjb' => 'PPJB', 'akad' => 'Akad', 'bast' => 'BAST', 'garansi' => 'Form Garansi',
                'selesai' => 'Selesai', 'ready_100' => 'Rumah Siap 100%',
            ] + $this->pipeline->stages(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(User $user, ConsumerApplication $application): array
    {
        abort_unless($this->canViewApplication($user, $application), Response::HTTP_FORBIDDEN);

        $application->load([
            'branch:id,name,code',
            'project:id,branch_id,project_name',
            'customer:id,name,phone',
            'sales:id,name',
            'kavling:id,project_id,name,kavling_code',
            'stageEvents:id,consumer_application_id,stage,event_date,status,decision,occurred_at,completed_at,notes,reason',
            'bankProcesses:id,consumer_application_id,attempt_no,bank_name,status,response_type,approved_plafond,approved_tenor,sp3k_at,rejected_at,rejection_reason,submitted_at,verified_at',
            'psjbs:id,consumer_application_id,consumer_stage_event_id,id_psjb,tanggal_psjb,status',
            'ppjbDevelopers:id,consumer_application_id,consumer_stage_event_id,tanggal_sp3k,tanggal_ttd_ppjb,status',
            'akadRecords:id,consumer_application_id,consumer_stage_event_id,tanggal_akad,status_konsumen',
            'bastRecords:id,consumer_application_id,consumer_stage_event_id,tanggal_bast,no_bast,status,notes',
            'warranties:id,consumer_application_id,consumer_bast_record_id,status_komplain,tanggal_selesai,status_garansi,detail_garansi',
            'issues:id,consumer_application_id,process_key,category,description,status,opened_at,resolution,resolved_at,pic_user_id',
            'processApplicabilities:id,consumer_application_id,process_key,applicability,reason',
        ]);

        $stageLabels = [
            'data_konsumen' => 'Data Konsumen', 'psjb' => 'PSJB', 'slik' => 'SLIK', 'pemberkasan' => 'Pemberkasan',
            'proses_bank' => 'Proses Bank', 'sp3k' => 'SP3K', 'ppjb' => 'PPJB', 'akad' => 'Akad', 'bast' => 'BAST',
            'garansi' => 'Form Garansi', 'selesai' => 'Selesai', 'ready_100' => 'Rumah Siap 100%',
        ] + $this->pipeline->stages();
        $latestBank = $application->bankProcesses->sortByDesc('attempt_no')->first();
        $psjbByEvent = $application->psjbs->keyBy('consumer_stage_event_id');
        $ppjbByEvent = $application->ppjbDevelopers->keyBy('consumer_stage_event_id');
        $akadByEvent = $application->akadRecords->keyBy('consumer_stage_event_id');
        $bastByEvent = $application->bastRecords->keyBy('consumer_stage_event_id');
        $activities = ActivityLog::query()
            ->with('causer:id,name')
            ->where('subject_type', ConsumerApplication::class)
            ->where('subject_id', $application->id)
            ->latest('created_at')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (ActivityLog $activity): array => [
                'description' => $this->activityDescription($activity),
                'actor' => $activity->causer?->name,
                'source' => $this->sourceLabel($activity->properties['source'] ?? null),
                'created_at' => $activity->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'overview' => [
                'id' => $application->id,
                'id_transaksi' => $application->id_transaksi,
                'customer_name' => $application->customer?->name,
                'phone' => $application->customer?->phone,
                'branch' => $application->branch?->name,
                'project' => $application->project?->project_name,
                'sales' => $application->sales?->name,
                'bank_current' => $latestBank?->bank_name,
                'bank_attempts' => $application->bankProcesses->count(),
                'kavling' => $application->kavling?->kavling_code ?: $application->kavling?->name ?: $application->id_kavling,
                'application_status' => $this->valueLabel($application->application_status),
                'consumer_status' => $this->valueLabel($application->consumer_status),
                'transaction_status' => $this->valueLabel($application->transaction_status ?: $application->consumer_status),
                'payment_method' => $this->paymentLabel($application->payment_method),
                'current_stage' => $stageLabels[$application->current_process ?: $application->current_stage] ?? $stageLabels[$application->current_stage] ?? $application->current_process ?: $application->current_stage,
                'booking_date' => $application->booking_date?->toDateString(),
                'akad_date' => $application->akad_date?->toDateString(),
                'updated_at' => $application->updated_at?->toIso8601String(),
            ],
            'process' => $application->stageEvents->sortByDesc('occurred_at')->map(function (ConsumerStageEvent $event) use ($stageLabels, $psjbByEvent, $ppjbByEvent, $akadByEvent, $bastByEvent): array {
                $summary = match ($event->stage) {
                    'PSJB' => ($psjbByEvent->get($event->id)?->id_psjb ? 'ID PSJB: '.$psjbByEvent->get($event->id)->id_psjb : null),
                    'ppjb_dev' => $ppjbByEvent->get($event->id)?->tanggal_ttd_ppjb?->format('d M Y') ? 'PPJB ditandatangani '.$ppjbByEvent->get($event->id)->tanggal_ttd_ppjb->format('d M Y') : null,
                    'akad' => $akadByEvent->get($event->id)?->tanggal_akad?->format('d M Y') ? 'Akad '.$akadByEvent->get($event->id)->tanggal_akad->format('d M Y') : null,
                    'bast' => $bastByEvent->get($event->id)?->no_bast ? 'No. BAST: '.$bastByEvent->get($event->id)->no_bast : null,
                    'ready_100' => 'Rumah siap 100% tercatat',
                    default => null,
                };

                return [
                    'stage' => $stageLabels[$event->stage] ?? $event->stage,
                    'event_date' => $event->event_date?->toDateString(),
                    'status' => $this->valueLabel($event->status),
                    'decision' => $this->valueLabel($event->decision),
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                    'completed_at' => $event->completed_at?->toIso8601String(),
                    'summary' => $summary,
                    'notes' => $event->notes ?: $event->reason,
                ];
            })->values()->all(),
            'payment' => $application->bankProcesses->sortBy('attempt_no')->map(fn ($attempt): array => [
                'attempt_no' => $attempt->attempt_no,
                'bank_name' => $attempt->bank_name,
                'status' => $this->valueLabel($attempt->status),
                'response_type' => $this->valueLabel($attempt->response_type),
                'approved_plafond' => $attempt->approved_plafond,
                'approved_tenor' => $attempt->approved_tenor,
                'sp3k_at' => $attempt->sp3k_at?->toIso8601String(),
                'rejected_at' => $attempt->rejected_at?->toIso8601String(),
                'rejection_reason' => $attempt->rejection_reason,
                'submitted_at' => $attempt->submitted_at?->toIso8601String(),
                'verified_at' => $attempt->verified_at?->toIso8601String(),
            ])->values()->all(),
            'counts' => [
                'process' => $application->stageEvents->count(),
                'payment' => $application->bankProcesses->count(),
                'files' => $application->documents()->count(),
                'activity' => count($activities),
                'warranty' => $application->warranties->count(),
                'issues' => $application->issues->count(),
            ],
            'activity' => $activities,
            'warranty' => $application->warranties->map(fn ($warranty): array => ['status_komplain' => $warranty->status_komplain, 'status_garansi' => $warranty->status_garansi, 'tanggal_selesai' => $warranty->tanggal_selesai?->toDateString(), 'detail_garansi' => $warranty->detail_garansi])->values()->all(),
            'issues' => $application->issues->map(fn ($issue): array => ['process' => $issue->process_key, 'category' => $issue->category, 'description' => $issue->description, 'status' => $issue->status, 'opened_at' => $issue->opened_at?->toIso8601String(), 'resolution' => $issue->resolution])->values()->all(),
        ];
    }

    /** @return Builder<ConsumerApplication> */
    private function query(User $user, array $branchIds, array $projectIds, ?Branch $selectedBranch, ?LeadMaster $selectedProject, Request $request): Builder
    {
        $query = $this->scopedQuery($user, $branchIds, $projectIds, $selectedBranch, $selectedProject)
            ->with([
                'branch:id,name,code',
                'project:id,branch_id,project_name',
                'customer:id,name,phone',
                'sales:id,name',
                'kavling:id,project_id,name,kavling_code',
                'bankProcesses:id,consumer_application_id,attempt_no,bank_name,status,response_type',
            ])
            ->withCount(['stageEvents', 'bankProcesses', 'documents']);

        $search = trim($request->string('search')->toString());
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('id_transaksi', 'like', "%{$search}%")
                    ->orWhere('id_kavling', 'like', "%{$search}%")
                    ->orWhere('consumer_status', 'like', "%{$search}%")
                    ->orWhere('sales_lead_id', is_numeric($search) ? (int) $search : 0)
                    ->when(preg_match('/^\d{16}$/', $search) === 1, fn (Builder $searchQuery): Builder => $searchQuery
                        ->orWhere('nik_hash', ConsumerIdentity::nikHash($search))
                        ->orWhereHas('customer', fn (Builder $customer): Builder => $customer->where('nik_hash', ConsumerIdentity::nikHash($search))))
                    ->orWhereHas('customer', fn (Builder $customer): Builder => $customer
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"))
                    ->orWhereHas('project', fn (Builder $project): Builder => $project->where('project_name', 'like', "%{$search}%"))
                    ->orWhereHas('sales', fn (Builder $sales): Builder => $sales->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('sourceNup', fn (Builder $nup): Builder => $nup->where('nup_number', 'like', "%{$search}%"))
                    ->orWhereHas('kavling', fn (Builder $kavling): Builder => $kavling
                        ->where('kavling_code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%"));
            });
        }

        $status = $request->string('status')->toString();
        $stage = $request->string('stage')->toString();
        $salesId = $request->integer('sales_id');
        $bank = trim($request->string('bank')->toString());
        $paymentMethod = trim($request->string('payment_method')->toString());

        $sort = $this->sortValue($request);

        return $query
            ->when($status !== '', fn (Builder $builder): Builder => $builder->where(fn (Builder $statusQuery): Builder => $statusQuery
                ->where('consumer_status', $status)
                ->orWhere('application_status', $status)))
            ->when($stage !== '', fn (Builder $builder): Builder => $builder->where(fn (Builder $stageQuery): Builder => $stage === 'proses_bank'
                ? $stageQuery->whereIn('current_process', ['proses_bank', 'sp3k'])->orWhereIn('current_stage', ['proses_bank', 'sp3k'])
                : $stageQuery->where('current_process', $stage)->orWhere('current_stage', $stage)))
            ->when($salesId > 0, fn (Builder $builder): Builder => $builder->where('sales_user_id', $salesId))
            ->when($bank !== '', fn (Builder $builder): Builder => $builder->whereHas('bankProcesses', fn (Builder $bankQuery): Builder => $bankQuery->where('bank_name', $bank)))
            ->when($paymentMethod !== '', fn (Builder $builder): Builder => $builder->where('payment_method', $paymentMethod))
            ->when($sort === 'name', fn (Builder $builder): Builder => $builder->orderBy(Customer::select('name')->whereColumn('customers.id', 'consumer_applications.customer_id')))
            ->when($sort === 'process', fn (Builder $builder): Builder => $builder->orderBy('current_process')->orderBy('current_stage'))
            ->when($sort === 'sales', fn (Builder $builder): Builder => $builder->orderBy(User::select('name')->whereColumn('users.id', 'consumer_applications.sales_user_id')))
            ->when($sort === 'updated', fn (Builder $builder): Builder => $builder->orderByDesc('updated_at'))
            ->orderByDesc('id');
    }

    private function sortValue(Request $request): string
    {
        $sort = $request->string('sort')->toString();

        return in_array($sort, ['updated', 'name', 'process', 'sales'], true) ? $sort : 'updated';
    }

    /** @return Builder<ConsumerApplication> */
    private function scopedQuery(User $user, array $branchIds, array $projectIds, ?Branch $selectedBranch, ?LeadMaster $selectedProject): Builder
    {
        return ConsumerApplication::query()
            ->whereIn('branch_id', $branchIds)
            ->when($this->organizationScope->requiresProjectScope($user, 'consumer_progress'), fn (Builder $query): Builder => $query->whereIn('project_id', $projectIds))
            ->when(! $this->organizationScope->requiresProjectScope($user, 'consumer_progress'), fn (Builder $query): Builder => $query->where(fn (Builder $scope): Builder => $scope->whereNull('project_id')->orWhereIn('project_id', $projectIds)))
            ->when($selectedBranch, fn (Builder $query): Builder => $query->where('branch_id', $selectedBranch->id))
            ->when($selectedProject, fn (Builder $query): Builder => $query->where('project_id', $selectedProject->id));
    }

    /** @return Collection<int, User> */
    private function salesOptions(User $user, array $branchIds, array $projectIds, ?Branch $selectedBranch, ?LeadMaster $selectedProject): Collection
    {
        $salesIds = $this->scopedQuery($user, $branchIds, $projectIds, $selectedBranch, $selectedProject)
            ->whereNotNull('sales_user_id')
            ->distinct()
            ->pluck('sales_user_id');

        return $this->organizationScope->visibleUsersQuery($user, 'consumer_progress')
            ->whereIn('id', $salesIds)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return Collection<int, string> */
    private function bankOptions(array $branchIds, array $projectIds, ?Branch $selectedBranch, ?LeadMaster $selectedProject, User $user): Collection
    {
        $applicationIds = $this->scopedQuery($user, $branchIds, $projectIds, $selectedBranch, $selectedProject)->select('consumer_applications.id');

        return ConsumerBankProcess::query()
            ->whereIn('consumer_application_id', $applicationIds)
            ->whereNotNull('bank_name')
            ->where('bank_name', '<>', '')
            ->distinct()
            ->orderBy('bank_name')
            ->pluck('bank_name');
    }

    private function activityDescription(ActivityLog $activity): string
    {
        $labels = [
            'consumer_slik_recorded' => 'BI Checking dicatat',
            'consumer_psjb_recorded' => 'PSJB dicatat',
            'consumer_bank_stage_recorded' => 'Tahap bank diperbarui',
            'consumer_bank_attempt_created' => 'Percobaan bank baru dibuat',
            'consumer_bank_attempt_updated' => 'Percobaan bank diperbarui',
            'consumer_ppjb_recorded' => 'PPJB Developer dicatat',
            'consumer_akad_recorded' => 'Akad dicatat',
            'consumer_ready_100_recorded' => 'Rumah Siap 100% dicatat',
            'consumer_bast_recorded' => 'BAST dicatat',
            'consumer_ganti_konsumen' => 'Konsumen diganti',
            'consumer_kavling_moved' => 'Kavling dipindahkan',
            'consumer_mundur' => 'Konsumen ditandai mundur',
        ];
        $description = $labels[$activity->event] ?? $activity->description;

        if (Str::contains($description, 'consumer_')) {
            $description = 'Aktivitas data konsumen dicatat.';
        }

        return $activity->causer?->name ? $activity->causer->name.' - '.$description : $description;
    }

    private function sourceLabel(?string $source): ?string
    {
        return match ($source) {
            'manual' => 'Manual',
            'sheet_sync' => 'Sinkronisasi Sheet',
            'marison_v2' => 'Migrasi Marison',
            null, '' => null,
            default => 'Sumber terintegrasi',
        };
    }

    private function valueLabel(?string $value): ?string
    {
        return match (Str::lower(trim((string) $value))) {
            '' => null,
            'active' => 'Aktif',
            'draft' => 'Draft',
            'current' => 'Berjalan',
            'complete', 'completed' => 'Selesai',
            'approved' => 'Disetujui',
            'rejected', 'reject', 'ditolak' => 'Ditolak',
            'submitted' => 'Diajukan',
            'pending' => 'Menunggu',
            'confirmed' => 'Dikonfirmasi',
            'lanjut' => 'Lanjut',
            'mundur' => 'Mundur',
            'pindah kavling' => 'Pindah Kavling',
            'replaced' => 'Diganti',
            default => Str::headline((string) $value),
        };
    }

    private function paymentLabel(?string $value): ?string
    {
        return match ($value) {
            'cash' => 'Cash',
            'cash_bertahap' => 'Cash Bertahap',
            'kpr' => 'KPR',
            'other' => 'Lainnya',
            default => $value ? Str::headline($value) : null,
        };
    }

    private function selectedBranch(User $user, Request $request, array $branchIds): ?Branch
    {
        $selectedBranch = $this->workspaceAccess->resolveRequestedBranch($user, $request->input('branch_id'));
        if ($request->filled('branch_id') && (! $selectedBranch || ! in_array((int) $selectedBranch->id, $branchIds, true))) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $selectedBranch;
    }

    private function selectedProject(Request $request, array $projectIds, ?Branch $selectedBranch): ?LeadMaster
    {
        if (! $request->filled('project_id')) {
            return null;
        }

        $project = LeadMaster::query()->whereKey($request->integer('project_id'))->where('is_active', true)->first();
        if (! $project || ! in_array((int) $project->id, $projectIds, true) || ($selectedBranch && (int) $project->branch_id !== (int) $selectedBranch->id)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $project;
    }

    private function canViewApplication(User $user, ConsumerApplication $application): bool
    {
        return $this->organizationScope->allowsProjectRecord(
            $user,
            'consumer_progress',
            'view',
            (int) $application->branch_id,
            $application->project_id,
        );
    }
}
