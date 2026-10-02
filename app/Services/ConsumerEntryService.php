<?php

namespace App\Services;

use App\Models\ConsumerApplication;
use App\Models\ConsumerProcessApplicability;
use App\Models\Customer;
use App\Models\LeadMaster;
use App\Models\User;
use App\Support\ConsumerIdentity;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ConsumerEntryService
{
    public function __construct(
        private readonly OrganizationScopeService $scope,
        private readonly ConsumerOperationalService $operational,
        private readonly ConsumerKavlingLifecycleService $kavlingLifecycle,
    ) {}

    public function create(array $data, User $actor, bool $skipAuthorization = false): ConsumerApplication
    {
        if (! $skipAuthorization) {
            $this->assertManageScope($actor, (int) $data['branch_id'], (int) $data['project_id']);
        }
        $project = LeadMaster::query()->whereKey($data['project_id'])->where('branch_id', $data['branch_id'])->where('is_active', true)->first();
        if ($project === null) {
            throw new DomainException('Proyek konsumen tidak valid untuk cabang yang dipilih.');
        }

        return DB::transaction(function () use ($data, $actor): ConsumerApplication {
            $customer = $this->resolveOrCreateCustomer($data);
            $paymentMethod = $data['payment_method'] ?? null;
            $historical = ($data['entry_mode'] ?? 'new') !== 'new';
            $acquisitionSource = $data['acquisition_source'] ?? (filled($data['sales_lead_id'] ?? null) ? 'lead' : (filled($data['source_nup_id'] ?? null) ? 'nup' : 'direct'));
            $application = $this->operational->create([
                'customer_id' => $customer->id,
                'branch_id' => $data['branch_id'],
                'project_id' => $data['project_id'],
                'sales_user_id' => $data['sales_user_id'] ?? null,
                'kavling_id' => $data['kavling_id'] ?? null,
                'promo_id' => $data['promo_id'] ?? null,
                'sales_lead_id' => $data['sales_lead_id'] ?? null,
                'source_nup_id' => $data['source_nup_id'] ?? null,
                'payment_method' => $paymentMethod,
                'status_cash' => $paymentMethod === 'cash' ? true : ($data['status_cash'] ?? null),
                'current_process' => $data['current_process'] ?? 'data_konsumen',
                'current_process_source' => $historical ? 'historical_entry' : 'operational',
                'entry_mode' => $data['entry_mode'] ?? 'new',
                'acquisition_source' => $acquisitionSource,
                'transaction_status' => $data['transaction_status'] ?? 'LANJUT',
                'historical_entered_at' => $historical ? now() : null,
                'historical_entered_by' => $historical ? $actor->id : null,
                'notes' => $data['notes'] ?? null,
            ], $actor, $this->kavlingLifecycle);

            if ($paymentMethod === 'cash') {
                $this->markCashNotApplicable($application, $actor);
            }

            return $application->fresh(['customer', 'kavling', 'project', 'sales']);
        });
    }

    public function resolveOrCreateCustomer(array $data): Customer
    {
        $nik = trim((string) ($data['nik'] ?? ''));
        $customer = null;
        if ($nik !== '') {
            $matches = Customer::withTrashed()->where('nik_hash', ConsumerIdentity::nikHash($nik))->get();
            if ($matches->count() > 1) {
                throw new DomainException('NIK cocok dengan lebih dari satu konsumen. Pilih konsumen secara eksplisit.');
            }
            $customer = $matches->first();
        }

        if ($customer === null && filled($data['phone'] ?? null)) {
            $matches = Customer::query()->where('phone', $this->normalizePhone((string) $data['phone']))->get();
            if ($matches->count() > 1) {
                throw new DomainException('Nomor HP cocok dengan lebih dari satu konsumen. Pilih konsumen secara eksplisit.');
            }
            $customer = $matches->first();
        }

        $attributes = array_intersect_key($data, array_flip([
            'name', 'phone', 'email', 'date_of_birth', 'occupation', 'occupation_detail', 'address',
            'kelurahan', 'kecamatan', 'kabupaten_kota', 'emergency_contact_name', 'emergency_contact_phone',
        ]));
        if (isset($attributes['phone'])) {
            $attributes['phone'] = $this->normalizePhone((string) $attributes['phone']);
        }
        if ($nik !== '') {
            $attributes['nik_encrypted'] = $nik;
        }

        if ($customer === null) {
            return Customer::create($attributes);
        }

        $customer->fill($attributes)->save();

        return $customer;
    }

    private function assertManageScope(User $actor, int $branchId, int $projectId): void
    {
        $hasManage = $actor->hasPermission('consumer_progress.manage')
            || $actor->hasScopedPermission('consumer_progress', 'manage');
        if (! $hasManage || ! $this->scope->allowsProjectRecord($actor, 'consumer_progress', 'manage', $branchId, $projectId)) {
            throw new AuthorizationException('Anda tidak berwenang mengelola data konsumen ini.');
        }
    }

    private function markCashNotApplicable(ConsumerApplication $application, User $actor): void
    {
        foreach (['proses_bank', 'sp3k'] as $process) {
            ConsumerProcessApplicability::updateOrCreate(
                ['consumer_application_id' => $application->id, 'process_key' => $process],
                ['applicability' => 'not_applicable', 'reason' => 'Cara pembayaran Cash tidak melalui proses bank.', 'source' => 'manual', 'source_id' => (string) $actor->id],
            );
        }
    }

    private function normalizePhone(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($normalized, '62') ? '0'.substr($normalized, 2) : $normalized;
    }
}
