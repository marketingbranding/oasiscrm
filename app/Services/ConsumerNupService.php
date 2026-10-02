<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ConsumerApplication;
use App\Models\ConsumerNup;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ConsumerNupService
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly ConsumerEntryService $entry,
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
                'kavling_id' => $data['kavling_id'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'entry_mode' => 'new',
                'acquisition_source' => 'nup',
                'source_nup_id' => $locked->id,
                'notes' => $data['notes'] ?? $locked->notes,
            ], $actor);
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
