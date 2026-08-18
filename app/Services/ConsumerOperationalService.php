<?php

namespace App\Services;

use App\Models\ConsumerApplication;
use App\Models\ConsumerPsjb;
use App\Models\ConsumerStageEvent;
use App\Models\Kavling;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class ConsumerOperationalService
{
    private const CONSUMER_STATUSES = ['Lanjut', 'Mundur', 'Pindah Kavling', 'Reject'];

    private const REQUIRED_FIELDS = [
        'customer.name' => 'Nama Konsumen', 'customer.phone' => 'No HP', 'customer.date_of_birth' => 'Tanggal Lahir',
        'customer.occupation' => 'Pekerjaan', 'customer.address' => 'Alamat', 'customer.kelurahan' => 'Kelurahan',
        'customer.kecamatan' => 'Kecamatan', 'customer.kabupaten_kota' => 'Kabupaten/Kota',
        'customer.emergency_contact_name' => 'Nama Kontak Darurat', 'customer.emergency_contact_phone' => 'No HP Kontak Darurat',
    ];

    public function statuses(): array
    {
        return self::CONSUMER_STATUSES;
    }

    public function visibleQuery(User $user, OrganizationScopeService $scope): Builder
    {
        return ConsumerApplication::query()
            ->whereIn('branch_id', $scope->branchIds($user, 'consumer_progress'))
            ->whereIn('project_id', $scope->projectIds($user, 'consumer_progress'));
    }

    public function completeness(ConsumerApplication $application): array
    {
        $missing = [];
        foreach (self::REQUIRED_FIELDS as $path => $label) {
            $value = data_get($application, $path);
            if ($value === null || trim((string) $value) === '') {
                $missing[] = $label;
            }
        }

        return ['status' => $missing === [] ? 'Data Lengkap' : 'Data Belum Lengkap', 'missing' => $missing, 'missing_count' => count($missing)];
    }

    public function processLast(ConsumerApplication $application): ?string
    {
        $order = ['bi_checking' => 'BI Checking', 'PSJB' => 'PSJB', 'pemberkasan' => 'Pemberkasan', 'proses_bank' => 'Proses Bank', 'ppjb_dev' => 'PPJB Developer', 'akad' => 'Akad', 'bast' => 'BAST'];
        $rank = array_flip(array_keys($order));
        $stage = $application->stageEvents->sortByDesc(fn ($event) => [$rank[$event->stage] ?? -1, $event->occurred_at?->timestamp ?? 0, $event->id])->first()?->stage;

        return $stage === null ? null : ($order[$stage] ?? $stage);
    }

    public function create(array $data, User $actor, ConsumerKavlingLifecycleService $lifecycle): ConsumerApplication
    {
        return \DB::transaction(function () use ($data, $actor, $lifecycle): ConsumerApplication {
            $application = ConsumerApplication::create([
                'customer_id' => $data['customer_id'], 'branch_id' => $data['branch_id'], 'project_id' => $data['project_id'],
                'sales_user_id' => $data['sales_user_id'] ?? null, 'promo_id' => $data['promo_id'] ?? null,
                'application_status' => 'draft', 'status_cash' => $data['status_cash'] ?? null,
                'consumer_status' => $data['consumer_status'] ?? 'Lanjut', 'notes' => $data['notes'] ?? null,
            ]);
            if (! empty($data['kavling_id'])) {
                $lifecycle->assign($application, Kavling::query()->findOrFail($data['kavling_id']));
            }
            $this->refreshDerived($application->fresh(['customer', 'stageEvents']), $actor);

            return $application->fresh(['customer', 'kavling', 'project', 'sales', 'promo']);
        });
    }

    public function update(ConsumerApplication $application, array $data, User $actor, ConsumerKavlingLifecycleService $lifecycle): ConsumerApplication
    {
        return \DB::transaction(function () use ($application, $data, $actor, $lifecycle): ConsumerApplication {
            $application->update([
                'sales_user_id' => $data['sales_user_id'] ?? null, 'promo_id' => $data['promo_id'] ?? null,
                'status_cash' => $data['status_cash'] ?? null, 'notes' => $data['notes'] ?? null,
            ]);
            $status = $data['consumer_status'] ?? null;
            if ($status === 'Mundur' && $application->consumer_status !== 'Mundur') {
                $lifecycle->mundur($application);
            } elseif ($status === 'Pindah Kavling') {
                if (empty($data['target_kavling_id'])) {
                    throw new DomainException('Kavling tujuan wajib dipilih.');
                }
                $lifecycle->pindahKavling($application, Kavling::query()->findOrFail($data['target_kavling_id']));
            } elseif ($status === 'Reject') {
                $application->update(['consumer_status' => 'Reject']);
            } elseif ($status === 'Lanjut') {
                $application->update(['consumer_status' => 'Lanjut']);
            }
            $this->refreshDerived($application->fresh(['customer', 'stageEvents']), $actor);

            return $application->fresh(['customer', 'kavling', 'project', 'sales', 'promo']);
        });
    }

    public function recordBiChecking(ConsumerApplication $application, array $data, User $actor): ConsumerStageEvent
    {
        return \DB::transaction(function () use ($application, $data, $actor): ConsumerStageEvent {
            $date = CarbonImmutable::parse($data['tanggal_slik']);
            $id = $this->nextId('bi_checking', $date, $application, $data['hasil_slik']);
            $event = $application->stageEvents()->create([
                'stage' => 'bi_checking', 'source_id' => $id, 'source' => 'manual', 'occurred_at' => $date,
                'status' => $data['hasil_slik'], 'notes' => $data['keterangan'] ?? null, 'actor_id' => $actor->id,
                'metadata' => ['id_kavling' => $application->kavling?->kavling_code],
            ]);
            $application->update(['current_stage' => 'bi_checking']);
            $this->refreshDerived($application->fresh(['customer', 'stageEvents']), $actor);

            return $event;
        });
    }

    public function recordPsjb(ConsumerApplication $application, array $data, User $actor): ConsumerPsjb
    {
        return \DB::transaction(function () use ($application, $data, $actor): ConsumerPsjb {
            $bi = $application->stageEvents()->where('stage', 'bi_checking')->latest('occurred_at')->latest('id')->first();
            if ($bi === null) {
                throw new DomainException('BI Checking wajib diinput sebelum PSJB.');
            }
            $date = CarbonImmutable::parse($data['tanggal_psjb']);
            $id = $this->nextId('PSJB', $date, $application, $data['cara_pembayaran'] ?? '');
            $event = $application->stageEvents()->create([
                'stage' => 'PSJB', 'source_id' => $id, 'source' => 'manual', 'occurred_at' => $date,
                'status' => $data['status'] ?? null, 'notes' => $data['keterangan'] ?? null, 'actor_id' => $actor->id,
                'metadata' => ['id_kons' => $bi->source_id],
            ]);
            $psjb = $application->psjbs()->create($data + [
                'consumer_stage_event_id' => $event->id, 'id_kavling' => $application->kavling?->kavling_code,
                'id_kons' => $bi->source_id, 'id_psjb' => $id,
                'nama_koordinator' => $application->sales?->currentSalesCoordinators()->first()?->name,
                'nama_sales' => $application->sales?->name, 'promo_id' => $application->promo_id,
            ]);
            $application->update(['current_stage' => 'PSJB']);
            $this->refreshDerived($application->fresh(['customer', 'stageEvents']), $actor);

            return $psjb;
        });
    }

    public function nextId(string $stage, CarbonImmutable $date, ConsumerApplication $application, string $seed): string
    {
        $suffix = Str::upper(Str::substr((string) ($application->kavling?->kavling_code ?? $application->kavling?->name ?? 'XXX'), -3));
        $token = $stage === 'bi_checking' ? Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $seed) ?: 'UNK', 0, 3)) : Str::upper(Str::substr(preg_replace('/[^A-Za-z0-9]/', '', $seed) ?: 'XX', 0, 2));
        $prefix = $date->format('ymd').'-'.$token.'-'.$suffix;
        $count = $application->stageEvents()->where('stage', $stage)->where('source_id', 'like', $prefix.'-%')->count() + 1;

        return $prefix.'-'.str_pad((string) $count, 2, '0', STR_PAD_LEFT);
    }

    private function refreshDerived(ConsumerApplication $application, User $actor): void
    {
        $completeness = $this->completeness($application);
        $application->update(['source_completeness_status' => $completeness['status'], 'source_last_process' => $this->processLast($application)]);
    }
}
