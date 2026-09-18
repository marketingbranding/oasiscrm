<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ConsumerApplication;
use App\Models\ConsumerMigrationReconciliation;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConsumerMigrationReconciliationService
{
    public const DECISIONS = ['LANJUT', 'MUNDUR', 'PINDAH_KAVLING', 'REJECT', 'SELESAI'];

    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly WorkspaceAccessService $workspace,
        private readonly ConsumerKavlingLifecycleService $lifecycle,
    ) {}

    public function visibleQuery(User $user)
    {
        return ConsumerMigrationReconciliation::query()
            ->with(['application', 'branch', 'project', 'targetKavling'])
            ->where('reconciliation_status', 'PENDING')
            ->whereIn('branch_id', $this->scope->branchIds($user, 'consumer_progress', 'manage'))
            ->whereIn('project_id', $this->scope->projectIds($user, 'consumer_progress', 'manage'));
    }

    public function assertAccess(User $user, ConsumerMigrationReconciliation $reconciliation): void
    {
        abort_unless($user->hasPermission('consumer_progress.manage'), 403);
        abort_unless($this->scope->allowsProjectRecord($user, 'consumer_progress', 'manage', $reconciliation->branch_id, $reconciliation->project_id), 403);
        abort_unless($this->workspace->canViewBranch($user, $reconciliation->branch_id), 403);
    }

    public function apply(ConsumerMigrationReconciliation $reconciliation, User $actor, array $data): ConsumerMigrationReconciliation
    {
        $this->assertAccess($actor, $reconciliation);

        return DB::transaction(function () use ($reconciliation, $actor, $data): ConsumerMigrationReconciliation {
            $item = ConsumerMigrationReconciliation::query()->lockForUpdate()->with('application')->findOrFail($reconciliation->id);
            if ($item->reconciliation_status !== 'PENDING') {
                throw ValidationException::withMessages(['reconciliation' => 'Rekonsiliasi sudah diterapkan.']);
            }
            $application = ConsumerApplication::query()->lockForUpdate()->findOrFail($item->consumer_application_id);
            if ((int) $application->branch_id !== (int) $item->branch_id || (int) $application->project_id !== (int) $item->project_id) {
                throw ValidationException::withMessages(['reconciliation' => 'Organisasi aplikasi tidak sesuai.']);
            }
            $decision = $data['decision'];
            $customer = $this->customer($data, $actor);
            $target = ! empty($data['target_kavling_id']) ? Kavling::query()->findOrFail($data['target_kavling_id']) : null;
            if ($target && (int) $target->project_id !== (int) $item->project_id) {
                throw ValidationException::withMessages(['target_kavling_id' => 'Kavling harus berada di proyek aplikasi.']);
            }
            if (in_array($decision, ['LANJUT', 'PINDAH_KAVLING', 'SELESAI'], true) && $target === null) {
                throw ValidationException::withMessages(['target_kavling_id' => 'Kavling wajib dipilih untuk keputusan ini.']);
            }
            if ($decision === 'MUNDUR') {
                $this->lifecycle->mundur($application);
                $application->update(['customer_id' => $customer->id, 'consumer_status' => 'Mundur', 'application_status' => 'active']);
            } elseif ($decision === 'PINDAH_KAVLING') {
                $this->lifecycle->pindahKavling($application, $target);
                $application->update(['customer_id' => $customer->id, 'application_status' => 'active']);
            } elseif ($decision === 'LANJUT') {
                $this->lifecycle->assign($application, $target);
                $application->update(['customer_id' => $customer->id, 'consumer_status' => 'Lanjut', 'application_status' => 'active']);
            } elseif ($decision === 'REJECT') {
                $application->update(['customer_id' => $customer->id, 'consumer_status' => 'Reject', 'application_status' => 'active']);
            } else {
                $this->lifecycle->assign($application, $target);
                $this->lifecycle->ensureSold($application);
                $application->update(['customer_id' => $customer->id, 'consumer_status' => 'Selesai', 'application_status' => 'active']);
            }
            $item->update(['decision' => $decision, 'customer_id' => $customer->id, 'target_kavling_id' => $target?->id, 'reconciliation_status' => 'RESOLVED', 'resolved_by' => $actor->id, 'resolved_at' => now()]);
            $item->load('application');
            $item->application->load('customer');
            ActivityLog::query()->create(['causer_id' => $actor->id, 'subject_type' => ConsumerMigrationReconciliation::class, 'subject_id' => $item->id, 'event' => 'marison_reconciliation_applied', 'description' => 'Rekonsiliasi Marison V2 diterapkan.', 'properties' => ['decision' => $decision, 'customer_id' => $customer->id, 'target_kavling_id' => $target?->id]]);

            return $item->fresh(['application', 'customer', 'targetKavling', 'resolver']);
        });
    }

    private function customer(array $data, User $actor): Customer
    {
        if (! empty($data['customer_id'])) {
            return Customer::query()->findOrFail($data['customer_id']);
        }
        $name = trim((string) ($data['new_customer_name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['new_customer_name' => 'Nama Customer wajib diisi.']);
        }

        return Customer::query()->create(['name' => $name]);
    }
}
