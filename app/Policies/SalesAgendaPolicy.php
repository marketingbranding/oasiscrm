<?php

namespace App\Policies;

use App\Models\ContentItem;
use App\Models\User;
use App\Services\CrmAuthorizationService;

class SalesAgendaPolicy
{
    public function __construct(private readonly CrmAuthorizationService $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->hasScope($user, 'sales_pocketbook', 'view', 'own')
            || $this->authorization->hasScope($user, 'sales_pocketbook', 'view', 'team')
            || $this->authorization->hasScope($user, 'sales_pocketbook', 'view', 'branch')
            || $this->authorization->hasScope($user, 'sales_pocketbook', 'view', 'all');
    }

    public function view(User $user, ContentItem $agenda): bool
    {
        return $this->isSalesAgenda($agenda) && $this->authorization->canViewSalesAgenda($user, $agenda);
    }

    public function create(User $user): bool
    {
        return $this->authorization->canCreateSalesAgenda($user);
    }

    public function update(User $user, ContentItem $agenda): bool
    {
        return $this->isSalesAgenda($agenda) && $this->authorization->canUpdateSalesAgenda($user, $agenda);
    }

    private function isSalesAgenda(ContentItem $item): bool
    {
        return $item->item_type === 'agenda' && $item->agenda_type === ContentItem::SALES_AGENDA_TYPE;
    }
}
