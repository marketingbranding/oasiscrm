<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\LeadMaster;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

final class ConsumerApplicationQueryService
{
    public function __construct(
        private DatabaseModuleRegistry $registry,
        private OrganizationScopeService $scope,
        private ConsumerDatabaseWriteService $writer,
    ) {}

    public function dataset(User $user, string $slug, Request $request): array
    {
        $module = $this->registry->get($slug);
        $branchIds = $this->scope->branchIds($user, 'consumer_progress');
        $projectIds = $this->scope->projectIds($user, 'consumer_progress');
        $branchId = $request->integer('branch_id') ?: null;
        $projectId = $request->integer('project_id') ?: null;

        abort_if($request->filled('branch_id') && ! in_array($branchId, $branchIds, true), 403);
        abort_if($request->filled('project_id') && ! in_array($projectId, $projectIds, true), 403);
        if ($projectId) {
            $project = LeadMaster::query()->whereKey($projectId)->firstOrFail();
            abort_if($branchId && (int) $project->branch_id !== $branchId, 403);
        }

        $relations = collect($module['columns'])
            ->pluck('path')
            ->filter(fn (string $path) => str_starts_with($path, 'application.'))
            ->map(fn (string $path) => str($path)->after('application.')->contains('.') ? str($path)->after('application.')->beforeLast('.')->toString() : null)
            ->filter()
            ->unique()
            ->all();
        if (! $module['relation']) {
            $relations = collect($module['columns'])
                ->pluck('path')
                ->filter(fn (string $path) => str_starts_with($path, 'record.'))
                ->map(fn (string $path) => str($path)->after('record.')->contains('.') ? str($path)->after('record.')->beforeLast('.')->toString() : null)
                ->filter()
                ->unique()
                ->all();
        }

        $query = ConsumerApplication::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('project_id', $projectIds)
            ->with($relations);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        if ($projectId) {
            $query->where('project_id', $projectId);
        }
        $this->search($query, trim((string) $request->query('search')));
        $this->process($query, $module);
        $this->filter($query, $module, (string) $request->query('filter_column'), trim((string) $request->query('filter')));

        $sorts = collect($module['columns'])->where('sortable', true)->mapWithKeys(fn (array $column) => [$column['key'] => match ($column['key']) {
            'customer_name' => 'customers.name',
            'project' => 'lead_master.project_name',
            default => 'consumer_applications.created_at',
        }])->all();
        $sort = $sorts[$request->query('sort')] ?? 'consumer_applications.created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        if ($sort === 'customers.name') {
            $query->leftJoin('customers', 'customers.id', '=', 'consumer_applications.customer_id')->select('consumer_applications.*');
        } elseif ($sort === 'lead_master.project_name') {
            $query->leftJoin('lead_master', 'lead_master.id', '=', 'consumer_applications.project_id')->select('consumer_applications.*');
        }

        $canManage = $user->hasPermission('consumer_progress.manage') || $user->hasScopedPermission('consumer_progress', 'manage');
        $manageBranchIds = $canManage ? $this->scope->branchIds($user, 'consumer_progress', 'manage') : [];
        $manageProjectIds = $canManage ? $this->scope->projectIds($user, 'consumer_progress', 'manage') : [];
        $applications = $query->orderBy($sort, $direction)->orderBy('consumer_applications.id')->paginate(25)->withQueryString();
        $applications->setCollection($applications->getCollection()->map(function (ConsumerApplication $application) use ($module, $manageBranchIds, $manageProjectIds): array {
            $record = $module['relation'] ? $application->{$module['relation']}->first() : $application;
            $canEdit = $module['key'] === 'data-konsumen' && in_array((int) $application->branch_id, $manageBranchIds, true) && in_array((int) $application->project_id, $manageProjectIds, true);
            $cells = [];
            if ($canEdit) {
                foreach ($module['columns'] as $column) {
                    if (! $column['editable'] || ($column['write_strategy'] === 'customer_field' && ! $application->customer)) {
                        continue;
                    }
                    $target = $column['write_strategy'] === 'customer_field' ? $application->customer : $application;
                    $cells[$column['key']] = ['value' => $target->{$column['write_target']['field']}, 'write_token' => $this->writer->token($target)];
                }
            }

            return ['source_id' => $module['key'].':'.$record->getKey(), 'application' => $application, 'record' => $record, 'can_edit' => $canEdit, 'update_url' => $canEdit ? route('consumer-database.cell.update', [$module['key'], $application->id]) : null, 'editable_cells' => $cells];
        }));

        return [
            'module' => $module,
            'rows' => $applications,
            'branches' => Branch::query()->whereIn('id', $branchIds)->forDropdown()->get(),
            'projects' => LeadMaster::query()->with('branch')->whereIn('id', $projectIds)->when($branchId, fn (Builder $projects) => $projects->where('branch_id', $branchId))->orderBy('project_name')->get(),
            'filterColumns' => collect($module['columns'])->where('filterable', true)->values(),
        ];
    }

    private function process(Builder $query, array $module): void
    {
        if (! $module['relation']) {
            return;
        }

        $relation = $module['relation'];
        $constraint = fn (Builder|Relation $related) => $this->latest($related, $module);
        $query->whereHas($relation, $constraint)->with([$relation => $constraint]);
    }

    private function filter(Builder $query, array $module, string $key, string $value): void
    {
        if ($value === '') {
            return;
        }

        $column = collect($module['columns'])->first(fn (array $column) => $column['key'] === $key && $column['filterable']);
        if (! $column) {
            return;
        }

        $field = str($column['path'])->afterLast('.')->toString();
        if ($module['relation']) {
            $query->whereHas($module['relation'], fn (Builder $related) => $this->latest($related, $module)->where($field, $value));
        } else {
            $query->where($field, $value);
        }
    }

    private function latest(Builder|Relation $query, array $module): Builder|Relation
    {
        $query = $this->related($query, $module);
        $table = $query->getModel()->getTable();
        $latest = $query->getModel()->newQuery()
            ->from("{$table} as latest_record")
            ->selectRaw('MAX(latest_record.id)')
            ->whereColumn('latest_record.consumer_application_id', "{$table}.consumer_application_id");
        $this->related($latest, $module, 'latest_record');

        return $query->where("{$table}.id", $latest)->orderByDesc("{$table}.id");
    }

    private function related(Builder|Relation $query, array $module, ?string $table = null): Builder|Relation
    {
        $column = fn (string $name) => $table ? "{$table}.{$name}" : $name;

        return match ($module['relation']) {
            'stageEvents' => $query->where($column('stage'), $module['stage']),
            'psjbs', 'ppjbDevelopers', 'akadRecords', 'bastRecords' => $query,
            'bankProcesses' => $module['stage'] === 'pemberkasan'
                ? $query->where(fn (Builder $bank) => $bank->whereNotNull($column('tipe_pemberkasan'))->orWhereNotNull($column('tanggal_terima_bank')))
                : $query->where(fn (Builder $bank) => $bank->whereNotNull($column('response_type'))->orWhereNotNull($column('approved_plafond'))->orWhereNotNull($column('approved_tenor'))->orWhereNotNull($column('verified_at'))->orWhereNotNull($column('sp3k_at'))->orWhereNotNull($column('rejected_at'))),
        };
    }

    private function search(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(fn (Builder $searchQuery) => $searchQuery
            ->whereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
            ->orWhereHas('kavling', fn (Builder $kavling) => $kavling->where('kavling_code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
            ->orWhereHas('project', fn (Builder $project) => $project->where('project_name', 'like', "%{$search}%")));
    }
}
