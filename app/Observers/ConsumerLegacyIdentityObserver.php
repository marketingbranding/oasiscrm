<?php

namespace App\Observers;

use App\Models\ConsumerLegacyIdentity;
use App\Services\ConsumerLeadLinkCarryOverService;

final class ConsumerLegacyIdentityObserver
{
    public function created(ConsumerLegacyIdentity $identity): void
    {
        $this->carry($identity);
    }

    public function updated(ConsumerLegacyIdentity $identity): void
    {
        if ($identity->wasChanged('id_kons')) {
            $this->carry($identity);
        }
    }

    private function carry(ConsumerLegacyIdentity $identity): void
    {
        if (trim((string) $identity->id_kons) === '' || $identity->consumer_application_id === null) {
            return;
        }

        $application = $identity->application()->withoutGlobalScopes()->first();
        if ($application !== null) {
            app(ConsumerLeadLinkCarryOverService::class)->carryApplication($application);
        }
    }
}
