<?php

namespace App\Services;

use App\Data\ConsumerLeadLinkSubject;
use App\Models\ConsumerApplication;
use App\Models\ConsumerNup;
use App\Models\KonsumenProgressSheetRow;
use App\Models\User;
use Illuminate\Support\Str;

final class ConsumerLeadLinkSubjectResolver
{
    public function fromApplication(ConsumerApplication $application): ConsumerLeadLinkSubject
    {
        return ConsumerLeadLinkSubject::fromApplication($application);
    }

    public function fromNup(ConsumerNup $nup): ConsumerLeadLinkSubject
    {
        return ConsumerLeadLinkSubject::fromNup($nup);
    }

    public function fromSheetRow(KonsumenProgressSheetRow $row): ConsumerLeadLinkSubject
    {
        $data = collect($row->row_data ?? [])->mapWithKeys(fn ($value, $key): array => [Str::lower(trim((string) $key)) => is_scalar($value) ? trim((string) $value) : '']);
        $projectName = $data->get('proyek', $data->get('project', $data->get('project_name', '')));
        $project = $projectName === '' ? null : $this->projects->resolveExact($row->branch_id, $projectName);
        $salesName = $data->get('sales', $data->get('nama sales', $data->get('sales_pic', '')));
        $sales = $salesName === '' ? null : User::query()->where('branch_id', $row->branch_id)->whereRaw('LOWER(name) = ?', [Str::lower($salesName)])->first();

        return ConsumerLeadLinkSubject::fromSheetRow($row, $project?->id, $sales?->id);
    }

    public function __construct(private readonly ProjectIdentityResolver $projects) {}
}
