<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ConsumerApplication;
use App\Models\ConsumerIssue;
use App\Models\ConsumerNup;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\User;
use App\Support\FeatureFlag;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ConsumerNupService
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly ConsumerEntryService $entry,
        private readonly ConsumerKavlingLifecycleService $kavlingLifecycle,
    ) {}

    public function visibleQuery(User $user): Builder
    {
        $branchIds = $this->scope->branchIds($user, 'consumer_progress');
        $projectIds = $this->scope->projectIds($user, 'consumer_progress');

        return ConsumerNup::query()
            ->with(['customer:id,name,phone', 'branch:id,name', 'project:id,project_name'])
            ->whereIn('branch_id', $branchIds)
            ->when($this->scope->requiresProjectScope($user, 'consumer_progress'), fn (Builder $query): Builder => $query->whereIn('project_id', $projectIds))
            ->when(! $this->scope->requiresProjectScope($user, 'consumer_progress'), fn (Builder $query): Builder => $query->where(fn (Builder $nested): Builder => $nested->whereNull('project_id')->orWhereIn('project_id', $projectIds)));
    }

    public function create(array $data, User $actor): ConsumerNup
    {
        $this->assertScope($actor, (int) $data['branch_id'], isset($data['project_id']) ? (int) $data['project_id'] : null);

        return DB::transaction(function () use ($data, $actor): ConsumerNup {
            $customer = $this->entry->resolveOrCreateCustomer($data);
            $nup = ConsumerNup::create([
                'customer_id' => $customer->id,
                'branch_id' => $data['branch_id'],
                'project_id' => $data['project_id'] ?? null,
                'nup_number' => $data['nup_number'],
                'registered_at' => $data['registered_at'],
                'status' => 'waiting',
                'entry_type' => $data['entry_type'] ?? 'legacy',
                'source' => $data['source'] ?? 'manual',
                'notes' => $data['notes'] ?? null,
            ]);
            ActivityLog::create([
                'causer_id' => $actor->id,
                'subject_type' => ConsumerNup::class,
                'subject_id' => $nup->id,
                'event' => 'consumer_nup_created',
                'description' => 'NUP baru dicatat.',
                'properties' => ['action' => 'nup_created', 'source' => 'manual'],
            ]);

            return $nup->fresh(['customer', 'branch', 'project']);
        });
    }

    public function createFromLead(SalesLead $lead, array $data, User $actor): ConsumerNup
    {
        if ($lead->resolveStageV3() !== 'utj') {
            throw new DomainException('Lead harus berada pada tahap UTJ sebelum dibuat menjadi NUP.');
        }

        $this->assertScope($actor, (int) $lead->branch_id, (int) $lead->project_id);

        return DB::transaction(function () use ($lead, $data, $actor): ConsumerNup {
            $existing = ConsumerNup::query()
                ->where('source', 'lead')
                ->where('source_id', (string) $lead->id)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $customer = $this->entry->resolveOrCreateCustomer([
                'name' => $lead->customer_name,
                'phone' => $lead->phone,
                'nik' => $data['nik'] ?? null,
            ]);
            $nup = ConsumerNup::create([
                'customer_id' => $customer->id,
                'branch_id' => $lead->branch_id,
                'project_id' => $lead->project_id,
                'nup_number' => $data['nup_number'],
                'registered_at' => $data['registered_at'] ?? today()->toDateString(),
                'status' => 'waiting',
                'entry_type' => 'from_lead',
                'source' => 'lead',
                'source_id' => (string) $lead->id,
                'notes' => $data['notes'] ?? $lead->notes,
            ]);
            ActivityLog::create([
                'causer_id' => $actor->id,
                'subject_type' => ConsumerNup::class,
                'subject_id' => $nup->id,
                'event' => 'consumer_nup_created_from_lead',
                'description' => 'NUP dibuat dari lead UTJ.',
                'properties' => ['action' => 'nup_created_from_lead', 'lead_id' => $lead->id],
            ]);

            return $nup->fresh(['customer', 'branch', 'project']);
        });
    }

    public function returnFromTransaction(ConsumerApplication $application, ConsumerIssue $issue, array $data, User $actor): ConsumerNup
    {
        $this->assertScope($actor, (int) $application->branch_id, $application->project_id === null ? null : (int) $application->project_id);
        if ((int) $issue->consumer_application_id !== (int) $application->id) {
            throw new DomainException('Kendala harus berasal dari transaksi yang dikembalikan ke NUP.');
        }
        if (blank($data['reason'] ?? null) && blank($issue->description)) {
            throw new DomainException('Alasan Kembali ke NUP wajib dicatat.');
        }

        return DB::transaction(function () use ($application, $issue, $data, $actor): ConsumerNup {
            $locked = ConsumerApplication::query()->lockForUpdate()->findOrFail($application->id);
            $existing = ConsumerNup::query()->where('source_application_id', $locked->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }
            $statusDetails = $locked->canonicalTransactionStatusDetails();
            if ($statusDetails['status'] !== 'LANJUT' || $statusDetails['needs_review']) {
                throw new DomainException('Transaksi harus berstatus LANJUT untuk dikembalikan ke NUP.');
            }
            $before = $this->snapshot($locked);
            $this->kavlingLifecycle->releaseForNup($locked);
            $nup = ConsumerNup::create([
                'customer_id' => $locked->customer_id,
                'branch_id' => $locked->branch_id,
                'project_id' => $locked->project_id,
                'nup_number' => $data['nup_number'],
                'registered_at' => $data['registered_at'] ?? today()->toDateString(),
                'status' => 'waiting',
                'entry_type' => 'returned',
                'source' => 'transaction',
                'source_id' => $locked->id_transaksi,
                'source_application_id' => $locked->id,
                'issue_id' => $issue->id,
                'notes' => $data['reason'] ?? $issue->description,
            ]);
            $locked->update(['transaction_status' => 'DITUTUP', 'closed_by_event' => 'KEMBALI_KE_NUP']);
            if (FeatureFlag::enabledForBranch('transaction_events_v3', $locked->branch_id)) {
                app(ConsumerOperationalService::class)->appendTransactionEvent($locked, 'KEMBALI_KE_NUP', $actor, 'manual', $nup->notes, ['nup_id' => $nup->id, 'issue_id' => $issue->id]);
            }
            $this->audit($actor, $locked, $before, $this->snapshot($locked->fresh()), $nup);

            return $nup->fresh(['customer', 'sourceApplication', 'issue']);
        });
    }

    public function convert(ConsumerNup $nup, array $data, User $actor): ConsumerApplication
    {
        $this->assertScope($actor, (int) $nup->branch_id, $nup->project_id === null ? null : (int) $nup->project_id);

        return DB::transaction(function () use ($nup, $data, $actor): ConsumerApplication {
            $locked = ConsumerNup::query()->with('customer')->lockForUpdate()->findOrFail($nup->id);
            if ($locked->converted_application_id !== null) {
                return ConsumerApplication::query()->findOrFail($locked->converted_application_id);
            }
            if ($locked->status !== 'waiting') {
                throw new DomainException('NUP ini tidak lagi berstatus menunggu.');
            }
            $projectId = $data['project_id'] ?? $locked->project_id;
            if ($projectId === null) {
                throw new DomainException('Proyek wajib dipilih saat NUP dilanjutkan menjadi konsumen.');
            }
            $this->assertScope($actor, (int) $locked->branch_id, (int) $projectId);
            if (empty($data['kavling_id'])) {
                throw new DomainException('Kavling wajib dipilih saat NUP dilanjutkan menjadi konsumen.');
            }
            $project = LeadMaster::query()->whereKey($projectId)->where('branch_id', $locked->branch_id)->where('is_active', true)->first();
            if ($project === null) {
                throw new DomainException('Proyek NUP tidak valid untuk cabang NUP.');
            }
            $kavling = Kavling::query()->whereKey($data['kavling_id'])->firstOrFail();
            if ((int) $kavling->project_id !== (int) $projectId) {
                throw new DomainException('Kavling harus berada pada proyek NUP.');
            }

            $application = $this->entry->create([
                'name' => $locked->customer->name,
                'phone' => $locked->customer->phone,
                'email' => $locked->customer->email,
                'nik' => $locked->customer->nik_encrypted,
                'date_of_birth' => $locked->customer->date_of_birth,
                'occupation' => $locked->customer->occupation,
                'occupation_detail' => $locked->customer->occupation_detail,
                'address' => $locked->customer->address,
                'kelurahan' => $locked->customer->kelurahan,
                'kecamatan' => $locked->customer->kecamatan,
                'kabupaten_kota' => $locked->customer->kabupaten_kota,
                'emergency_contact_name' => $locked->customer->emergency_contact_name,
                'emergency_contact_phone' => $locked->customer->emergency_contact_phone,
                'branch_id' => $locked->branch_id,
                'project_id' => $projectId,
                'sales_user_id' => $data['sales_user_id'] ?? null,
                'kavling_id' => null,
                'payment_method' => $data['payment_method'] ?? null,
                'entry_mode' => 'new',
                'acquisition_source' => 'nup',
                'source_nup_id' => $locked->id,
                'notes' => $data['notes'] ?? $locked->notes,
            ], $actor);
            $this->kavlingLifecycle->assign($application, $kavling);
            $locked->update(['status' => 'converted', 'converted_application_id' => $application->id]);
            ActivityLog::create([
                'causer_id' => $actor->id,
                'subject_type' => ConsumerNup::class,
                'subject_id' => $locked->id,
                'event' => 'consumer_nup_converted',
                'description' => 'NUP dilanjutkan menjadi konsumen.',
                'properties' => ['action' => 'nup_converted', 'source' => 'manual', 'after' => ['consumer_application_id' => $application->id]],
            ]);

            return $application;
        });
    }

    private function snapshot(ConsumerApplication $application): array
    {
        return collect($application->getAttributes())->all();
    }

    private function audit(User $actor, ConsumerApplication $application, array $before, array $after, ConsumerNup $nup): void
    {
        ActivityLog::create([
            'causer_id' => $actor->id,
            'subject_type' => ConsumerApplication::class,
            'subject_id' => $application->id,
            'event' => 'consumer_returned_to_nup',
            'description' => 'Transaksi dikembalikan ke NUP.',
            'properties' => ['action' => 'kembali_ke_nup', 'before' => $before, 'after' => $after, 'nup_id' => $nup->id],
        ]);
    }

    private function assertScope(User $actor, int $branchId, ?int $projectId): void
    {
        if (! $actor->hasPermission('consumer_progress.manage') && ! $actor->hasScopedPermission('consumer_progress', 'manage')) {
            throw new AuthorizationException('Anda tidak berwenang mengelola NUP.');
        }
        if ($projectId !== null && ! $this->scope->allowsProjectRecord($actor, 'consumer_progress', 'manage', $branchId, $projectId)) {
            throw new AuthorizationException('NUP berada di luar lingkup akses Anda.');
        }
        if ($projectId === null && ! in_array($branchId, $this->scope->branchIds($actor, 'consumer_progress'), true)) {
            throw new AuthorizationException('NUP berada di luar lingkup akses Anda.');
        }
    }
}
