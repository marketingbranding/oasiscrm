<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\SalesAgendaEvidenceArchive;
use App\Models\User;
use App\Support\SalesAgendaEvidenceRules;

class SalesAgendaEvidenceAuthorizationService
{
    public function __construct(
        private readonly CommentableAccessService $agendaAccess,
        private readonly CoordinatorSalesMonitoringService $coordinatorMonitoring,
        private readonly WorkspaceAccessService $workspaceAccess,
    ) {}

    public function canMutate(User $user, ContentItem $agenda): bool
    {
        return SalesAgendaEvidenceRules::isSalesAgenda($agenda) && SalesAgendaEvidenceRules::isMutable($agenda)
            && $user->hasPrimaryRole('sales') && (int) $agenda->owner_user_id === (int) $user->id;
    }

    public function canView(User $user, ContentItem $agenda): bool
    {
        if (! SalesAgendaEvidenceRules::isSalesAgenda($agenda)) {
            return false;
        }
        if ($user->hasPrimaryRole('sales')) {
            return (int) $agenda->owner_user_id === (int) $user->id;
        }
        if ($user->hasPrimaryRole('superadmin')) {
            return true;
        }
        if ($user->hasPrimaryRole('admin')) {
            return (int) $agenda->branch_id === (int) $user->branch_id
                && $this->workspaceAccess->canViewBranch($user, (int) $agenda->branch_id);
        }

        if ($user->hasPrimaryRole('sales_coordinator')) {
            return $this->coordinatorMonitoring->canViewAgenda($user, $agenda);
        }

        return $user->hasPrimaryRole('supervisor')
            && $this->agendaAccess->canView($user, $agenda);
    }

    public function canManageArchives(User $user): bool
    {
        return $user->hasPrimaryRole('admin') || $user->hasPrimaryRole('superadmin');
    }

    public function canBuildArchive(User $user, int $branchId): bool
    {
        return $user->hasPrimaryRole('superadmin') || ($user->hasPrimaryRole('admin')
            && (int) $user->branch_id === $branchId && $this->workspaceAccess->canViewBranch($user, $branchId));
    }

    public function canDownloadArchive(User $user, SalesAgendaEvidenceArchive $archive): bool
    {
        return $user->hasPrimaryRole('superadmin') || ($user->hasPrimaryRole('admin')
            && (int) $user->branch_id === (int) $archive->branch_id
            && $this->workspaceAccess->canViewBranch($user, (int) $archive->branch_id));
    }

    public function canPurge(User $user): bool
    {
        return $user->hasPrimaryRole('superadmin');
    }
}
