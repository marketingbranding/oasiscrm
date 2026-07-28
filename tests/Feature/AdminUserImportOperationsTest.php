<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\User;
use App\Models\UserImportBatch;
use App\Models\UserImportRow;
use App\Notifications\UserInvitationNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\CreatesUserImportWorkbooks;
use Tests\TestCase;

class AdminUserImportOperationsTest extends TestCase
{
    use CreatesUserImportWorkbooks;
    use RefreshDatabase;

    public function test_import_persists_primary_and_additional_assignments_with_exactly_one_primary_and_hashed_password(): void
    {
        $actor = $this->importActor();
        $solo = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $pati = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $main = LeadMaster::create(['branch_id' => $solo->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $extra = LeadMaster::create(['branch_id' => $pati->id, 'project_name' => 'Oasis Pati', 'is_active' => true]);
        $this->uploadImport($actor, [[
            'Sales Lengkap', 'sales.lengkap@example.test', 'sales', 'Solo', 'Pati',
            'Oasis Solo', 'Oasis Pati', '', 'pending_invitation',
        ]]);
        $batch = UserImportBatch::firstOrFail();
        $this->confirm($actor, $batch);

        $user = User::where('email', 'sales.lengkap@example.test')->firstOrFail();
        $this->assertSame($solo->id, $user->branch_id);
        $this->assertEqualsCanonicalizing([$solo->id, $pati->id], $user->branches->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$main->id, $extra->id], $user->assignedProjects->pluck('id')->all());
        $this->assertSame(1, $user->assignedProjects()->wherePivot('is_primary', true)->count());
        $this->assertTrue($user->primaryAssignedProject()->firstOrFail()->is($main));
        $this->assertTrue(Hash::needsRehash($user->password) === false);
        $this->assertStringStartsWith('$2y$', $user->getRawOriginal('password'));
        $this->assertFalse(Hash::check('', $user->password));
        $this->assertSame(AccountStatus::PendingInvitation, $user->account_status);
    }

    public function test_send_all_overrides_pending_rows_and_bulk_account_remains_compatible_with_manual_lifecycle_pages(): void
    {
        Notification::fake();
        $actor = $this->importActor();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $this->uploadImport($actor, [['Lifecycle', 'lifecycle@example.test', 'manager', 'Solo', '', '', '', '', 'pending_invitation']]);
        $batch = UserImportBatch::firstOrFail();
        $this->confirm($actor, $batch, true);

        $user = User::where('email', 'lifecycle@example.test')->firstOrFail();
        $this->assertSame(AccountStatus::Invited, $user->account_status);
        $this->assertDatabaseHas('user_invitations', ['user_id' => $user->id, 'invited_by' => $actor->id]);
        Notification::assertSentTo($user, UserInvitationNotification::class);
        $this->actingAs($actor)->get(route('admin-users.index'))->assertOk()->assertSee($user->email);
        $this->actingAs($actor)->get(route('admin-users.show', $user))->assertOk()->assertSee($user->email);
        $this->actingAs($actor)->patch(route('admin-users.invitation.revoke', $user))->assertRedirect();
        $this->assertNotNull($user->invitations()->latest('id')->firstOrFail()->revoked_at);
    }

    public function test_result_xlsx_has_exact_headers_text_types_filter_freeze_labels_and_formula_safe_values(): void
    {
        $actor = $this->importActor();
        $batch = UserImportBatch::create([
            'original_filename' => 'result.xlsx', 'uploaded_by' => $actor->id,
            'status' => UserImportBatch::STATUS_COMPLETED, 'total_rows' => 1, 'completed_at' => now(),
        ]);
        $batch->rows()->create([
            'row_number' => 7,
            'raw_data' => [
                'name' => '=2+2', 'email' => '+formula@example.test', 'role' => 'manager',
                'primary_branch' => 'Solo', 'additional_branches' => '', 'primary_project' => '',
                'additional_projects' => '', 'supervisor_email' => '@boss', 'status' => 'invited',
            ],
            'normalized_data' => [], 'validation_status' => UserImportRow::VALIDATION_ERROR,
            'errors' => ['=unsafe message'], 'warnings' => ['warning'],
            'creation_status' => 'created', 'invitation_status' => 'email_failed',
        ]);

        $response = $this->actingAs($actor)->get(route('admin-users.import-result', $batch))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'oasis-result-');
        file_put_contents($path, $response->streamedContent());
        $workbook = IOFactory::load($path);
        try {
            $sheet = $workbook->getActiveSheet();
            $this->assertSame('HASIL IMPORT USER', $sheet->getTitle());
            $this->assertSame([
                'Row', 'Nama', 'Email', 'Role', 'Cabang Utama', 'Proyek Utama', 'Atasan Langsung',
                'User Creation Status', 'Invitation Status', 'Error / Warning',
            ], $sheet->rangeToArray('A1:J1')[0]);
            $this->assertSame(['7', '=2+2', '+formula@example.test', 'manager', 'Solo', null, '@boss', 'CREATED', 'CREATED - EMAIL FAILED', '=unsafe message | warning'], $sheet->rangeToArray('A2:J2')[0]);
            foreach (['A', 'B', 'C', 'D', 'E', 'G', 'H', 'I', 'J'] as $column) {
                $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($column.'2')->getDataType());
            }
            $this->assertSame(DataType::TYPE_NULL, $sheet->getCell('F2')->getDataType());
            $this->assertSame('A2', $sheet->getFreezePane());
            $this->assertSame('A1:J2', $sheet->getAutoFilter()->getRange());
        } finally {
            $workbook->disconnectWorksheets();
            @unlink($path);
        }
    }

    public function test_cleanup_dry_run_preserves_rows_then_delete_removes_only_expired_unconfirmed_batches(): void
    {
        $actor = $this->importActor();
        $expired = $this->batch($actor, UserImportBatch::STATUS_PREVIEW_READY, now()->subHour());
        $fresh = $this->batch($actor, UserImportBatch::STATUS_PREVIEW_READY, now()->addHour());
        $confirmed = $this->batch($actor, UserImportBatch::STATUS_COMPLETED, now()->subHour(), now()->subMinutes(30));
        $expired->rows()->create(['row_number' => 2, 'raw_data' => [], 'normalized_data' => [], 'validation_status' => 'valid']);

        $this->artisan('oasis:user-import-cleanup', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 batches and 1 staging rows')->assertSuccessful();
        $this->assertDatabaseHas('user_import_batches', ['id' => $expired->id]);

        $this->artisan('oasis:user-import-cleanup')->expectsOutputToContain('1 expired staging batches deleted')->assertSuccessful();
        $this->assertDatabaseMissing('user_import_batches', ['id' => $expired->id]);
        $this->assertDatabaseMissing('user_import_rows', ['batch_id' => $expired->id]);
        $this->assertDatabaseHas('user_import_batches', ['id' => $fresh->id]);
        $this->assertDatabaseHas('user_import_batches', ['id' => $confirmed->id]);
    }

    public function test_cleanup_is_scheduled_daily_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'oasis:user-import-cleanup'));

        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_bulk_audits_contain_actor_batch_and_row_metadata_without_secrets_or_workbook_data(): void
    {
        $actor = $this->importActor();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $secret = 'RAW-WORKBOOK-SECRET';
        $this->uploadImport($actor, [[$secret, 'audit@example.test', 'manager', 'Solo', '', '', '', '', 'pending_invitation']]);
        $batch = UserImportBatch::firstOrFail();
        $this->confirm($actor, $batch);

        $logs = ActivityLog::whereIn('event', [
            'user_import_uploaded', 'user_import_preview_generated', 'user_import_confirmed',
            'user_created_bulk', 'user_import_completed',
        ])->get();
        $this->assertCount(5, $logs);
        $bulk = $logs->firstWhere('event', 'user_created_bulk');
        $this->assertSame($actor->id, $bulk->causer_id);
        $this->assertSame($batch->id, $bulk->properties['batch_id']);
        $this->assertArrayHasKey('row_id', $bulk->properties);
        $serialized = $logs->toJson();
        foreach ([$secret, 'audit@example.test', 'password', 'token_hash', 'remember_token'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $serialized);
        }
    }

    private function confirm(User $actor, UserImportBatch $batch, bool $sendAll = false): void
    {
        $this->actingAs($actor)->post(route('admin-users.import-confirm'), [
            'batch_id' => $batch->id,
            'expected_updated_at' => $batch->updated_at->toISOString(),
            'send_invitations' => $sendAll ? '1' : '0',
        ])->assertSessionHasNoErrors();
    }

    private function batch(User $actor, string $status, $expiresAt, $confirmedAt = null): UserImportBatch
    {
        return UserImportBatch::create([
            'original_filename' => "{$status}.xlsx", 'uploaded_by' => $actor->id,
            'status' => $status, 'expires_at' => $expiresAt, 'confirmed_at' => $confirmedAt,
        ]);
    }
}
