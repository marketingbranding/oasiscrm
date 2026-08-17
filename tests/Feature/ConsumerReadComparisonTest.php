<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerLegacyIdentity;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\KonsumenProgressSheetRow;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsumerReadComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsumerReadComparisonTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_mapping_normalizes_phone_date_and_stage_and_selects_latest_bank(): void
    {
        [$branch, $project] = $this->context();
        $customer = Customer::create(['name' => 'Budi', 'phone' => '0812345678']);
        $kavling = Kavling::query()->where('project_id', $project->id)->where('kavling_code', 'K-01')->firstOrFail();
        $application = ConsumerApplication::create([
            'customer_id' => $customer->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'kavling_id' => $kavling->id,
            'application_status' => 'active', 'current_stage' => 'akad', 'booking_date' => '2026-08-01',
        ]);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'customer_id' => $customer->id, 'legacy_source' => 'manual_spreadsheet_paste', 'external_key' => 'external:ext-1', 'mapping_status' => 'imported']);
        ConsumerBankProcess::create(['consumer_application_id' => $application->id, 'bank_name' => 'Bank Lama', 'status' => 'pending', 'submitted_at' => '2026-08-01']);
        ConsumerBankProcess::create(['consumer_application_id' => $application->id, 'bank_name' => 'Bank Baru', 'status' => 'approved', 'submitted_at' => '2026-08-02']);
        $this->legacy($branch, 'K-01', ['nama_konsumen' => 'Budi', 'no_hp' => '+62812345678', 'project_name' => $project->project_name, 'external_id' => 'EXT-1', 'tanggal_booking' => '2026-08-01', 'bank' => 'Bank Baru', 'status_bank' => 'approved'], 'akad');

        $result = app(ConsumerReadComparisonService::class)->compare($branch, $project);

        $this->assertSame(1, $result->summary['matched'], json_encode([$result->summary, $result->rows], JSON_THROW_ON_ERROR));
        $this->assertSame(1, $result->summary['exact_match']);
        $this->assertSame(0, $result->summary['mismatch']);
        $this->assertSame('Bank Baru', $application->fresh()->bankProcesses()->latest('submitted_at')->value('bank_name'));
        $this->assertSame(100.0, $result->coverage['link_coverage_percent']);
    }

    public function test_existing_manual_paste_provenance_resolves_without_identity_write(): void
    {
        [$branch, $project] = $this->context();
        $customer = Customer::create(['name' => 'Provenance Consumer', 'phone' => '0812345678']);
        $application = ConsumerApplication::create(['customer_id' => $customer->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'application_status' => 'draft', 'current_stage' => 'akad']);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'customer_id' => $customer->id, 'legacy_source' => 'manual_spreadsheet_paste', 'external_key' => 'external:provenance-1']);
        $this->legacy($branch, 'K-01', ['nama_konsumen' => 'Provenance Consumer', 'external_id' => 'provenance-1'], 'akad');
        $before = ConsumerLegacyIdentity::count();

        $result = app(ConsumerReadComparisonService::class)->compare($branch, $project);

        $row = collect($result->rows)->first(fn ($row) => in_array($row['status'], ['MATCHED', 'MISMATCH'], true));
        $this->assertSame(1, $result->summary['matched'] + $result->summary['mismatch']);
        $this->assertContains('EXISTING_PROVENANCE', $row['notes']);
        $this->assertSame($before, ConsumerLegacyIdentity::count());
    }

    public function test_reports_mismatch_legacy_only_local_only_and_ambiguous(): void
    {
        [$branch, $project] = $this->context();
        $this->legacy($branch, 'K-01', ['nama_konsumen' => 'Budi', 'external_id' => 'EXT-1'], 'akad');
        $customer = Customer::create(['name' => 'Budi Berbeda', 'phone' => '0812']);
        $application = ConsumerApplication::create(['customer_id' => $customer->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'application_status' => 'draft', 'current_stage' => 'bast']);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'customer_id' => $customer->id, 'legacy_source' => 'manual_spreadsheet_paste', 'external_key' => 'external:ext-1']);
        $this->legacy($branch, 'K-02', ['nama_konsumen' => 'Legacy Only', 'external_id' => 'EXT-2'], 'akad');
        ConsumerApplication::create(['customer_id' => Customer::create(['name' => 'Local Only'])->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'application_status' => 'draft']);
        $ambiguous = ConsumerApplication::create(['customer_id' => Customer::create(['name' => 'Ambiguous'])->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'application_status' => 'draft']);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $ambiguous->id, 'legacy_source' => 'manual_spreadsheet_paste', 'external_key' => 'external:ext-3']);
        $ambiguous2 = ConsumerApplication::create(['customer_id' => Customer::create(['name' => 'Ambiguous 2'])->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'application_status' => 'draft']);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $ambiguous2->id, 'legacy_source' => 'manual_spreadsheet_paste', 'external_key' => 'external:ext-3']);
        $this->legacy($branch, 'K-03', ['nama_konsumen' => 'Ambiguous', 'external_id' => 'EXT-3'], 'akad');

        $result = app(ConsumerReadComparisonService::class)->compare($branch, $project);

        $this->assertSame(1, $result->summary['mismatch']);
        $this->assertSame(1, $result->summary['legacy_only']);
        $this->assertSame(1, $result->summary['local_only']);
        $this->assertSame(1, $result->summary['ambiguous']);
        $this->assertContains('stage', collect($result->rows)->firstWhere('status', 'MISMATCH')['mismatch_fields']);
    }

    public function test_comparison_page_is_superadmin_only_and_requires_no_write_action(): void
    {
        [$branch, $project] = $this->context();
        foreach (['admin', 'sales_coordinator', 'supervisor', 'sales'] as $slug) {
            $this->actingAs($this->user($slug))->get(route('consumer-comparison.index', ['branch_id' => $branch->id, 'project_id' => $project->id]))->assertForbidden();
        }
        $response = $this->actingAs($this->user('superadmin'))->get(route('consumer-comparison.index', ['branch_id' => $branch->id, 'project_id' => $project->id]));
        $response->assertOk()->assertSee('Perbandingan Data Konsumen')->assertSee('NOT_READY')->assertSee('Coverage link')->assertDontSee('Sinkronkan')->assertDontSee('Perbaiki');
    }

    public function test_comparison_page_is_blocked_while_impersonating(): void
    {
        [$branch, $project] = $this->context();
        $actor = $this->user('superadmin');
        $target = $this->user('admin');

        $this->actingAs($target)->withSession([
            'impersonation.original_user_id' => $actor->id,
            'impersonation.target_user_id' => $target->id,
            'impersonation.started_at' => now()->toIso8601String(),
        ])->get(route('consumer-comparison.index', ['branch_id' => $branch->id, 'project_id' => $project->id]))->assertForbidden();
    }

    public function test_legacy_rows_from_another_project_are_not_compared(): void
    {
        [$branch, $project] = $this->context();
        $otherProject = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Other Project', 'is_active' => true]);
        Kavling::create(['project_id' => $otherProject->id, 'kavling_code' => 'OTHER-01', 'name' => 'OTHER-01']);
        $this->legacy($branch, 'OTHER-01', ['nama_konsumen' => 'Other Project Consumer', 'project_name' => $otherProject->project_name, 'external_id' => 'OTHER-1'], 'akad');

        $result = app(ConsumerReadComparisonService::class)->compare($branch, $project);

        $this->assertSame(0, $result->summary['total_legacy']);
    }

    public function test_bank_selection_uses_latest_submitted_date_and_null_is_oldest(): void
    {
        [$branch, $project] = $this->context();
        $application = ConsumerApplication::create(['customer_id' => Customer::create(['name' => 'Bank Test'])->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'kavling_id' => Kavling::where('project_id', $project->id)->where('kavling_code', 'BANK-TEST')->value('id'), 'current_stage' => 'akad', 'application_status' => 'draft']);
        ConsumerBankProcess::create(['consumer_application_id' => $application->id, 'bank_name' => 'Null Submitted', 'status' => 'old', 'submitted_at' => null]);
        ConsumerBankProcess::create(['consumer_application_id' => $application->id, 'bank_name' => 'Latest Submitted', 'status' => 'current', 'submitted_at' => '2026-08-02']);
        ConsumerLegacyIdentity::create(['consumer_application_id' => $application->id, 'legacy_source' => 'manual_spreadsheet_paste', 'external_key' => 'external:bank-test']);
        $this->legacy($branch, 'BANK-TEST', ['nama_konsumen' => 'Bank Test', 'external_id' => 'bank-test', 'bank' => 'Latest Submitted', 'status_bank' => 'current'], 'akad');

        $result = app(ConsumerReadComparisonService::class)->compare($branch, $project);

        $this->assertSame(1, $result->summary['exact_match'], json_encode([$result->summary, $result->rows], JSON_THROW_ON_ERROR));
    }

    private function legacy(Branch $branch, string $kavling, array $customer, string $stage): void
    {
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => 'data_konsumen', 'row_hash' => Str::uuid(), 'row_data' => ['id_kavling' => $kavling, ...$customer]]);
        KonsumenProgressSheetRow::create(['branch_id' => $branch->id, 'sheet_id' => $branch->sheet_id, 'sheet_name' => $stage, 'row_hash' => Str::uuid(), 'row_data' => ['id_kavling' => $kavling]]);
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Comparison Branch', 'code' => 'CP'.Str::upper(Str::random(6)), 'sheet_id' => 'sheet-id', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Comparison Project', 'is_active' => true]);
        foreach (['K-01', 'K-02', 'K-03', 'BANK-TEST'] as $code) {
            Kavling::create(['project_id' => $project->id, 'kavling_code' => $code, 'name' => $code]);
        }

        return [$branch, $project];
    }

    private function user(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id'), 'password_changed_at' => now()]);
    }
}
