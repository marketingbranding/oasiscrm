<?php

namespace Tests\Feature;

use App\Http\Requests\Crm\StoreSalesLeadRequest;
use App\Http\Requests\Crm\UpdateSalesLeadRequest;
use Tests\TestCase;

class SalesLeadPhoneValidationTest extends TestCase
{
    public function test_interactive_create_and_edit_require_a_phone_number(): void
    {
        $storeRules = (new StoreSalesLeadRequest)->rules();
        $updateRules = (new UpdateSalesLeadRequest)->rules();

        $this->assertContains('required', $storeRules['phone']);
        $this->assertContains('required', $updateRules['phone']);
    }
}
