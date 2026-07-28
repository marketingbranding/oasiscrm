<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\LeadMaster;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ExpenseFilterService
{
    private const SORTS = [
        'expense_date', 'branch', 'project', 'category', 'description', 'vendor_name',
        'payment_method', 'amount', 'created_by', 'status',
    ];

    public function normalize(array $input): array
    {
        $now = CarbonImmutable::today();
        $minimumYear = 2000;
        $maximumYear = $now->year + 5;
        $periodMonth = is_string($input['period_month'] ?? null) && preg_match('/^(\d{4})-(\d{2})$/', $input['period_month'], $matches)
            ? [(int) $matches[1], (int) $matches[2]]
            : null;
        $month = $periodMonth[1] ?? filter_var($input['month'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]) ?: $now->month;
        $year = $periodMonth[0] ?? filter_var($input['year'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimumYear, 'max_range' => $maximumYear]]) ?: $now->year;
        if ($year < $minimumYear || $year > $maximumYear || $month < 1 || $month > 12) {
            [$year, $month] = [$now->year, $now->month];
        }

        $dateFrom = $this->date($input['date_from'] ?? null);
        $dateTo = $this->date($input['date_to'] ?? null);
        if ($dateFrom && $dateTo && $dateFrom->gt($dateTo)) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }
        $customPeriod = $dateFrom || $dateTo;
        $periodStart = $dateFrom ?? ($dateTo?->startOfMonth() ?? CarbonImmutable::create($year, $month, 1)->startOfDay());
        $periodEnd = $dateTo ?? ($dateFrom?->endOfMonth()->startOfDay() ?? CarbonImmutable::create($year, $month, 1)->endOfMonth()->startOfDay());

        $branchId = $this->activeId(Branch::query(), $input['branch_id'] ?? null);
        $projectId = $this->activeId(
            LeadMaster::query()
                ->whereNotNull('branch_id')
                ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId)),
            $input['project_id'] ?? null,
        );

        $status = in_array($input['status'] ?? null, [Expense::STATUS_ACTIVE, Expense::STATUS_CANCELLED, 'all'], true)
            ? $input['status']
            : Expense::STATUS_ACTIVE;
        $paymentMethod = array_key_exists((string) ($input['payment_method'] ?? ''), Expense::PAYMENT_METHODS)
            ? (string) $input['payment_method']
            : null;
        $sort = in_array($input['sort'] ?? null, self::SORTS, true) ? $input['sort'] : 'expense_date';
        $direction = ($input['dir'] ?? null) === 'asc' ? 'asc' : 'desc';

        return [
            'period_month' => sprintf('%04d-%02d', $year, $month),
            'month' => $month,
            'year' => $year,
            'date_from' => $dateFrom?->toDateString(),
            'date_to' => $dateTo?->toDateString(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'period_type' => $customPeriod ? 'custom' : 'month',
            'branch_id' => $branchId,
            'project_id' => $projectId,
            'expense_category_id' => $this->existingId(ExpenseCategory::query(), $input['expense_category_id'] ?? $input['category'] ?? null),
            'payment_method' => $paymentMethod,
            'created_by' => $this->existingId(User::query(), $input['created_by'] ?? null),
            'status' => $status,
            'search' => mb_substr(trim((string) ($input['search'] ?? '')), 0, 150),
            'sort' => $sort,
            'dir' => $direction,
            'per_page' => min(100, max(10, (int) ($input['per_page'] ?? 20))),
        ];
    }

    public function query(array $filters, bool $withSorting = true): Builder
    {
        $query = $this->baseQuery($filters)
            ->whereBetween('expense_date', [$filters['period_start']->toDateString(), $filters['period_end']->toDateString()]);

        if ($filters['status'] === 'all') {
            $query->whereIn('status', [Expense::STATUS_ACTIVE, Expense::STATUS_CANCELLED]);
        } else {
            $query->where('status', $filters['status']);
        }

        return $withSorting ? $this->sort($query, $filters) : $query;
    }

    public function summary(array $filters): array
    {
        $current = $this->activePeriodQuery($filters, $filters['period_start'], $filters['period_end']);
        $totals = (clone $current)->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(amount), 0) as total, COALESCE(AVG(amount), 0) as average')->first();
        [$previousStart, $previousEnd] = $this->previousPeriod($filters);
        $previousTotal = (float) $this->activePeriodQuery($filters, $previousStart, $previousEnd)->sum('amount');

        return [
            'total' => (float) $totals->total,
            'count' => (int) $totals->transaction_count,
            'average' => (float) $totals->average,
            'top_category' => $this->topGroup($current, 'expense_categories', 'expense_category_id', 'name'),
            'top_branch' => $this->topGroup($current, 'branches', 'branch_id', 'name'),
            'top_project' => $this->topGroup($current, 'lead_master', 'project_id', 'project_name'),
            'previous_total' => $previousTotal,
            'comparison_percent' => $previousTotal > 0 ? (((float) $totals->total - $previousTotal) / $previousTotal) * 100 : null,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
        ];
    }

    public function recapQuery(array $filters): Builder
    {
        return $this->query($filters, false)
            ->leftJoin('branches', 'branches.id', '=', 'expenses.branch_id')
            ->leftJoin('lead_master', 'lead_master.id', '=', 'expenses.project_id')
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->selectRaw("COALESCE(branches.name, '-') as branch_name, COALESCE(lead_master.project_name, '-') as project_name, COALESCE(expense_categories.name, '-') as category_name, COUNT(*) as transaction_count, SUM(expenses.amount) as total")
            ->groupBy('branches.id', 'branches.name', 'lead_master.id', 'lead_master.project_name', 'expense_categories.id', 'expense_categories.name')
            ->orderBy('branches.name')->orderBy('lead_master.project_name')->orderBy('expense_categories.name');
    }

    public function periodLabel(array $filters): string
    {
        return $filters['period_start']->format('d/m/Y').' - '.$filters['period_end']->format('d/m/Y');
    }

    private function baseQuery(array $filters): Builder
    {
        return Expense::query()
            ->when($filters['branch_id'], fn (Builder $query, int $id) => $query->where('expenses.branch_id', $id))
            ->when($filters['project_id'], fn (Builder $query, int $id) => $query->where('expenses.project_id', $id))
            ->when($filters['expense_category_id'], fn (Builder $query, int $id) => $query->where('expenses.expense_category_id', $id))
            ->when($filters['payment_method'], fn (Builder $query, string $method) => $query->where('expenses.payment_method', $method))
            ->when($filters['created_by'], fn (Builder $query, int $id) => $query->where('expenses.created_by', $id))
            ->when($filters['search'] !== '', function (Builder $query) use ($filters) {
                $search = '%'.$filters['search'].'%';
                $query->where(fn (Builder $searchQuery) => $searchQuery
                    ->where('expenses.description', 'like', $search)
                    ->orWhere('expenses.vendor_name', 'like', $search)
                    ->orWhere('expenses.reference_number', 'like', $search)
                    ->orWhere('expenses.notes', 'like', $search));
            });
    }

    private function activePeriodQuery(array $filters, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $this->baseQuery($filters)
            ->where('status', Expense::STATUS_ACTIVE)
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()]);
    }

    private function previousPeriod(array $filters): array
    {
        if ($filters['period_type'] === 'month') {
            $start = $filters['period_start']->subMonthNoOverflow()->startOfMonth();

            return [$start, $start->endOfMonth()->startOfDay()];
        }

        $days = $filters['period_start']->diffInDays($filters['period_end']) + 1;
        $end = $filters['period_start']->subDay();

        return [$end->subDays($days - 1), $end];
    }

    private function topGroup(Builder $query, string $table, string $foreignKey, string $labelColumn): ?array
    {
        $row = (clone $query)
            ->join($table, "{$table}.id", '=', "expenses.{$foreignKey}")
            ->select("{$table}.{$labelColumn} as label", DB::raw('SUM(expenses.amount) as total'))
            ->groupBy("{$table}.id", "{$table}.{$labelColumn}")
            ->orderByDesc('total')->orderBy("{$table}.{$labelColumn}")
            ->first();

        return $row ? ['label' => $row->label, 'total' => (float) $row->total] : null;
    }

    private function sort(Builder $query, array $filters): Builder
    {
        $related = [
            'branch' => [Branch::class, 'name', 'branch_id'],
            'project' => [LeadMaster::class, 'project_name', 'project_id'],
            'category' => [ExpenseCategory::class, 'name', 'expense_category_id'],
            'created_by' => [User::class, 'name', 'created_by'],
        ];
        if (isset($related[$filters['sort']])) {
            [$model, $column, $foreignKey] = $related[$filters['sort']];
            $query->orderBy($model::select($column)->whereColumn('id', "expenses.{$foreignKey}"), $filters['dir']);
        } else {
            $query->orderBy($filters['sort'], $filters['dir']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return $date->format('Y-m-d') === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function activeId(Builder $query, mixed $value): ?int
    {
        return $this->existingId($query->where('is_active', true), $value);
    }

    private function existingId(Builder $query, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id && $query->whereKey($id)->exists() ? (int) $id : -1;
    }
}
