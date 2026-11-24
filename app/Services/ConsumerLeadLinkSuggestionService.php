<?php

namespace App\Services;

use App\Models\ConsumerApplication;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class ConsumerLeadLinkSuggestionService
{
    private const MIN_NAME_SIMILARITY = 0.5;

    private const MIN_SALES_NAME_SIMILARITY = 0.3;

    private const MAX_CANDIDATES = 200;

    public function __construct(
        private readonly OrganizationScopeService $organizationScope,
        private readonly WorkspaceAccessService $workspaceAccess,
        private readonly PhoneNormalizationService $phones,
    ) {}

    public function suggest(ConsumerApplication $consumer, User $viewer, int $limit = 10): Collection
    {
        if (! $this->isFullyAuthorized($viewer)) {
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

        $candidateQuery = SalesLead::query()
            ->where('branch_id', $consumer->branch_id)
            ->where('project_id', $consumer->project_id)
            ->whereIn('sales_user_id', $salesUserIds)
            ->whereDoesntHave('linkDecisions', function ($query): void {
                $query->where('status', 'linked')->where('is_active', true);
            });

        if ($consumerPhone !== null || $consumerName !== '') {
            $candidateQuery->where(function ($query) use ($consumerPhone, $consumerName): void {
                if ($consumerPhone !== null) {
                    $query->where('normalized_phone', $consumerPhone);
                }

                foreach ($this->nameTokens($consumerName) as $token) {
                    $query->orWhere('customer_name', 'like', '%'.$token.'%');
                }
            });
        }

        return $candidateQuery
            ->with(['sales:id,name', 'branch:id,name', 'project:id,project_name'])
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES)
            ->get()
            ->map(fn (SalesLead $lead): ?array => $this->score(
                $lead,
                $consumerName,
                $consumerPhone,
                $consumerDate,
                $consumer->sales_user_id,
            ))
            ->filter()
            ->sortBy([
                ['score', 'desc'],
                ['lead_id', 'asc'],
            ])
            ->take($limit)
            ->values();
    }

    /** @return array{lead_id: int, lead: SalesLead, score: float, rationale: array{normalized_phone_match: bool, name_similarity: float, same_sales: bool, chronological_plausibility: bool}}|null */
    private function score(SalesLead $lead, string $consumerName, ?string $consumerPhone, mixed $consumerDate, ?int $consumerSalesId): ?array
    {
        $leadName = $this->normalizedName($lead->customer_name);
        $nameSimilarity = $this->nameSimilarity($consumerName, $leadName);
        $leadPhone = $this->phones->normalize($lead->phone)
            ?? $this->phones->normalize($lead->normalized_phone);
        $phoneMatch = $consumerPhone !== null && $leadPhone !== null && $consumerPhone === $leadPhone;
        $sameSales = $consumerSalesId !== null && (int) $lead->sales_user_id === $consumerSalesId;
        $chronological = $consumerDate === null || $lead->lead_date === null || $lead->lead_date->startOfDay()->lte($consumerDate);

        if (! $phoneMatch
            && $nameSimilarity < self::MIN_NAME_SIMILARITY
            && ! ($sameSales && $nameSimilarity >= self::MIN_SALES_NAME_SIMILARITY)) {
            return null;
        }

        return [
            'lead_id' => (int) $lead->id,
            'lead' => $lead,
            'score' => round(($phoneMatch ? 0.6 : 0.0)
                + ($nameSimilarity * 0.3)
                + ($sameSales ? 0.05 : 0.0)
                + ($chronological ? 0.05 : 0.0), 6),
            'rationale' => [
                'normalized_phone_match' => $phoneMatch,
                'name_similarity' => round($nameSimilarity, 6),
                'same_sales' => $sameSales,
                'chronological_plausibility' => $chronological,
            ],
        ];
    }

    /** @return list<string> */
    private function nameTokens(string $name): array
    {
        return array_values(array_filter(
            explode(' ', $name),
            fn (string $token): bool => mb_strlen($token) >= 3,
        ));
    }

    private function nameSimilarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        similar_text($left, $right, $percentage);

        return min(1.0, max(0.0, $percentage / 100));
    }

    private function isFullyAuthorized(User $viewer): bool
    {
        return $viewer->isAccountActive()
            && $viewer->is_active
            && $viewer->hasVerifiedEmail()
            && ! $viewer->must_change_password
            && $viewer->password_changed_at !== null;
    }

    private function normalizedName(?string $name): string
    {
        $ascii = Str::ascii(trim((string) $name));
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', strtolower($ascii)) ?? '';

        return trim($normalized);
    }
}
