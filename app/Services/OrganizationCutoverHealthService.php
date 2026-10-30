<?php

namespace App\Services;

use App\Models\OrganizationAssignment;
use App\Models\User;
use App\Support\OrganizationParityFilter;
use Illuminate\Support\Collection;

final class OrganizationCutoverHealthService
{
    public function __construct(
        private readonly OrganizationParityService $parity,
        private readonly OrganizationBackfillService $backfill,
        private readonly OrganizationGraphModeService $mode,
        private readonly OrganizationGraphShadowService $shadow,
    ) {}

    /** @param array<int>|null $userIds @return array<string, mixed> */
    public function check(?array $userIds = null, bool $controlledEvidence = false): array
    {
        $selectedUserIds = $this->normalizeUserIds($userIds);
        $missingUsers = $this->missingUserCount($selectedUserIds);
        $parityReports = $this->parityReports($selectedUserIds);
        $parity = $this->aggregateParity($parityReports);
        $backfill = $selectedUserIds === []
            ? $this->backfill->run(true)
            : $this->backfill->runControlled($selectedUserIds, false, true);
        $shadowCounters = $this->shadow->snapshot();
        $evidenceMarker = (bool) config('organization.controlled_evidence_marker', false);
        $evidenceComplete = $controlledEvidence
            && $selectedUserIds !== []
            && $evidenceMarker
            && ($backfill['safety']['allowed'] ?? false)
            && ($backfill['assignments_proposed'] ?? 0) === 0
            && ($backfill['conflicts'] ?? 0) === 0
            && $parity['mismatches'] === 0
            && $shadowCounters !== [];
        $edgeIntegrity = $this->edgeIntegrity($selectedUserIds);
        $integrity = $this->integrity($parity, $backfill, $missingUsers, $edgeIntegrity);
        $writerEvidenceAvailable = $controlledEvidence && $evidenceMarker && $selectedUserIds !== [] && $shadowCounters !== [];
        $writers = [
            'evidence_available' => $writerEvidenceAvailable,
            'compatibility_writer_sync_failures' => 0,
            'transaction_failures' => 0,
        ];
        $mode = $this->modeDiagnostics($selectedUserIds);
        $shadow = [
            'evidence_available' => $shadowCounters !== [],
            'counters' => $shadowCounters,
        ];
        $blockers = $this->blockers($parity, $backfill, $integrity, $writers, $mode, $shadow, $evidenceComplete);

        return [
            'status' => $blockers === [] ? 'READY' : 'BLOCKED',
            'reasons' => $this->reasons($blockers, $parity, $backfill, $integrity),
            'blockers' => $blockers,
            'parity' => $parity,
            'backfill' => $this->backfillSummary($backfill),
            'integrity' => $integrity,
            'writers' => $writers,
            'mode' => [
                ...$mode,
                'production_read_source' => 'legacy',
                'production_cutover_performed' => false,
            ],
            'shadow' => $shadow,
        ];
    }

    /** @param array<string, mixed> $health */
    public function isReady(array $health): bool
    {
        return ($health['status'] ?? null) === 'READY' && ($health['blockers'] ?? []) === [];
    }

    /** @param array<int>|null $userIds @return array<int> */
    private function normalizeUserIds(?array $userIds): array
    {
        return collect($userIds ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param array<int> $userIds @return Collection<int, array<string, mixed>> */
    private function parityReports(array $userIds): Collection
    {
        if ($userIds === []) {
            return collect([$this->parity->report(new OrganizationParityFilter)]);
        }

        return collect($userIds)->map(fn (int $userId): array => $this->parity->report(
            new OrganizationParityFilter(userId: $userId),
        ));
    }

    /** @param Collection<int, array<string, mixed>> $reports @return array<string, int> */
    private function aggregateParity(Collection $reports): array
    {
        $summary = $reports->pluck('summary');

        return [
            'comparisons' => (int) $summary->sum('comparisons'),
            'mismatches' => (int) $summary->sum('differences'),
            'unknown' => (int) $summary->sum('unknown'),
            'access_expansion' => (int) $summary->sum('access_expansion'),
            'access_loss' => (int) $summary->sum('access_loss'),
            'critical' => (int) $summary->sum('critical'),
            'conflicts' => (int) $summary->sum('conflicts'),
            'cross_branch_expansion' => (int) $reports->sum(fn (array $report): int => (int) ($report['gate']['failures']['cross_branch_expansion'] ?? 0)),
            'cross_project_expansion' => (int) $reports->sum(fn (array $report): int => (int) ($report['gate']['failures']['cross_project_expansion'] ?? 0)),
            'reporting_conflicts' => (int) $reports->sum(fn (array $report): int => (int) ($report['gate']['failures']['unresolved_reporting_conflict'] ?? 0)),
            'sales_team_conflicts' => (int) $reports->sum(fn (array $report): int => (int) ($report['gate']['failures']['unresolved_sales_coordinator_conflict'] ?? 0)),
            'cycles' => (int) $reports->sum(fn (array $report): int => (int) ($report['gate']['failures']['cycle_safety_failure'] ?? 0)),
        ];
    }

    /** @return array<string, int> */
    private function integrity(array $parity, array $backfill, int $missingUsers, array $edgeIntegrity): array
    {
        return [
            'missing_users' => $missingUsers,
            'unknown_edges' => $parity['unknown'],
            'ambiguous_edges' => max((int) ($backfill['multiple_active_coordinator_assignments'] ?? 0), $edgeIntegrity['ambiguous_edges']),
            'stale_edges' => $edgeIntegrity['stale_edges'],
            'invalid_edges' => (int) ($backfill['invalid_role_relationships'] ?? 0),
            'cycle_failures' => $parity['cycles'] + (int) ($backfill['cycles'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function modeDiagnostics(array $userIds): array
    {
        $viewer = $userIds === [] ? User::query()->orderBy('id')->first() : User::query()->find($userIds[0]);
        if (! $viewer instanceof User) {
            return [
                'configured_mode' => $this->mode->configuredMode(),
                'effective_mode' => 'legacy',
                'reason' => 'no_viewer_context',
            ];
        }

        return $this->mode->activationDiagnostics($viewer, $viewer->branch_id);
    }

    /** @return array<string, mixed> */
    private function backfillSummary(array $backfill): array
    {
        return [
            'safety_allowed' => (bool) ($backfill['safety']['allowed'] ?? true),
            'assignments_proposed' => (int) ($backfill['assignments_proposed'] ?? 0),
            'created' => (int) ($backfill['created'] ?? 0),
            'already_migrated' => (int) ($backfill['already_migrated'] ?? 0),
            'conflicts' => (int) ($backfill['conflicts'] ?? 0),
            'missing_users' => (int) ($backfill['missing_users'] ?? 0),
            'cycles' => (int) ($backfill['cycles'] ?? 0),
        ];
    }

    /** @return array<int, string> */
    private function blockers(array $parity, array $backfill, array $integrity, array $writers, array $mode, array $shadow, bool $evidenceComplete): array
    {
        $blockers = [];
        $conditions = [
            'parity_unknown' => $parity['unknown'] > 0,
            'parity_access_expansion' => $parity['access_expansion'] > 0,
            'parity_access_loss' => $parity['access_loss'] > 0,
            'parity_critical' => $parity['critical'] > 0,
            'parity_cross_branch_expansion' => $parity['cross_branch_expansion'] > 0,
            'parity_cross_project_expansion' => $parity['cross_project_expansion'] > 0,
            'parity_reporting_conflict' => $parity['reporting_conflicts'] > 0,
            'parity_sales_team_conflict' => $parity['sales_team_conflicts'] > 0,
            'backfill_proposals' => ($backfill['assignments_proposed'] ?? 0) > 0,
            'backfill_conflicts' => ($backfill['conflicts'] ?? 0) > 0,
            'integrity_unknown_edges' => $integrity['unknown_edges'] > 0,
            'integrity_ambiguous_edges' => $integrity['ambiguous_edges'] > 0,
            'integrity_stale_edges' => $integrity['stale_edges'] > 0,
            'integrity_invalid_edges' => $integrity['invalid_edges'] > 0,
            'integrity_cycle_failures' => $integrity['cycle_failures'] > 0,
            'invalid_user_selection' => $integrity['missing_users'] > 0,
            'writer_sync_failure' => $writers['compatibility_writer_sync_failures'] > 0,
            'writer_evidence_incomplete' => ! $writers['evidence_available'],
            'transaction_evidence_incomplete' => ! $writers['evidence_available'],
            'shadow_mismatch' => collect($shadow['counters'] ?? [])->filter(fn (int $count, string $key): bool => str_ends_with($key, '.mismatch') && $count > 0)->isNotEmpty(),
            'controlled_evidence_incomplete' => ! $evidenceComplete,
            'mode_invalid' => ($mode['mode_valid'] ?? true) === false,
        ];

        foreach ($conditions as $code => $blocked) {
            if ($blocked) {
                $blockers[] = $code;
            }
        }

        return $blockers;
    }

    /** @return array<int, array<string, int|string>> */
    private function reasons(array $blockers, array $parity, array $backfill, array $integrity): array
    {
        return collect($blockers)->map(fn (string $code): array => [
            'code' => $code,
            'count' => match (true) {
                str_starts_with($code, 'parity_') => $this->parityReasonCount($code, $parity),
                $code === 'backfill_proposals' => (int) ($backfill['assignments_proposed'] ?? 0),
                $code === 'backfill_conflicts' => (int) ($backfill['conflicts'] ?? 0),
                str_starts_with($code, 'integrity_') => (int) ($integrity[substr($code, 10)] ?? 1),
                default => 1,
            },
        ])->values()->all();
    }

    private function parityReasonCount(string $code, array $parity): int
    {
        return match ($code) {
            'parity_unknown' => $parity['unknown'],
            'parity_access_expansion' => $parity['access_expansion'],
            'parity_access_loss' => $parity['access_loss'],
            'parity_critical' => $parity['critical'],
            'parity_cross_branch_expansion' => $parity['cross_branch_expansion'],
            'parity_cross_project_expansion' => $parity['cross_project_expansion'],
            'parity_reporting_conflict' => $parity['reporting_conflicts'],
            'parity_sales_team_conflict' => $parity['sales_team_conflicts'],
            default => 1,
        };
    }

    /** @param array<int> $userIds */
    private function missingUserCount(array $userIds): int
    {
        if ($userIds === []) {
            return 0;
        }

        return count($userIds) - User::query()->whereIn('id', $userIds)->count();
    }

    /** @param array<int> $userIds @return array{ambiguous_edges: int, stale_edges: int} */
    private function edgeIntegrity(array $userIds): array
    {
        $reporting = OrganizationAssignment::query()
            ->current()
            ->with(['user', 'parent'])
            ->get();
        $salesTeam = OrganizationAssignment::query()
            ->currentSalesTeam()
            ->with(['user', 'parent'])
            ->get();
        $all = $reporting->concat($salesTeam);
        $ambiguous = $all->groupBy(fn (OrganizationAssignment $assignment): string => $assignment->relationship_type.':'.$assignment->user_id)
            ->filter(fn (Collection $assignments): bool => $assignments->count() > 1)
            ->count();
        $stale = $all->filter(function (OrganizationAssignment $assignment): bool {
            $user = $assignment->user;
            $parent = $assignment->parent;

            return ! $user instanceof User
                || ! $parent instanceof User
                || ! $user->isAccountActive()
                || ! $parent->isAccountActive()
                || ! $user->is_active
                || ! $parent->is_active
                || ($assignment->branch_id !== null && (int) $assignment->branch_id !== (int) $user->branch_id)
                || ($user->branch_id !== null && $parent->branch_id !== null && (int) $user->branch_id !== (int) $parent->branch_id);
        })->count();

        return ['ambiguous_edges' => $ambiguous, 'stale_edges' => $stale];
    }
}
