<?php

namespace App\Policies;

use App\Models\FeedbackReport;
use App\Models\User;
use App\Services\WorkspaceAccessService;

class FeedbackReportPolicy
{
    public function view(User $user, FeedbackReport $report): bool
    {
        return $report->user_id === $user->id || $this->review($user, $report);
    }

    public function review(User $user, FeedbackReport $report): bool
    {
        if ($user->isSuperadmin()) {
            return true;
        }

        return $user->hasRole('pusat')
            && app(WorkspaceAccessService::class)->canViewBranch($user, (int) $report->branch_id);
    }
}
