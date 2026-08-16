<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\ConsumerApplication;
use App\Models\Customer;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsumerPasteImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ConsumerPasteImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_handles_bom_crlf_blank_rows_and_header_aliases(): void
    {
        [$branch, $project] = $this->context();
        $batch = app(ConsumerPasteImportService::class)->createBatch(
            $this->admin(),
            $branch,
            $project,
            "\xEF\xBB\xBFNama Konsumen\tNo HP\tExternal ID\r\nBudi\t+628123\tEXT-1\r\n\r\n",
        );

        $this->assertSame(1, $batch->total_rows);
        $this->assertSame('READY', $batch->rows()->sole()->status);
    }

    public function test_duplicate_headers_and_unknown_headers_are_rejected(): void
    {
        [$branch, $project] = $this->context();
        $this->expectException(InvalidArgumentException::class);
        app(ConsumerPasteImportService::class)->createBatch($this->admin(), $branch, $project, "Nama Konsumen\tNama Konsumen\nBudi\tBudi");
    }

    public function test_malformed_row_is_previewed_invalid_without_aborting_other_rows(): void
    {
        [$branch, $project] = $this->context();
        $batch = app(ConsumerPasteImportService::class)->createBatch($this->admin(), $branch, $project, "Nama Konsumen\tNo HP\nBudi\t081234\textra\nSari\t081235");

        $this->assertSame(['INVALID', 'READY'], $batch->rows()->orderBy('line_number')->pluck('status')->all());
    }

    public function test_preview_marks_unknown_stage_and_invalid_date_for_review_or_invalid(): void
    {
        [$branch, $project] = $this->context();
        $batch = app(ConsumerPasteImportService::class)->createBatch($this->admin(), $branch, $project, "Nama Konsumen\tTahap\tTanggal Booking\nBudi\tTahap Baru\t31/02/2026");
        $row = $batch->rows()->sole();

        $this->assertSame('NEEDS_REVIEW', $row->status);
        $this->assertContains('Tanggal Booking tidak valid atau ambigu.', $row->errors);
        $this->assertContains('Tahap tidak dikenal; baris perlu diperiksa.', $row->warnings);
    }

    public function test_preview_reports_exact_mixed_status_counts(): void
    {
        [$branch, $project] = $this->context();
        $actor = $this->admin();
        $input = "Nama Konsumen\tNo HP\tPromo\tTahap\tExternal ID\nReady\t081231\t\t\tREADY-1\nWarning\t081232\tMissing Promo\t\tWARN-1\nReview\t\t\tTahap Baru\tREVIEW-1\nInvalid\t081234\textra\t\tINVALID-1\textra\nAlready\t081235\t\t\tALREADY-1";
        $firstInput = "Nama Konsumen\tNo HP\tExternal ID\nAlready\t081235\tALREADY-1";
        $first = app(ConsumerPasteImportService::class)->createBatch($actor, $branch, $project, $firstInput);
        app(ConsumerPasteImportService::class)->import($first, $actor);
        $second = app(ConsumerPasteImportService::class)->createBatch($actor, $branch, $project, $input);

        $counts = $second->rows()->get()->groupBy('status')->map->count()->all();
        ksort($counts);
        $this->assertSame(['ALREADY_IMPORTED' => 1, 'INVALID' => 1, 'NEEDS_REVIEW' => 1, 'READY' => 1, 'WARNING' => 1], $counts);
        $this->assertSame(5, $second->total_rows);
        $this->assertSame(1, $second->ready_rows);
        $this->assertSame(1, $second->already_imported_rows);
        $this->assertSame(1, $second->warning_rows);
        $this->assertSame(1, $second->review_rows);
        $this->assertSame(1, $second->invalid_rows);
    }

    public function test_confirm_reconciles_mixed_rows_without_importing_review_or_invalid_rows(): void
    {
        [$branch, $project] = $this->context();
        $actor = $this->admin();
        $input = "Nama Konsumen\tNo HP\tPromo\tTahap\tExternal ID\nReady\t081231\t\t\tREADY-1\nWarning\t081232\tMissing Promo\t\tWARN-1\nReview\t\t\tTahap Baru\tREVIEW-1\nInvalid\t081234\textra\t\tINVALID-1\textra";
        $batch = app(ConsumerPasteImportService::class)->createBatch($actor, $branch, $project, $input);

        $result = app(ConsumerPasteImportService::class)->import($batch, $actor);
        $rows = $result['batch']->rows()->orderBy('line_number')->get();

        $this->assertSame(['IMPORTED', 'IMPORTED', 'SKIPPED', 'SKIPPED'], $rows->pluck('status')->all());
        $this->assertSame(1, $result['warning']);
        $this->assertSame(1, $result['review']);
        $this->assertSame(1, $result['invalid']);
        $this->assertSame(2, $result['created_applications']);
        $this->assertSame(2, ConsumerApplication::count());
        $this->assertDatabaseHas('activity_log', [
            'event' => 'consumer_legacy_paste_import',
        ]);
        $log = ActivityLog::where('event', 'consumer_legacy_paste_import')->latest('id')->firstOrFail();
        $this->assertSame(1, $log->properties['invalid']);
        $this->assertSame(1, $log->properties['warning']);
    }

    public function test_import_creates_local_customer_application_identity_and_is_idempotent(): void
    {
        [$branch, $project] = $this->context();
        $actor = $this->admin();
        $input = "Nama Konsumen\tNo HP\tExternal ID\tTahap\tTanggal Akad\nBudi\t081234\tEXT-1\tAkad\t2026-08-16";
        $batch = app(ConsumerPasteImportService::class)->createBatch($actor, $branch, $project, $input);
        $result = app(ConsumerPasteImportService::class)->import($batch, $actor);

        $this->assertSame(1, $result['created_applications']);
        $this->assertSame(1, Customer::count());
        $this->assertSame(1, ConsumerApplication::count());
        $this->assertDatabaseHas('consumer_legacy_identities', ['external_key' => 'external:ext-1']);
        $this->assertDatabaseMissing('customers', ['phone' => 'DO-NOT-SAVE']);

        $second = app(ConsumerPasteImportService::class)->createBatch($actor, $branch, $project, $input);
        $secondResult = app(ConsumerPasteImportService::class)->import($second, $actor);
        $this->assertSame(0, $secondResult['created_applications']);
        $this->assertSame(1, ConsumerApplication::count());
    }

    public function test_only_superadmin_can_open_import_routes(): void
    {
        [$branch, $project] = $this->context();
        foreach (['admin', 'sales_coordinator', 'supervisor', 'sales'] as $slug) {
            $user = User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id'), 'branch_id' => $branch->id, 'password_changed_at' => now()]);
            $this->actingAs($user)->get(route('consumer-import.create'))->assertForbidden();
        }

        $this->actingAs($this->admin())->get(route('consumer-import.create'))->assertOk();
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Import Branch', 'code' => 'IM'.Str::upper(Str::random(6)), 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Import Project', 'is_active' => true]);

        return [$branch, $project];
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id'), 'password_changed_at' => now()]);
    }
}
