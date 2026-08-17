<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerLegacyIdentity;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\KonsumenProgressSheetRow;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsumerIdentityBridgeAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsumerIdentityBridgeAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_safe_unique_phone_kavling_candidate_without_writes(): void
    {
        [$branch, $project] = $this->context();
        $this->legacy($branch, ['id_kavling' => 'K-01', 'nama_konsumen' => 'Budi', 'no_hp' => '+62812345678']);
        $customer = Customer::create(['name' => 'Budi', 'phone' => '0812345678']);
        ConsumerApplication::create(['customer_id' => $customer->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'kavling_id' => Kavling::where('project_id', $project->id)->value('id'), 'application_status' => 'draft']);
        $before = ConsumerLegacyIdentity::count();

        $audit = app(ConsumerIdentityBridgeAuditService::class)->audit($branch, $project);

        $this->assertSame(1, $audit['candidates']['UNIQUE_PHONE_KAVLING']);
        $this->assertSame(0, $audit['candidates']['AMBIGUOUS']);
        $this->assertSame($before, ConsumerLegacyIdentity::count());
        $this->assertSame('0812****5678', $audit['candidates']['rows'][0]['phone']);
    }

    public function test_duplicate_phone_kavling_and_reused_kavling_are_not_auto_matched(): void
    {
        [$branch, $project] = $this->context();
        $this->legacy($branch, ['id_kavling' => 'K-01', 'nama_konsumen' => 'Budi', 'no_hp' => '0812345678']);
        $this->legacy($branch, ['id_kavling' => 'K-01', 'nama_konsumen' => 'Sari', 'no_hp' => '0812345678']);
        foreach (['Budi', 'Sari'] as $name) {
            $customer = Customer::create(['name' => $name, 'phone' => '0812345678']);
            ConsumerApplication::create(['customer_id' => $customer->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'kavling_id' => Kavling::where('project_id', $project->id)->value('id'), 'application_status' => 'draft']);
        }

        $audit = app(ConsumerIdentityBridgeAuditService::class)->audit($branch, $project);

        $this->assertSame(2, $audit['candidates']['AMBIGUOUS']);
        $this->assertSame(1, $audit['legacy']['duplicates']['phone_kavling']);
    }

    public function test_shared_external_id_and_missing_phone_and_nik_privacy(): void
    {
        [$branch, $project] = $this->context();
        $this->legacy($branch, ['id_kavling' => 'K-01', 'nama_konsumen' => 'Budi', 'external_id' => 'EXT-1', 'nik' => '3308106504650001']);
        $customer = Customer::create(['name' => 'Budi', 'phone' => null, 'nik_encrypted' => '3308106504650001']);
        $application = ConsumerApplication::create(['customer_id' => $customer->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'application_status' => 'draft']);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'customer_id' => $customer->id, 'legacy_source' => 'manual_spreadsheet_paste', 'external_key' => 'external:EXT-1']);

        $audit = app(ConsumerIdentityBridgeAuditService::class)->audit($branch, $project);
        $json = json_encode($audit, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $audit['candidates']['SHARED_EXTERNAL_ID']);
        $this->assertSame(0, $audit['candidates']['NIK_FINGERPRINT_CANDIDATE']);
        $this->assertSame(1, $audit['legacy']['counts']['nik']);
        $this->assertSame(1, $audit['local']['with_nik']);
        $this->assertStringNotContainsString('3308106504650001', $json);
    }

    public function test_audit_is_superadmin_only_impersonation_blocked_and_isolated(): void
    {
        [$branch, $project] = $this->context();
        foreach (['admin', 'sales'] as $slug) {
            $this->actingAs($this->user($slug))->get(route('consumer-comparison.index', ['branch_id' => $branch->id, 'project_id' => $project->id]))->assertForbidden();
        }
        $actor = $this->user('superadmin');
        $target = $this->user('admin');
        $this->actingAs($target)->withSession(['impersonation.original_user_id' => $actor->id, 'impersonation.target_user_id' => $target->id, 'impersonation.started_at' => now()->toIso8601String()])->get(route('consumer-comparison.index', ['branch_id' => $branch->id, 'project_id' => $project->id]))->assertForbidden();
        $this->flushSession()->actingAs($actor)->get(route('consumer-comparison.index', ['branch_id' => $branch->id, 'project_id' => $project->id]))->assertOk()->assertSee('Phase 5.6');
    }

    private function legacy(Branch $branch, array $data): void
    {
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => Str::uuid(), 'row_data' => ['project_name' => 'Audit Project', ...$data]]);
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Audit Branch', 'code' => 'AB'.Str::upper(Str::random(6)), 'sheet_id' => 'audit-sheet', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Audit Project', 'is_active' => true]);
        Kavling::create(['project_id' => $project->id, 'kavling_code' => 'K-01', 'name' => 'K-01']);

        return [$branch, $project];
    }

    private function user(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id'), 'password_changed_at' => now()]);
    }
}
