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
            && Gate::forUser($user)->allows('updateSalesAgenda', $agenda);
    }

    public function canView(User $user, ContentItem $agenda): bool
    {
        if (! SalesAgendaEvidenceRules::isSalesAgenda($agenda)) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $agenda);
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
