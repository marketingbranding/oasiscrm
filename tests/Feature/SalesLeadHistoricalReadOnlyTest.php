<?php

namespace Tests\Feature;

use App\Models\SalesLead;
use App\Models\User;
use App\Policies\SalesLeadPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesLeadHistoricalReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_br54_branch_manager_and_admin_cabang_cannot_edit_historical_owned_lead(): void
    {
        $policy = app(SalesLeadPolicy::class);
        $lead = new SalesLead(['branch_id' => 1, 'sales_user_id' => 2]);
        $branchManager = new User;
        $adminCabang = new User;

        $this->assertFalse($policy->canEditHistoricalOwnedLead($branchManager, $lead));
        $this->assertFalse($policy->canEditHistoricalOwnedLead($adminCabang, $lead));
    }
}
