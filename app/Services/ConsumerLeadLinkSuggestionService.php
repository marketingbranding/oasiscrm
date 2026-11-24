<?php

namespace App\Services;

use App\Models\ConsumerApplication;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ConsumerLeadLinkSuggestionService
{
    public function __construct(
        private readonly OrganizationScopeService $organizationScope,
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly PhoneNormalizationService $phones,
    ) {}

    public function suggest(ConsumerApplication $consumer, User $viewer, int $limit = 10): Collection
    {
        if (! $viewer->isAccountActive() || ! $viewer->is_active) {
            return collect();
        }

        $limit = max(0, $limit);
        if ($limit === 0) {
            return collect();
        }

        $branchIds = array_values(array_intersect(
            $this->organizationScope->branchIds($viewer, 'consumer_progress', 'view'),
            $this->organizationScope->branchIds($viewer, 'sales_pocketbook', 'view'),
            $this->workspaceAccess->accessibleBranchIds($viewer),
        ));
        $projectIds = array_values(array_intersect(
            $this->organizationScope->projectIds($viewer, 'consumer_progress', 'view'),
            $this->organizationScope->projectIds($viewer, 'sales_pocketbook', 'view'),
            $this->workspaceAccess->accessibleProjectIds($viewer),
        ));

        if (! in_array((int) $consumer->branch_id, $branchIds, true)
            || ($consumer->project_id !== null && ! in_array((int) $consumer->project_id, $projectIds, true))) {
            return collect();
        }

        $salesUserIds = $this->organizationScope->visibleUserIds($viewer, 'sales_pocketbook', 'view', 'sales_leads');
        if ($branchIds === [] || $projectIds === [] || $salesUserIds === []) {
            return collect();
        }

        $consumerName = $this->normalizedName($consumer->customer?->name ?: $consumer->nama_konsumen);
        $consumerPhone = $this->phones->normalize($consumer->customer?->phone);
        $consumerDate = $consumer->booking_date?->startOfDay()
            ?? $consumer->historical_entered_at?->startOfDay()
            ?? $consumer->created_at?->startOfDay();

        return SalesLead::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('project_id', $projectIds)
            ->whereIn('sales_user_id', $salesUserIds)
            ->with(['sales:id,name', 'branch:id,name', 'project:id,project_name'])
            ->get()
            ->map(fn (SalesLead $lead): ?array => $this->score(
                $lead,
                $consumerName,
                $consumerPhone,
                $consumerDate,
                $consumer->project_id,
                $consumer->sales_user_id,
            ))
            ->filter()
            ->sortByDesc(fn (array $suggestion): float => $suggestion['score'])
            ->take($limit)
            ->values();
    }

    /** @return array{lead_id: int, lead: SalesLead, score: float, rationale: array{normalized_phone_match: bool, name_similarity: float, same_project: bool, same_sales: bool, chronological_plausibility: bool}}|null */
    private function score(SalesLead $lead, string $consumerName, ?string $consumerPhone, mixed $consumerDate, ?int $consumerProjectId, ?int $consumerSalesId): ?array
    {
        $leadName = $this->normalizedName($lead->customer_name);
        $nameSimilarity = $this->nameSimilarity($consumerName, $leadName);
        $leadPhone = $this->phones->normalize($lead->phone) ?? $lead->normalized_phone;
        $phoneMatch = $consumerPhone !== null && $leadPhone !== null && $consumerPhone === $leadPhone;
        $sameProject = $consumerProjectId !== null && (int) $lead->project_id === $consumerProjectId;
        $sameSales = $consumerSalesId !== null && (int) $lead->sales_user_id === $consumerSalesId;
        $chronological = $consumerDate === null || $lead->lead_date === null || $lead->lead_date->startOfDay()->lte($consumerDate);

        if (! $phoneMatch && $nameSimilarity === 0.0 && ! $sameProject && ! $sameSales) {
            return null;
        }

        return [
            'lead_id' => (int) $lead->id,
            'lead' => $lead,
            'score' => round(($phoneMatch ? 0.5 : 0.0)
                + ($nameSimilarity * 0.25)
                + ($sameProject ? 0.1 : 0.0)
                + ($sameSales ? 0.1 : 0.0)
                + ($chronological ? 0.05 : 0.0), 6),
            'rationale' => [
                'normalized_phone_match' => $phoneMatch,
                'name_similarity' => round($nameSimilarity, 6),
                'same_project' => $sameProject,
                'same_sales' => $sameSales,
                'chronological_plausibility' => $chronological,
            ],
        ];
    }

    private function nameSimilarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        similar_text($left, $right, $percentage);

        return min(1.0, max(0.0, $percentage / 100));
    }

    private function normalizedName(?string $name): string
    {
        $ascii = Str::ascii(trim((string) $name));
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', strtolower($ascii)) ?? '';

        return trim($normalized);
    }
}
