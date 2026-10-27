<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\SalesAgendaEvidenceArchive;
use App\Models\User;
use App\Support\SalesAgendaEvidenceRules;
use Illuminate\Support\Facades\Gate;

class SalesAgendaEvidenceAuthorizationService
{
    public function __construct(
        private readonly WorkspaceAccessService $workspaceAccess,
    ) {}

    public function canMutate(User $user, ContentItem $agenda): bool
    {
        return SalesAgendaEvidenceRules::isSalesAgenda($agenda) && SalesAgendaEvidenceRules::isMutable($agenda)
            && $user->hasPermission('sales_pocketbook.manage_own')
            && $user->hasPrimaryRole('sales')
            && (int) $agenda->owner_user_id === (int) $user->id
            && Gate::forUser($user)->allows('updateSalesAgenda', $agenda);
    }

    public function canView(User $user, ContentItem $agenda): bool
    {
        if (! SalesAgendaEvidenceRules::isSalesAgenda($agenda)) {
            return false;
        }
        if (! $user->hasAnyPermission([
            'sales_pocketbook.view_own',
            'sales_pocketbook.view_team',
            'sales_pocketbook.view_assigned',
            'sales_pocketbook.view_branch',
            'sales_pocketbook.view_all',
        ])) {
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

        return Gate::forUser($user)->allows('view', $agenda);
    }

    public function canManageArchives(User $user): bool
    {
        return ($user->hasPrimaryRole('admin') || $user->hasPrimaryRole('superadmin'))
            && $user->hasAnyPermission(['sales_pocketbook.manage_branch', 'sales_pocketbook.manage_all']);
    }

    public function canBuildArchive(User $user, int $branchId): bool
    {
        return ($user->hasPrimaryRole('superadmin') && $user->hasPermission('sales_pocketbook.manage_all'))
            || ($user->hasPrimaryRole('admin') && $user->hasPermission('sales_pocketbook.manage_branch')
                && (int) $user->branch_id === $branchId && $this->workspaceAccess->canViewBranch($user, $branchId));
    }

    public function canDownloadArchive(User $user, SalesAgendaEvidenceArchive $archive): bool
    {
        return ($user->hasPrimaryRole('superadmin') && $user->hasPermission('sales_pocketbook.view_all'))
            || ($user->hasPrimaryRole('admin') && $user->hasPermission('sales_pocketbook.view_branch')
                && (int) $user->branch_id === (int) $archive->branch_id
                && $this->workspaceAccess->canViewBranch($user, (int) $archive->branch_id));
    }

    public function canPurge(User $user): bool
    {
        return $user->hasPrimaryRole('superadmin') && $user->hasPermission('sales_pocketbook.manage_all');
    }
}
