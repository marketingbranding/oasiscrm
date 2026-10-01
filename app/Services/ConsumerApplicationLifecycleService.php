<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ConsumerAkadRecord;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerBastRecord;
use App\Models\ConsumerPpjbDeveloper;
use App\Models\ConsumerPsjb;
use App\Models\ConsumerStageEvent;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ConsumerApplicationLifecycleService
{
    public function __construct(
        private ConsumerKavlingLifecycleService $kavlingLifecycle,
        private ConsumerOperationalService $operational,
        private OrganizationScopeService $scope,
    ) {}

    public function recordPemberkasan(ConsumerApplication $application, array $data, User $actor): ConsumerBankProcess
    {
        $this->authorize($actor, $application);

        return $this->operational->recordPemberkasan($application, $data, $actor);
    }

    public function recordBiChecking(ConsumerApplication $application, array $data, User $actor): ConsumerStageEvent
    {
        $this->authorize($actor, $application);

        return $this->operational->recordBiChecking($application, $data, $actor);
    }

    public function recordPsjb(ConsumerApplication $application, array $data, User $actor): ConsumerPsjb
    {
        $this->authorize($actor, $application);

        return $this->operational->recordPsjb($application, $data, $actor);
    }

    public function recordProsesBank(ConsumerApplication $application, array $data, User $actor): ConsumerBankProcess
    {
        $this->authorize($actor, $application);

        return $this->operational->recordProsesBank($application, $data, $actor);
    }

    public function gantiBank(ConsumerApplication $application, array $data, User $actor): ConsumerBankProcess
    {
        $this->authorize($actor, $application);

        return $this->operational->gantiBank($application, $data, $actor);
    }

    public function recordReady100(ConsumerApplication $application, array $data, User $actor): ConsumerStageEvent
    {
        $this->authorize($actor, $application);

        return $this->operational->recordReady100($application, $data, $actor);
    }

    public function recordAkad(ConsumerApplication $application, array $data, User $actor): ConsumerAkadRecord
    {
        $this->authorize($actor, $application);

        return $this->operational->recordAkad($application, $data, $actor, $this->kavlingLifecycle);
    }

    public function recordPpjb(ConsumerApplication $application, array $data, User $actor): ConsumerPpjbDeveloper
    {
        $this->authorize($actor, $application);

        return $this->operational->recordPpjb($application, $data, $actor);
    }

    public function recordBast(ConsumerApplication $application, array $data, User $actor): ConsumerBastRecord
    {
        $this->authorize($actor, $application);

        return $this->operational->recordBast($application, $data, $actor, $this->kavlingLifecycle);
    }

    public function gantiKonsumen(ConsumerApplication $application, Customer $replacementCustomer, User $actor, ?Kavling $targetKavling = null): ConsumerApplication
    {
        $this->authorize($actor, $application);

        return DB::transaction(function () use ($application, $replacementCustomer, $actor, $targetKavling): ConsumerApplication {
            $oldApplication = ConsumerApplication::query()->lockForUpdate()->findOrFail($application->id);
            if ($oldApplication->replacement_application_id !== null) {
                return $oldApplication->replacementApplication()->firstOrFail();
            }
            if (strtoupper((string) $oldApplication->application_status) === 'REPLACED') {
                throw new \DomainException('Aplikasi konsumen sudah berstatus REPLACED tanpa relasi pengganti.');
            }
            if ((int) $oldApplication->customer_id === (int) $replacementCustomer->id) {
                throw new \DomainException('Konsumen pengganti harus berbeda dari konsumen lama.');
            }

            $before = $this->snapshot($oldApplication);
            $currentKavling = $targetKavling ?? ($oldApplication->kavling_id === null ? null : Kavling::query()->findOrFail($oldApplication->kavling_id));
            if ($currentKavling !== null && (int) $currentKavling->project_id !== (int) $oldApplication->project_id) {
                throw new \DomainException('Kavling pengganti harus berada pada proyek aplikasi.');
            }

            if ($currentKavling !== null) {
                $this->kavlingLifecycle->releaseForReplacement($oldApplication);
            }

            $replacement = ConsumerApplication::create([
                'customer_id' => $replacementCustomer->id,
                'branch_id' => $oldApplication->branch_id,
                'project_id' => $oldApplication->project_id,
                'sales_user_id' => $oldApplication->sales_user_id,
                'promo_id' => $oldApplication->promo_id,
                'application_status' => 'active',
                'consumer_status' => 'Lanjut',
                'status_cash' => $oldApplication->status_cash,
                'booking_date' => $oldApplication->booking_date,
                'notes' => $oldApplication->notes,
            ]);
            if ($currentKavling !== null) {
                $this->kavlingLifecycle->assign($replacement, $currentKavling);
            }

            $oldApplication->update([
                'application_status' => 'REPLACED',
                'replacement_application_id' => $replacement->id,
                'kavling_id' => null,
            ]);

            $this->audit($actor, $oldApplication, $before, [
                'old_application' => $this->snapshot($oldApplication->fresh()),
                'replacement_application' => $this->snapshot($replacement->fresh()),
            ]);

            return $replacement->fresh(['customer', 'kavling', 'project', 'sales', 'promo']);
        });
    }

    private function authorize(User $actor, ConsumerApplication $application): void
    {
        $managePermission = $actor->hasPermission('consumer_progress.manage')
            || $actor->hasScopedPermission('consumer_progress', 'manage');
        if (! $managePermission || ! $this->scope->allowsProjectRecord($actor, 'consumer_progress', 'manage', (int) $application->branch_id, $application->project_id)) {
            throw new AuthorizationException('Anda tidak berwenang melakukan tindakan lifecycle pada aplikasi konsumen ini.');
        }
    }

    private function snapshot(ConsumerApplication $application): array
    {
        return collect($application->getAttributes())->map(function (mixed $value): mixed {
            return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
        })->all();
    }

    private function audit(User $actor, ConsumerApplication $application, array $before, array $after): void
    {
        ActivityLog::query()->create([
            'causer_id' => $actor->id,
            'subject_type' => ConsumerApplication::class,
            'subject_id' => $application->id,
            'event' => 'consumer_ganti_konsumen',
            'description' => 'Konsumen aplikasi diganti dengan aplikasi pengganti.',
            'properties' => [
                'action' => 'ganti_konsumen',
                'source' => 'manual',
                'before' => $before,
                'after' => $after,
            ],
        ]);
    }
}
