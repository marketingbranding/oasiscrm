<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerLegacyIdentity;
use App\Models\ConsumerStageEvent;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsumerHistoricalProcessImportService;
use App\Services\ConsumerPasteImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricalProcessImportTest extends TestCase
{
    use RefreshDatabase;

    private function branchAndUser(string $roleSlug = 'superadmin'): array
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'is_superadmin' => $roleSlug === 'superadmin']);
        $branch = Branch::create([
            'name' => 'Test Branch '.str()->random(4),
            'code' => 'T'.str()->upper(str()->random(2)),
            'is_active' => true,
        ]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        return [$branch, $user];
    }

    private function projectFor(Branch $branch): LeadMaster
    {
        return LeadMaster::firstOrCreate(['branch_id' => $branch->id, 'project_name' => 'Oasis Test'], ['is_active' => true]);
    }

    private function localApplication(Branch $branch, string $kavlingCode, string $stage = 'bi_checking'): ConsumerApplication
    {
        $project = $this->projectFor($branch);
        $customer = Customer::create(['name' => 'Test Consumer']);
        $kavling = Kavling::firstOrCreate(['project_id' => $project->id, 'kavling_code' => $kavlingCode], ['name' => $kavlingCode]);
        $application = ConsumerApplication::create([
            'customer_id' => $customer->id, 'branch_id' => $branch->id, 'project_id' => $project->id,
            'kavling_id' => $kavling->id, 'application_status' => 'draft', 'current_stage' => $stage,
        ]);

        return $application;
    }

    private function attachPasteIdentity(ConsumerApplication $app, string $externalId): ConsumerLegacyIdentity
    {
        return ConsumerLegacyIdentity::create([
            'consumer_application_id' => $app->id,
            'customer_id' => $app->customer_id,
            'legacy_source' => ConsumerPasteImportService::SOURCE,
            'spreadsheet_id' => 'manual-paste',
            'sheet_name' => (string) $app->project_id,
            'external_key' => 'external:'.mb_strtolower($externalId),
            'mapping_status' => 'imported',
        ]);
    }

    public function test_parsing_reordered_headers(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-001');
        $this->attachPasteIdentity($app, 'lead-001');
        $project = $this->projectFor($branch);
        $tsv = "tanggal\tstatus\tid_kavling\tketerangan\texternal id\n2025-06-15\tLolos\tKAV-001\tSelesai\tlead-001\n";
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview($tsv, $branch, $project, 'bi_checking');
        $this->assertCount(1, $rows);
        $this->assertEquals('READY', $rows[0]['status']);
        $this->assertEquals('KAV-001', $rows[0]['normalized_data']['kavling']);
        $this->assertEquals($app->id, $rows[0]['normalized_data']['consumer_application_id']);
    }

    public function test_missing_optional_columns(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-002');
        $this->attachPasteIdentity($app, 'lead-002');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\texternal id\nKAV-002\tlead-002\n";
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview($tsv, $branch, $project, 'psjb');
        $this->assertCount(1, $rows);
        $this->assertEquals('READY', $rows[0]['status']);
        $this->assertNull($rows[0]['normalized_data']['date']);
        $this->assertNull($rows[0]['normalized_data']['status']);
    }

    public function test_blank_header_columns(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-003');
        $this->attachPasteIdentity($app, 'lead-003');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\t\tstatus\t\texternal id\nKAV-003\textra\tLolos\t\tlead-003\n";
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview($tsv, $branch, $project, 'pemberkasan');
        $this->assertCount(1, $rows);
        $this->assertEquals('READY', $rows[0]['status']);
    }

    public function test_supported_aliases(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-004');
        $this->attachPasteIdentity($app, 'lead-004');
        $project = $this->projectFor($branch);
        $tsv = "kav\ttgl pemberkasan\thasil\tcatatan\texternal_id\nKAV-004\t2025-07-01\tSelesai\tOke\tlead-004\n";
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview($tsv, $branch, $project, 'pemberkasan');
        $this->assertCount(1, $rows);
        $this->assertEquals('READY', $rows[0]['status']);
        $this->assertEquals('2025-07-01', $rows[0]['normalized_data']['date']);
    }

    public function test_same_paste_idempotency(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-005');
        $this->attachPasteIdentity($app, 'lead-005');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\tstatus\texternal id\nKAV-005\t2025-08-01\tLolos\tlead-005\n";
        $service = new ConsumerHistoricalProcessImportService;
        $batch1 = $service->createBatch($user, $branch, $project, $tsv, 'bi_checking');
        $result1 = $service->import($batch1, $user);
        $this->assertEquals(1, $result1['created_events']);
        $this->assertDatabaseHas('consumer_stage_events', ['consumer_application_id' => $app->id, 'stage' => 'bi_checking', 'source' => ConsumerHistoricalProcessImportService::SOURCE]);
        $previewRows = $service->preview($tsv, $branch, $project, 'bi_checking');
        $this->assertEquals('ALREADY_IMPORTED', $previewRows[0]['status']);
    }

    public function test_reused_kavling_alone_never_resolves(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $project = $this->projectFor($branch);
        $customer1 = Customer::create(['name' => 'Consumer A']);
        $customer2 = Customer::create(['name' => 'Consumer B']);
        $kavling = Kavling::firstOrCreate(['project_id' => $project->id, 'kavling_code' => 'KAV-SHARE'], ['name' => 'KAV-SHARE']);
        ConsumerApplication::create(['customer_id' => $customer1->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'kavling_id' => $kavling->id, 'application_status' => 'draft']);
        ConsumerApplication::create(['customer_id' => $customer2->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'kavling_id' => $kavling->id, 'application_status' => 'draft']);
        $tsv = "id_kavling\ttanggal\tstatus\nKAV-SHARE\t2025-09-01\tSelesai\n";
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview($tsv, $branch, $project, 'psjb');
        $this->assertEquals('UNRESOLVED_APPLICATION', $rows[0]['status']);
    }

    public function test_reused_kavling_resolves_via_provenance_to_single_application(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $project = $this->projectFor($branch);
        $customer1 = Customer::create(['name' => 'Consumer A']);
        $customer2 = Customer::create(['name' => 'Consumer B']);
        $kavling = Kavling::firstOrCreate(['project_id' => $project->id, 'kavling_code' => 'KAV-SHARE'], ['name' => 'KAV-SHARE']);
        $appA = ConsumerApplication::create(['customer_id' => $customer1->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'kavling_id' => $kavling->id, 'application_status' => 'draft']);
        $appB = ConsumerApplication::create(['customer_id' => $customer2->id, 'branch_id' => $branch->id, 'project_id' => $project->id, 'kavling_id' => $kavling->id, 'application_status' => 'draft']);
        $this->attachPasteIdentity($appA, 'lead-share-a');
        $this->attachPasteIdentity($appB, 'lead-share-b');
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview("id_kavling\ttanggal\tstatus\texternal id\nKAV-SHARE\t2025-09-01\tSelesai\tlead-share-b\n", $branch, $project, 'psjb');
        $this->assertEquals('READY', $rows[0]['status']);
        $this->assertEquals($appB->id, $rows[0]['normalized_data']['consumer_application_id']);
    }

    public function test_provenance_kavling_conflict_classified(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $project = $this->projectFor($branch);
        $app = $this->localApplication($branch, 'KAV-CONFLICT-A');
        $this->attachPasteIdentity($app, 'lead-conflict');
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview("id_kavling\ttanggal\tstatus\texternal id\nKAV-CONFLICT-B\t2025-10-01\tOK\tlead-conflict\n", $branch, $project, 'akad');
        $this->assertEquals('IDENTITY_CONFLICT', $rows[0]['status']);
    }

    public function test_safe_application_resolution(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-RESOLVE');
        $this->attachPasteIdentity($app, 'lead-resolve');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\tstatus\texternal id\nKAV-RESOLVE\t2025-10-01\tOK\tlead-resolve\n";
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview($tsv, $branch, $project, 'akad');
        $this->assertEquals('READY', $rows[0]['status']);
        $this->assertEquals($app->id, $rows[0]['normalized_data']['consumer_application_id']);
    }

    public function test_unresolved_application(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\tstatus\texternal id\nKAV-NONE\t2025-11-01\tDone\tlead-none\n";
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview($tsv, $branch, $project, 'bast');
        $this->assertEquals('UNRESOLVED_APPLICATION', $rows[0]['status']);
    }

    public function test_invalid_row_missing_kavling(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\n\t2025-12-01\n";
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview($tsv, $branch, $project, 'bi_checking');
        $this->assertEquals('INVALID', $rows[0]['status']);
    }

    public function test_bi_checking_stage_event_import(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-BIC');
        $this->attachPasteIdentity($app, 'lead-bic');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\tstatus\tketerangan\texternal id\nKAV-BIC\t2025-06-15\tLolos\tBI checking selesai\tlead-bic\n";
        $service = new ConsumerHistoricalProcessImportService;
        $batch = $service->createBatch($user, $branch, $project, $tsv, 'bi_checking');
        $result = $service->import($batch, $user);
        $this->assertEquals(1, $result['created_events']);
        $event = ConsumerStageEvent::where('consumer_application_id', $app->id)->where('stage', 'bi_checking')->first();
        $this->assertNotNull($event);
        $this->assertEquals('2025-06-15', $event->occurred_at->format('Y-m-d'));
        $this->assertEquals('BI checking selesai', $event->reason);
        $this->assertEquals(ConsumerHistoricalProcessImportService::SOURCE, $event->source);
    }

    public function test_psjb_stage_event_import(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-PSJB');
        $this->attachPasteIdentity($app, 'lead-psjb');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\tstatus\texternal id\nKAV-PSJB\t2025-07-10\tSelesai\tlead-psjb\n";
        $service = new ConsumerHistoricalProcessImportService;
        $batch = $service->createBatch($user, $branch, $project, $tsv, 'psjb');
        $result = $service->import($batch, $user);
        $this->assertEquals(1, $result['created_events']);
        $event = ConsumerStageEvent::where('consumer_application_id', $app->id)->where('stage', 'PSJB')->first();
        $this->assertNotNull($event);
    }

    public function test_pemberkasan_stage_event_import(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-PBK');
        $this->attachPasteIdentity($app, 'lead-pbk');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\tstatus\texternal id\nKAV-PBK\t2025-08-01\tLengkap\tlead-pbk\n";
        $service = new ConsumerHistoricalProcessImportService;
        $batch = $service->createBatch($user, $branch, $project, $tsv, 'pemberkasan');
        $result = $service->import($batch, $user);
        $this->assertEquals(1, $result['created_events']);
        $event = ConsumerStageEvent::where('consumer_application_id', $app->id)->where('stage', 'pemberkasan')->first();
        $this->assertNotNull($event);
    }

    public function test_ppjb_developer_stage_event_import(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-PPJB');
        $this->attachPasteIdentity($app, 'lead-ppjb');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\tstatus\texternal id\nKAV-PPJB\t2025-09-01\tOK\tlead-ppjb\n";
        $service = new ConsumerHistoricalProcessImportService;
        $batch = $service->createBatch($user, $branch, $project, $tsv, 'ppjb_developer');
        $result = $service->import($batch, $user);
        $this->assertEquals(1, $result['created_events']);
        $event = ConsumerStageEvent::where('consumer_application_id', $app->id)->where('stage', 'ppjb_dev')->first();
        $this->assertNotNull($event);
    }

    public function test_akad_stage_event_import(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-AKAD');
        $this->attachPasteIdentity($app, 'lead-akad');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\tstatus\texternal id\nKAV-AKAD\t2025-10-01\tSelesai\tlead-akad\n";
        $service = new ConsumerHistoricalProcessImportService;
        $batch = $service->createBatch($user, $branch, $project, $tsv, 'akad');
        $result = $service->import($batch, $user);
        $this->assertEquals(1, $result['created_events']);
        $event = ConsumerStageEvent::where('consumer_application_id', $app->id)->where('stage', 'akad')->first();
        $this->assertNotNull($event);
    }

    public function test_bast_stage_event_import(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-BAST');
        $this->attachPasteIdentity($app, 'lead-bast');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\tstatus\texternal id\nKAV-BAST\t2025-11-01\tDone\tlead-bast\n";
        $service = new ConsumerHistoricalProcessImportService;
        $batch = $service->createBatch($user, $branch, $project, $tsv, 'bast');
        $result = $service->import($batch, $user);
        $this->assertEquals(1, $result['created_events']);
        $event = ConsumerStageEvent::where('consumer_application_id', $app->id)->where('stage', 'bast')->first();
        $this->assertNotNull($event);
    }

    public function test_proses_bank_import(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-BANK');
        $this->attachPasteIdentity($app, 'lead-bank');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\tbank\tstatus bank\ttanggal\tketerangan\texternal id\nKAV-BANK\tBCA\tDisetujui\t2025-10-15\tProses lancar\tlead-bank\n";
        $service = new ConsumerHistoricalProcessImportService;
        $batch = $service->createBatch($user, $branch, $project, $tsv, 'proses_bank');
        $result = $service->import($batch, $user);
        $this->assertEquals(1, $result['created_bank_processes']);
        $bank = ConsumerBankProcess::where('consumer_application_id', $app->id)->first();
        $this->assertNotNull($bank);
        $this->assertEquals('BCA', $bank->bank_name);
        $this->assertEquals('Disetujui', $bank->status);
        $this->assertEquals('2025-10-15', $bank->submitted_at->format('Y-m-d'));
        $this->assertEquals(ConsumerHistoricalProcessImportService::SOURCE, $bank->source);
        $this->assertNotNull($bank->source_id);
        $this->assertNotNull($bank->metadata);
    }

    public function test_multiple_bank_rows_preserved(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-MULTI');
        $this->attachPasteIdentity($app, 'lead-multi');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\tbank\tstatus bank\ttanggal\texternal id\nKAV-MULTI\tBCA\tSubmitted\t2025-10-01\tlead-multi\nKAV-MULTI\tMandiri\tSubmitted\t2025-10-05\tlead-multi\nKAV-MULTI\tBCA\tDisetujui\t2025-10-15\tlead-multi\n";
        $service = new ConsumerHistoricalProcessImportService;
        $batch = $service->createBatch($user, $branch, $project, $tsv, 'proses_bank');
        $result = $service->import($batch, $user);
        $this->assertEquals(3, $result['created_bank_processes']);
        $banks = ConsumerBankProcess::where('consumer_application_id', $app->id)->get();
        $this->assertCount(3, $banks);
    }

    public function test_source_last_process_does_not_fabricate_events(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-NF');
        $app->update(['source_last_process' => 'M-2']);
        $eventCount = ConsumerStageEvent::where('consumer_application_id', $app->id)->count();
        $this->assertEquals(0, $eventCount);
    }

    public function test_superadmin_access(): void
    {
        [$branch, $user] = $this->branchAndUser('superadmin');
        $response = $this->actingAs($user)->get(route('historical-process-import.create'));
        $response->assertOk()->assertSee('Import Proses Historis');
    }

    public function test_impersonation_denied(): void
    {
        [$branch, $user] = $this->branchAndUser('superadmin');
        $response = $this->actingAs($user)->withSession(['impersonation.original_user_id' => 1])->get(route('historical-process-import.create'));
        $response->assertForbidden();
    }

    public function test_normal_branch_user_denied(): void
    {
        [$branch, $user] = $this->branchAndUser('sales');
        $response = $this->actingAs($user)->get(route('historical-process-import.create'));
        $response->assertForbidden();
    }

    public function test_wrong_branch_application_never_linked(): void
    {
        [$branch, $user] = $this->branchAndUser();
        [$otherBranch] = $this->branchAndUser();
        $otherProject = LeadMaster::firstOrCreate(['branch_id' => $otherBranch->id, 'project_name' => 'Other'], ['is_active' => true]);
        $customer = Customer::create(['name' => 'Wrong Branch']);
        $kavling = Kavling::firstOrCreate(['project_id' => $otherProject->id, 'kavling_code' => 'KAV-WRONG'], ['name' => 'KAV-WRONG']);
        $otherApp = ConsumerApplication::create(['customer_id' => $customer->id, 'branch_id' => $otherBranch->id, 'project_id' => $otherProject->id, 'kavling_id' => $kavling->id, 'application_status' => 'draft']);
        $this->attachPasteIdentity($otherApp, 'lead-wrong');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\tstatus\texternal id\nKAV-WRONG\t2025-10-01\tOK\tlead-wrong\n";
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview($tsv, $branch, $project, 'akad');
        $this->assertEquals('UNRESOLVED_APPLICATION', $rows[0]['status']);
    }

    public function test_no_plaintext_nik_in_preview(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-NIK');
        $this->attachPasteIdentity($app, 'lead-nik');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\tstatus\texternal id\nKAV-NIK\t2025-10-01\tOK\tlead-nik\n";
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview($tsv, $branch, $project, 'akad');
        $rowJson = json_encode($rows);
        $this->assertStringNotContainsString('nik_encrypted', $rowJson);
    }

    public function test_repeat_confirm_does_not_duplicate_stage_events(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-DUP');
        $this->attachPasteIdentity($app, 'lead-dup');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\ttanggal\tstatus\texternal id\nKAV-DUP\t2025-10-01\tSelesai\tlead-dup\n";
        $service = new ConsumerHistoricalProcessImportService;
        $batch1 = $service->createBatch($user, $branch, $project, $tsv, 'bast');
        $service->import($batch1, $user);
        $count1 = ConsumerStageEvent::where('consumer_application_id', $app->id)->where('stage', 'bast')->count();
        $this->assertEquals(1, $count1);
        $batch2 = $service->createBatch($user, $branch, $project, $tsv, 'bast');
        $service->import($batch2, $user);
        $count2 = ConsumerStageEvent::where('consumer_application_id', $app->id)->where('stage', 'bast')->count();
        $this->assertEquals(1, $count2);
    }

    public function test_repeat_confirm_does_not_duplicate_bank_processes(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-BDUP');
        $this->attachPasteIdentity($app, 'lead-bdup');
        $project = $this->projectFor($branch);
        $tsv = "id_kavling\tbank\tstatus bank\ttanggal\texternal id\nKAV-BDUP\tBCA\tOK\t2025-10-01\tlead-bdup\n";
        $service = new ConsumerHistoricalProcessImportService;
        $batch1 = $service->createBatch($user, $branch, $project, $tsv, 'proses_bank');
        $service->import($batch1, $user);
        $count1 = ConsumerBankProcess::where('consumer_application_id', $app->id)->count();
        $this->assertEquals(1, $count1);
        $batch2 = $service->createBatch($user, $branch, $project, $tsv, 'proses_bank');
        $service->import($batch2, $user);
        $count2 = ConsumerBankProcess::where('consumer_application_id', $app->id)->count();
        $this->assertEquals(1, $count2);
    }

    public function test_deterministic_row_identity_resolves_via_imported_identity(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-ROW');
        $project = $this->projectFor($branch);
        $key = ConsumerPasteImportService::deterministicIdentityKey([
            'name' => 'Budi Santoso', 'phone' => '081234567890', 'kavling' => 'KAV-ROW', 'external_id' => null,
        ], $branch->id, $project->id);
        $this->assertStringStartsWith('row:', $key);
        ConsumerLegacyIdentity::create([
            'consumer_application_id' => $app->id,
            'customer_id' => $app->customer_id,
            'legacy_source' => ConsumerPasteImportService::SOURCE,
            'spreadsheet_id' => 'manual-paste',
            'sheet_name' => (string) $project->id,
            'external_key' => $key,
            'mapping_status' => 'imported',
        ]);
        $service = new ConsumerHistoricalProcessImportService;
        $rows = $service->preview("id_kavling\tnama konsumen\tno hp\ttanggal\tstatus\nKAV-ROW\tBudi Santoso\t0812-3456-7890\t2025-10-01\tOK\n", $branch, $project, 'akad');
        $this->assertEquals('READY', $rows[0]['status']);
        $this->assertEquals($app->id, $rows[0]['normalized_data']['consumer_application_id']);
    }

    public function test_create_page_renders_with_process_types(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $response = $this->actingAs($user)->get(route('historical-process-import.create'));
        $response->assertOk()->assertSee('BI Checking')->assertSee('Proses Bank')->assertSee('BAST');
    }

    public function test_preview_route_creates_batch(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $project = $this->projectFor($branch);
        $app = $this->localApplication($branch, 'KAV-PREV');
        $this->attachPasteIdentity($app, 'lead-prev');
        $response = $this->actingAs($user)->post(route('historical-process-import.preview'), [
            'branch_id' => $branch->id, 'project_id' => $project->id, 'process_type' => 'bi_checking',
            'tsv' => "id_kavling\ttanggal\tstatus\texternal id\nKAV-PREV\t2025-10-01\tLolos\tlead-prev\n",
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('consumer_import_batches', ['source' => ConsumerHistoricalProcessImportService::SOURCE, 'status' => 'preview_ready']);
    }

    public function test_confirm_route_executes_import(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $app = $this->localApplication($branch, 'KAV-CONF');
        $this->attachPasteIdentity($app, 'lead-conf');
        $project = $this->projectFor($branch);
        $service = new ConsumerHistoricalProcessImportService;
        $batch = $service->createBatch($user, $branch, $project, "id_kavling\ttanggal\tstatus\texternal id\nKAV-CONF\t2025-10-01\tDone\tlead-conf\n", 'akad');
        $response = $this->actingAs($user)->post(route('historical-process-import.confirm', $batch), [
            'expected_updated_at' => $batch->updated_at->toISOString(),
        ]);
        $response->assertRedirect();
        $this->assertDatabaseHas('consumer_stage_events', ['consumer_application_id' => $app->id, 'stage' => 'akad']);
    }
}
