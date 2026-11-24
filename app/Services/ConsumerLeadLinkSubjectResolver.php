<?php

namespace App\Services;

use App\Data\ConsumerLeadLinkSubject;
use App\Models\ConsumerApplication;
use App\Models\ConsumerNup;
use App\Models\KonsumenProgressSheetRow;
use App\Models\LeadMaster;
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

    public function fromSheetRow(KonsumenProgressSheetRow $row, ?int $projectId = null, ?int $salesUserId = null): ConsumerLeadLinkSubject
    {
        if ($projectId === null || $salesUserId === null) {
            $data = collect($row->row_data ?? [])->mapWithKeys(fn ($value, $key): array => [Str::lower(trim((string) $key)) => is_scalar($value) ? trim((string) $value) : '']);
            $projectName = $data->get('proyek', $data->get('project', $data->get('project_name', '')));
            $projectId ??= $projectName === '' ? null : $this->projects->resolveExact($row->branch_id, $projectName)?->id;
            $salesName = $data->get('sales', $data->get('nama sales', $data->get('sales_pic', '')));
            $salesUserId ??= $salesName === '' ? null : User::query()->where('branch_id', $row->branch_id)->whereRaw('LOWER(name) = ?', [Str::lower($salesName)])->value('id');
        }

        return ConsumerLeadLinkSubject::fromSheetRow($row, $projectId, $salesUserId);
    }

    /** @return array{project_ids: array<string, int>, sales_ids: array<string, int>} */
    public function lookupMaps(iterable $rows, array $projectIds): array
    {
        $projectNames = [];
        $salesNames = [];
        foreach ($rows as $row) {
            $data = collect($row->row_data ?? [])->mapWithKeys(fn ($value, $key): array => [Str::lower(trim((string) $key)) => is_scalar($value) ? trim((string) $value) : '']);
            $projectNames[] = Str::lower($data->get('proyek', $data->get('project', $data->get('project_name', ''))));
            $salesNames[] = Str::lower($data->get('sales', $data->get('nama sales', $data->get('sales_pic', ''))));
        }
        $projects = LeadMaster::query()->whereIn('id', $projectIds)->get(['id', 'branch_id', 'project_name']);
        $sales = User::query()->whereIn('branch_id', $projects->pluck('branch_id')->unique())->get(['id', 'branch_id', 'name']);

        return [
            'project_ids' => $projects->flatMap(fn ($project): array => [Str::lower($project->project_name) => $project->id])->all(),
            'sales_ids' => $sales->flatMap(fn ($user): array => [Str::lower($user->name) => $user->id])->all(),
        ];
    }

    public function __construct(private readonly ProjectIdentityResolver $projects) {}
}
