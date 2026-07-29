<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\Expense;
use App\Models\LeadMaster;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class CommentableAccessService
{
    // Konsumen Progress is sheet-row data without a stable local target ID, so it is intentionally excluded.
    private const ALIASES = [
        'sales-lead' => SalesLead::class,
        'planner-item' => ContentItem::class,
        'sales-agenda' => ContentItem::class,
        'expense' => Expense::class,
        'bridge-fund' => DanaTalangan::class,
    ];

    public function resolve(string $alias, int|string $id): ?Model
    {
        $class = self::ALIASES[$alias] ?? null;
        if (! $class || filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return null;
        }

        $model = $class::query()->find((int) $id);

        return $model && $this->matchesAlias($alias, $model) ? $model : null;
    }

    public function aliasFor(Model $model): ?string
    {
        return match (true) {
            $model instanceof SalesLead => 'sales-lead',
            $model instanceof ContentItem && $this->isSalesAgenda($model) => 'sales-agenda',
            $model instanceof ContentItem => 'planner-item',
            $model instanceof Expense => 'expense',
            $model instanceof DanaTalangan => 'bridge-fund',
            default => null,
        };
    }

    public function canonicalExternalAlias(Model|string $target): ?string
    {
        return $target instanceof Model
            ? $this->aliasFor($target)
            : (array_key_exists($target, self::ALIASES) ? $target : null);
    }

    public function label(Model $model): ?string
    {
        return match (true) {
            $model instanceof SalesLead => $model->customer_name,
            $model instanceof ContentItem => $model->title,
            $model instanceof Expense => $model->description,
            $model instanceof DanaTalangan => $model->nama_konsumen,
            default => null,
        };
    }

    public function branchId(Model $model): ?int
    {
        return isset($model->branch_id) ? (int) $model->branch_id : null;
    }

    public function projectId(Model $model): ?int
    {
        $id = match (true) {
            $model instanceof SalesLead, $model instanceof Expense => $model->project_id,
            $model instanceof ContentItem => $model->sales_project_id,
            default => null,
        };

        return $id ? (int) $id : null;
    }

    /** @return array{name: string, parameters: array<string, mixed>}|null */
    public function targetRoute(Model $model): ?array
    {
        $alias = $this->aliasFor($model);

        return $alias ? ['name' => 'comments.thread', 'parameters' => ['alias' => $alias, 'id' => $model->getKey()]] : null;
    }

    /** @return array{name: string, parameters: array<string, mixed>}|null */
    public function sourceRoute(Model $model): ?array
    {
        return match ($this->aliasFor($model)) {
            'sales-lead' => ['name' => 'sales-pocketbook.index', 'parameters' => []],
            'sales-agenda' => ['name' => 'sales-pocketbook.index', 'parameters' => ['tab' => 'agenda']],
            'planner-item' => ['name' => 'content-calendar.index', 'parameters' => []],
            'expense' => ['name' => 'expenses.show', 'parameters' => ['expense' => $model]],
            'bridge-fund' => ['name' => 'dana-talangan.index', 'parameters' => []],
            default => null,
        };
    }

    public function targetUrl(Model $model, ?string $fragment = null): ?string
    {
        $target = $this->targetRoute($model);
        if (! $target) {
            return null;
        }

        $url = route($target['name'], $target['parameters']);
        $fragment = ltrim((string) $fragment, '#');

        return $fragment === '' ? $url : $url.'#'.rawurlencode($fragment);
    }

    public function canView(User $user, Model $model): bool
    {
        if (! $this->aliasFor($model) || (method_exists($model, 'trashed') && $model->trashed())) {
            return false;
        }

        if ($model instanceof ContentItem && $this->isSalesAgenda($model)) {
            return $this->canViewSalesAgenda($user, $model);
        }

        return Gate::forUser($user)->allows('view', $model);
    }

    private function matchesAlias(string $alias, Model $model): bool
    {
        if (! $model instanceof ContentItem) {
            return true;
        }

        return $alias === 'sales-agenda' ? $this->isSalesAgenda($model) : ! $this->isSalesAgenda($model);
    }

    private function isSalesAgenda(ContentItem $item): bool
    {
        return $item->item_type === 'agenda' && $item->agenda_type === ContentItem::SALES_AGENDA_TYPE;
    }

    private function canViewSalesAgenda(User $user, ContentItem $agenda): bool
    {
        if (! $user->hasScopedPermission('sales_pocketbook')) {
            return false;
        }

        if ($user->hasPermission('sales_pocketbook.view_all')) {
            return true;
        }

        $scope = app(OrganizationScopeService::class);
        $workspace = app(WorkspaceAccessService::class);
        if (! $workspace->canViewBranch($user, $agenda->branch_id)
            || ! in_array((int) $agenda->owner_user_id, $scope->visibleUserIds($user, 'sales_pocketbook'), true)) {
            return false;
        }

        $projectIds = $scope->projectIds($user, 'sales_pocketbook');
        if ($agenda->sales_project_id) {
            return in_array((int) $agenda->sales_project_id, $projectIds, true);
        }

        return $agenda->project_name !== null
            && LeadMaster::query()->whereIn('id', $projectIds)
                ->where('branch_id', $agenda->branch_id)
                ->where('project_name', $agenda->project_name)
                ->exists();
    }
}
