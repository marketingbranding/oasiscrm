<?php

namespace App\Services;

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
        private WorkspaceAccessService $workspace,
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

        $query = ConsumerApplication::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('project_id', $projectIds)
            ->with(['branch', 'customer', 'project', 'kavling', 'sales']);

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

        $applications = $query->orderBy($sort, $direction)->orderBy('consumer_applications.id')->paginate(25)->withQueryString();
        $applications->setCollection($applications->getCollection()->map(function (ConsumerApplication $application) use ($module): array {
            $record = $module['relation'] ? $application->{$module['relation']}->first() : $application;

            return ['source_id' => $module['key'].':'.$record->getKey(), 'application' => $application, 'record' => $record];
        }));

        return [
            'module' => $module,
            'rows' => $applications,
            'branches' => $this->workspace->accessibleBranches($user)->whereIn('id', $branchIds)->values(),
            'projects' => $this->workspace->accessibleProjects($user)->whereIn('id', $projectIds)->when($branchId, fn ($items) => $items->where('branch_id', $branchId))->values(),
            'filterColumns' => collect($module['columns'])->where('filterable', true)->values(),
        ];
    }

    private function process(Builder $query, array $module): void
    {
        if (! $module['relation']) {
            return;
        }

        $relation = $module['relation'];
        $constraint = fn (Builder|Relation $related) => $this->related($related, $module);
        $query->whereHas($relation, $constraint)->with([$relation => fn (Builder|Relation $related) => $constraint($related)->latest('id')]);
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
            $query->whereHas($module['relation'], fn (Builder $related) => $this->related($related, $module)->where($field, $value));
        } else {
            $query->where($field, $value);
        }
    }

    private function related(Builder|Relation $query, array $module): Builder|Relation
    {
        return match ($module['relation']) {
            'stageEvents' => $query->where('stage', $module['stage']),
            'psjbs', 'ppjbDevelopers', 'akadRecords', 'bastRecords' => $query->whereHas('event', fn (Builder $event) => $event->where('stage', $module['stage'])),
            'bankProcesses' => $module['stage'] === 'pemberkasan'
                ? $query->where(fn (Builder $bank) => $bank->whereNotNull('tipe_pemberkasan')->orWhereNotNull('tanggal_terima_bank'))
                : $query->where(fn (Builder $bank) => $bank->whereNotNull('response_type')->orWhereNotNull('approved_plafond')->orWhereNotNull('approved_tenor')->orWhereNotNull('verified_at')->orWhereNotNull('sp3k_at')->orWhereNotNull('rejected_at')),
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
