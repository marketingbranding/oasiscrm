<?php

namespace App\Services;

use App\Models\SalesLead;
use App\Models\User;
use App\Support\FeatureFlag;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LeadAttentionService
{
    public function __construct(private readonly AppSettingResolver $settings) {}

    public function queryFor(User $viewer, ?CarbonImmutable $now = null): Builder
    {
        return $this->decorate($this->baseQuery($viewer), $now);
    }

    private function baseQuery(User $viewer): Builder
    {
        return SalesLead::query()->visibleTo($viewer)->activeForLeadViews();
    }

    public function attentionQuery(User $viewer, ?CarbonImmutable $now = null): Builder
    {
        return $this->whereNeedsAttention($this->decorate($this->baseQuery($viewer), $now), $now);
    }

    public function attentionFor(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        return $this->whereNeedsAttention($query, $now);
    }

    public function attentionQueryFor(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        return $this->whereNeedsAttention($this->decorate($query, $now), $now);
    }

    public function whereNeedsAttention(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $threshold = $now->subDays($this->staleDays());
        $closedCondition = $this->closedStageCondition();

        return $query->whereRaw(
            "CASE WHEN {$closedCondition} THEN 0 WHEN {$this->latestActivityExpression()} <= ? THEN 1 ELSE 0 END = 1",
            [$threshold],
        );
    }

    public function staleDays(): int
    {
        return $this->settings->int('sales_monitoring.stale_lead_days');
    }

    public function decorate(Builder $query, ?CarbonImmutable $now = null): Builder
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $threshold = $now->subDays($this->settings->int('sales_monitoring.stale_lead_days'));
        $closedCondition = $this->closedStageCondition();

        return $this->joinActivitySources($query)
            ->addSelect([
                'legacy_stage_activity.legacy_stage_activity_at',
                'lead_stage_activity.lead_stage_activity_at',
                'site_visit_activity.site_visit_activity_at',
                'lead_transfer_activity.lead_transfer_activity_at',
                'lead_comment_activity.lead_comment_activity_at',
            ])
            ->selectRaw($this->latestActivityExpression().' AS latest_activity_at')
            ->selectRaw(
                "CASE WHEN {$closedCondition} THEN 0 WHEN {$this->latestActivityExpression()} <= ? THEN 1 ELSE 0 END AS needs_attention",
                [$threshold],
            )
            ->selectRaw('CASE WHEN open_lead_queue.sales_lead_id IS NULL THEN 0 ELSE 1 END AS has_unowned_queue');
    }

    public function latestActivityAt(SalesLead $lead): ?CarbonImmutable
    {
        $row = $this->activityQuery()->whereKey($lead->getKey())->first();
        if ($row === null) {
            return null;
        }

        $dates = collect([
            $row->lead_date,
            $row->lead_stage_activity_at,
            $row->legacy_stage_activity_at,
            $row->current_status_changed_at,
            $row->site_visit_activity_at,
            $row->lead_transfer_activity_at,
            $row->lead_comment_activity_at,
        ])->filter()->map(fn (mixed $date): CarbonImmutable => CarbonImmutable::parse((string) $date, config('app.timezone')));

        return $dates->sortDesc()->first();
    }

    public function isAttentionNeeded(SalesLead $lead, ?CarbonImmutable $now = null): bool
    {
        if ($this->isClosedV3Stage($lead)) {
            return false;
        }

        $now ??= CarbonImmutable::now(config('app.timezone'));
        $lastActivity = $this->latestActivityAt($lead);

        return $lastActivity?->lte($now->subDays($this->settings->int('sales_monitoring.stale_lead_days'))) ?? true;
    }

    private function activityQuery(): Builder
    {
        return $this->joinActivitySources(SalesLead::query())
            ->addSelect([
                'legacy_stage_activity.legacy_stage_activity_at',
                'lead_stage_activity.lead_stage_activity_at',
                'site_visit_activity.site_visit_activity_at',
                'lead_transfer_activity.lead_transfer_activity_at',
                'lead_comment_activity.lead_comment_activity_at',
            ]);
    }

    private function joinActivitySources(Builder $query): Builder
    {
        $stage = DB::table('sales_lead_stage_histories')
            ->select('sales_lead_id')
            ->selectRaw('MAX(changed_at) AS lead_stage_activity_at')
            ->groupBy('sales_lead_id');
        $legacyStage = DB::table('sales_lead_status_histories')
            ->select('sales_lead_id')
            ->selectRaw('MAX(changed_at) AS legacy_stage_activity_at')
            ->groupBy('sales_lead_id');
        $visits = DB::table('sales_lead_site_visits')
            ->select('sales_lead_id')
            ->selectRaw('MAX(created_at) AS site_visit_activity_at')
            ->groupBy('sales_lead_id');
        $transfers = DB::table('sales_lead_transfers')
            ->select('sales_lead_id')
            ->selectRaw('MAX(created_at) AS lead_transfer_activity_at')
            ->groupBy('sales_lead_id');
        $comments = DB::table('comments')
            ->select('commentable_id AS sales_lead_id')
            ->selectRaw('MAX(comments.created_at) AS lead_comment_activity_at')
            ->where('commentable_type', (new SalesLead)->getMorphClass())
            ->where('source', 'human')
            ->whereNull('deleted_at')
            ->groupBy('commentable_id');
        $queues = DB::table('sales_lead_unowned_queues')
            ->select('sales_lead_id')
            ->where('status', 'open')
            ->groupBy('sales_lead_id');

        return $query
            ->leftJoinSub($stage, 'lead_stage_activity', 'lead_stage_activity.sales_lead_id', '=', 'sales_leads.id')
            ->leftJoinSub($legacyStage, 'legacy_stage_activity', 'legacy_stage_activity.sales_lead_id', '=', 'sales_leads.id')
            ->leftJoinSub($visits, 'site_visit_activity', 'site_visit_activity.sales_lead_id', '=', 'sales_leads.id')
            ->leftJoinSub($transfers, 'lead_transfer_activity', 'lead_transfer_activity.sales_lead_id', '=', 'sales_leads.id')
            ->leftJoinSub($comments, 'lead_comment_activity', 'lead_comment_activity.sales_lead_id', '=', 'sales_leads.id')
            ->leftJoinSub($queues, 'open_lead_queue', 'open_lead_queue.sales_lead_id', '=', 'sales_leads.id')
            ->select('sales_leads.*');
    }

    public function latestActivityExpression(): string
    {
        $dates = [
            'COALESCE(legacy_stage_activity.legacy_stage_activity_at, sales_leads.lead_date)',
            'COALESCE(lead_stage_activity.lead_stage_activity_at, sales_leads.lead_date)',
            'COALESCE(sales_leads.current_status_changed_at, sales_leads.lead_date)',
            'COALESCE(site_visit_activity.site_visit_activity_at, sales_leads.lead_date)',
            'COALESCE(lead_transfer_activity.lead_transfer_activity_at, sales_leads.lead_date)',
            'COALESCE(lead_comment_activity.lead_comment_activity_at, sales_leads.lead_date)',
        ];
        $latest = $dates[0];

        foreach (array_slice($dates, 1) as $date) {
            $latest = "CASE WHEN {$latest} >= {$date} THEN {$latest} ELSE {$date} END";
        }

        return $latest;
    }

    private function closedStageCondition(): string
    {
        $scope = FeatureFlag::branchScope('lead_stages_v3');
        if ($scope === 'none') {
            return '0 = 1';
        }

        $closed = "(sales_leads.lead_stage IN ('utj', 'gugur') OR sales_leads.gugur_at IS NOT NULL OR sales_leads.utj_at IS NOT NULL OR sales_leads.current_status IN ('utj', 'slik_check', 'slik_rejected', 'akad'))";

        return $scope === 'all'
            ? $closed
            : '(sales_leads.branch_id IN ('.implode(',', array_map('intval', $scope)).") AND {$closed})";
    }

    private function isClosedV3Stage(SalesLead $lead): bool
    {
        if (! FeatureFlag::enabledForBranch('lead_stages_v3', (int) $lead->branch_id)) {
            return false;
        }

        return in_array($lead->resolveStageV3(), ['utj', 'gugur'], true);
    }
}
