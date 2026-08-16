<?php

namespace App\Services;

use App\Data\ConsumerComparisonRecord;
use App\Models\Branch;
use App\Models\Kavling;
use App\Models\KonsumenProgressSheetRow;
use App\Models\LeadMaster;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Str;

class LegacyConsumerReadService
{
    public function __construct(private readonly KonsumenPipelineService $pipeline) {}

    /** @return array<int, ConsumerComparisonRecord> */
    public function records(Branch $branch, LeadMaster $project): array
    {
        $rows = KonsumenProgressSheetRow::query()
            ->where('branch_id', $branch->id)
            ->orderBy('id')
            ->get(['sheet_name', 'row_data']);

        $byKavling = [];
        $customers = [];
        foreach ($rows->where('sheet_name', 'data_konsumen') as $row) {
            $data = $this->data($row->row_data);
            $key = $this->value($data, ['id_kavling', 'id kavling', 'kavling', 'kav']);
            if ($key !== '') {
                $customers[$key] = $data;
            }
        }

        $stageOrder = array_flip(array_keys(KonsumenPipelineService::STAGES));
        $kavlings = Kavling::query()->where('project_id', $project->id)->get()->flatMap(fn (Kavling $kavling) => collect([
            Str::lower($kavling->kavling_code) => $kavling,
            Str::lower($kavling->name) => $kavling,
        ]))->all();
        $sales = User::query()->where('branch_id', $branch->id)->get()->keyBy(fn (User $user) => Str::lower($user->name));
        $unknownStages = [];
        foreach ($rows->whereNotIn('sheet_name', ['data_konsumen']) as $row) {
            $data = $this->data($row->row_data);
            $key = $this->value($data, ['id_kavling', 'id kavling', 'kavling', 'kav']);
            $canonicalStage = $this->pipeline->canonicalStage($row->sheet_name);
            if ($canonicalStage === null) {
                $unknownStages[$row->sheet_name] = true;

                continue;
            }
            $rank = $stageOrder[$canonicalStage] ?? -1;
            $currentRank = $stageOrder[$byKavling[$key][0] ?? null] ?? -1;
            if ($key !== '' && $rank >= $currentRank) {
                $byKavling[$key] = [$canonicalStage, $data];
            }
        }

        $records = [];
        foreach ($customers as $kavling => $customer) {
            [$sheet, $stageData] = $byKavling[$kavling] ?? [null, []];
            $name = $this->value($customer, ['nama_konsumen', 'nama konsumen', 'nama']);
            if ($name === '') {
                continue;
            }
            $stage = $sheet ?: null;
            $projectName = $this->value($customer, ['project_name', 'proyek', 'project']);
            $kavlingModel = $kavlings[Str::lower($kavling)] ?? null;
            if (! $this->matchProject($project, $projectName, $kavlingModel)) {
                continue;
            }
            $salesName = $this->value($stageData + $customer, ['sales', 'sales_pic', 'nama_sales', 'sales name']);
            $salesModel = $salesName === '' ? null : $sales->get(Str::lower($salesName));
            $bank = $this->value($stageData + $customer, ['bank', 'nama_bank', 'bank_name']);
            $bankStatus = $this->value($stageData + $customer, ['status_bank', 'bank_status']);
            $external = $this->value($customer, ['external_id', 'external_key', 'id_konsumen', 'id_customer', 'id_lead']);
            $legacyKey = $external !== '' ? 'external:'.Str::lower($external) : 'kavling:'.Str::lower($kavling);

            $records[] = new ConsumerComparisonRecord(
                legacyKey: $legacyKey,
                localApplicationId: null,
                customerName: $name,
                phone: $this->phone($this->value($customer, ['no_hp', 'no hp', 'phone', 'nomor hp'])),
                branchId: $branch->id,
                projectId: $project->id,
                salesLabel: $salesName ?: null,
                salesUserId: $salesModel?->id,
                kavlingLabel: $kavling,
                kavlingId: $kavlingModel?->id,
                applicationStatus: null,
                currentStage: $stage,
                bookingDate: $this->date($this->value($customer, ['tanggal_booking', 'booking_date'])),
                akadDate: $this->date($this->value($stageData + $customer, ['tanggal_akad', 'akad_date', 'tgl_akad'])),
                bankName: $bank ?: null,
                bankStatus: $bankStatus ?: null,
                values: ['project_name' => $projectName ?: null],
                notes: $unknownStages === [] ? [] : ['Tahap legacy tidak dikenal: '.implode(', ', array_keys($unknownStages)).'.'],
            );
        }

        return $records;
    }

    private function matchProject(LeadMaster $project, string $name, ?Kavling $kavling): bool
    {
        return ($name !== '' && Str::lower(trim($name)) === Str::lower($project->project_name))
            || ($name === '' && $kavling !== null);
    }

    private function data(?array $data): array
    {
        return $data ?? [];
    }

    private function value(array $data, array $keys): string
    {
        $normalized = collect($data)->mapWithKeys(fn ($value, $key) => [Str::lower(trim((string) $key)) => is_scalar($value) ? trim((string) $value) : '']);
        foreach ($keys as $key) {
            if (($value = $normalized->get(Str::lower($key), '')) !== '') {
                return $value;
            }
        }

        return '';
    }

    private function phone(string $value): ?string
    {
        $phone = preg_replace('/[^0-9+]/', '', $value);
        if (str_starts_with($phone, '+62')) {
            return '0'.substr($phone, 3);
        }
        if (str_starts_with($phone, '62')) {
            return '0'.substr($phone, 2);
        }

        return $phone ?: null;
    }

    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'Y/m/d', 'n/j/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
