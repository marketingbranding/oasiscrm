<?php

namespace App\Observers;

use App\Models\ConsumerApplication;
use App\Services\ConsumerLeadLinkCarryOverService;

final class ConsumerApplicationObserver
{
    public function created(ConsumerApplication $application): void
    {
        app(ConsumerLeadLinkCarryOverService::class)->carryApplication($application);
    }
}
