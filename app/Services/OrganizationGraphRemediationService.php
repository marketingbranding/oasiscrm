<?php

namespace App\Services;

use App\Exceptions\OrganizationAssignmentConflictException;
use App\Models\OrganizationAssignment;
use App\Models\SalesCoordinatorSales;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrganizationGraphRemediationService
{
    public function __construct(private readonly OrganizationGraphService $graph) {}

    /**
     * @param array{
     *     user_id:int,
     *     parent_user_id:int,
     *     classification:string,
     *     confidence:string,
     *     expected_legacy_parent_user_id:int|null,
     *     expected_graph_parent_user_id:int|null,
     *     reason:string,
     *     relationship_type?:string
     * } $approval
     */
    public function applyApproved(array $approval): ?OrganizationAssignment
    {
        $approval['relationship_type'] ??= OrganizationAssignment::REPORTS_TO;
        $this->validateApproval($approval);

        return DB::transaction(function () use ($approval): ?OrganizationAssignment {
            $users = User::query()
                ->with('role')
                ->whereIn('id', [$approval['user_id'], $approval['parent_user_id']])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $user = $users->get($approval['user_id']);
            $parent = $users->get($approval['parent_user_id']);

            if (! $user instanceof User || ! $parent instanceof User) {
                throw ValidationException::withMessages(['organization' => 'Target atau parent organisasi tidak ditemukan.']);
            }

            $relationshipType = $approval['relationship_type'];
            $currentAssignments = OrganizationAssignment::query()
                ->where('user_id', $user->id)
                ->when($relationshipType === OrganizationAssignment::SALES_TEAM_MEMBER_OF, fn ($query) => $query->currentSalesTeam(), fn ($query) => $query->current())
                ->lockForUpdate()
                ->get();
            if ($currentAssignments->count() > 1) {
                throw ValidationException::withMessages(['organization' => 'Terdapat lebih dari satu assignment aktif untuk tipe relasi ini.']);
            }

            $current = $currentAssignments->first();
            $legacyParentId = $relationshipType === OrganizationAssignment::SALES_TEAM_MEMBER_OF
                ? SalesCoordinatorSales::query()->current()->where('sales_user_id', $user->id)->value('coordinator_user_id')
                : $user->supervisor_user_id;
            if ($legacyParentId !== $approval['expected_legacy_parent_user_id']
                || $current?->parent_user_id !== $approval['expected_graph_parent_user_id']) {
                throw new OrganizationAssignmentConflictException($current);
            }

            if ($current !== null && (int) $current->parent_user_id === $parent->id) {
                return $current;
            }

            if ($relationshipType === OrganizationAssignment::SALES_TEAM_MEMBER_OF
                && SalesCoordinatorSales::query()->current()->where('sales_user_id', $user->id)->where('coordinator_user_id', $parent->id)->doesntExist()) {
                throw ValidationException::withMessages(['organization' => 'Mapping Sales Coordinator legacy tidak sesuai dengan approval.']);
            }

            return $relationshipType === OrganizationAssignment::SALES_TEAM_MEMBER_OF
                ? $this->graph->assignSalesTeamMember($user, $parent, source: 'phase_2_6_remediation', reason: $approval['reason'])
                : $this->graph->assign($user, $parent, source: 'phase_2_5_remediation', reason: $approval['reason']);
        }, attempts: 3);
    }

    /** @param array<string, mixed> $approval */
    private function validateApproval(array $approval): void
    {
        foreach (['user_id', 'parent_user_id', 'classification', 'confidence', 'expected_legacy_parent_user_id', 'expected_graph_parent_user_id', 'reason'] as $key) {
            if (! array_key_exists($key, $approval)) {
                throw ValidationException::withMessages([$key => 'Field approval wajib diisi.']);
            }
        }

        foreach (['user_id', 'parent_user_id', 'expected_legacy_parent_user_id', 'expected_graph_parent_user_id'] as $key) {
            if ($approval[$key] !== null && (! is_int($approval[$key]) || $approval[$key] < 1)) {
                throw ValidationException::withMessages([$key => 'Nilai harus berupa ID positif atau null.']);
            }
        }

        if (($approval['classification'] ?? null) !== 'MISSING_GRAPH_ASSIGNMENT') {
            throw ValidationException::withMessages(['classification' => 'Hanya missing graph assignment yang dapat diremediasi.']);
        }
        if (($approval['confidence'] ?? null) !== 'HIGH') {
            throw ValidationException::withMessages(['confidence' => 'Remediasi memerlukan confidence HIGH.']);
        }
        if (! in_array($approval['relationship_type'] ?? null, [OrganizationAssignment::REPORTS_TO, OrganizationAssignment::SALES_TEAM_MEMBER_OF], true)) {
            throw ValidationException::withMessages(['relationship_type' => 'Tipe relasi remediasi tidak didukung.']);
        }
        if (! is_string($approval['reason'] ?? null) || trim($approval['reason']) === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan remediasi wajib diisi.']);
        }
    }
}
