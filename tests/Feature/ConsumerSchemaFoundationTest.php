<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerDocument;
use App\Models\ConsumerLegacyIdentity;
use App\Models\ConsumerStageEvent;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\Promo;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConsumerSchemaFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_supports_multiple_applications_and_related_records(): void
    {
        [$branch, $project, $sales, $kavling, $promo] = $this->canonicalRecords();
        $customer = Customer::factory()->create();
        $lead = SalesLead::create([
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'lead_date' => today(),
            'customer_name' => $customer->name,
            'current_status' => 'no_response',
        ]);

        $first = ConsumerApplication::factory()->create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
            'kavling_id' => $kavling->id,
            'promo_id' => $promo->id,
            'sales_lead_id' => $lead->id,
        ]);
        $second = ConsumerApplication::factory()->create([
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'sales_user_id' => $sales->id,
        ]);

        $event = ConsumerStageEvent::factory()->create(['consumer_application_id' => $first->id]);
        $document = ConsumerDocument::factory()->create(['consumer_application_id' => $first->id]);
        $bankProcess = ConsumerBankProcess::factory()->create(['consumer_application_id' => $first->id]);
        $identity = ConsumerLegacyIdentity::factory()->create([
            'customer_id' => $customer->id,
            'consumer_application_id' => $first->id,
        ]);

        $this->assertCount(2, $customer->applications);
        $this->assertTrue($first->customer->is($customer));
        $this->assertTrue($first->branch->is($branch));
        $this->assertTrue($first->project->is($project));
        $this->assertTrue($first->sales->is($sales));
        $this->assertTrue($first->kavling->is($kavling));
        $this->assertTrue($first->promo->is($promo));
        $this->assertTrue($first->salesLead->is($lead));
        $this->assertTrue($first->stageEvents->contains($event));
        $this->assertTrue($first->documents->contains($document));
        $this->assertTrue($first->bankProcesses->contains($bankProcess));
        $this->assertTrue($first->legacyIdentities->contains($identity));
        $this->assertTrue($second->customer->is($customer));
    }

    public function test_stable_legacy_identity_is_unique_per_source_key(): void
    {
        $customer = Customer::factory()->create();
        $attributes = [
            'customer_id' => $customer->id,
            'legacy_source' => 'google_progress',
            'spreadsheet_id' => 'spreadsheet-1',
            'sheet_name' => 'data_konsumen',
            'external_key' => 'consumer-1',
        ];

        ConsumerLegacyIdentity::factory()->create($attributes);

        $this->expectException(QueryException::class);
        ConsumerLegacyIdentity::factory()->create($attributes);
    }

    public function test_phase_one_schema_has_no_plaintext_sensitive_identity_columns(): void
    {
        $this->assertTrue(Schema::hasTable('customers'));
        $this->assertFalse(Schema::hasColumn('customers', 'nik'));
        $this->assertFalse(Schema::hasColumn('customers', 'kk'));
        $this->assertFalse(Schema::hasColumn('customers', 'salary'));
        $this->assertFalse(Schema::hasColumn('customers', 'income'));
        $this->assertFalse(Schema::hasColumn('consumer_documents', 'content'));
    }

    private function canonicalRecords(): array
    {
        $branch = Branch::create(['name' => 'Jepara', 'code' => 'JPR', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Jepara', 'is_active' => true]);
        $role = Role::firstOrCreate(['slug' => 'sales'], ['name' => 'Sales', 'is_superadmin' => false]);
        $sales = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);
        $kavling = Kavling::create(['project_id' => $project->id, 'kavling_code' => 'A-01', 'name' => 'A-01']);
        $promo = Promo::create(['branch_id' => $branch->id, 'code' => 'P1', 'name' => 'Promo 1', 'is_active' => true]);

        return [$branch, $project, $sales, $kavling, $promo];
    }
}
