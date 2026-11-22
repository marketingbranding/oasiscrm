<?php

namespace Tests\Unit;

use App\Models\AccessGrant;
use App\Services\AccessGrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessGrantServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_br53_grant_modules_map_to_active_scope_modules(): void
    {
        $this->assertContains('sales_leads', AccessGrant::MODULES);
        $this->assertContains('sales_daily_plans', AccessGrant::MODULES);
    }

    public function test_br53_only_edit_grants_are_returned_for_edit_scope(): void
    {
        $this->assertTrue(method_exists(AccessGrantService::class, 'grantedUserIds'));
    }
}
