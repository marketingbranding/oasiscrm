<?php

namespace Tests\Feature;

use App\Exports\UserImportTemplateExport;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\User;
use App\Models\UserImportBatch;
use App\Models\UserImportRow;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use App\Services\AccountAuditService;
use App\Services\BranchAssignmentService;
use App\Services\UserInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class AdminUserImportExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_creates_all_users_links_same_batch_supervisor_and_sends_only_invited_rows(): void
    {
        Notification::fake();
        $actor = $this->superadmin();
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $batch = $this->preview($actor, [
            ['Manager Baru', 'manager.baru@example.test', 'manager', 'Solo', '', '', '', '', 'pending_invitation'],
            ['Sales Baru', 'sales.baru@example.test', 'sales', 'Solo', '', 'Oasis Solo', '', 'manager.baru@example.test', 'invited'],
        ]);

        $this->actingAs($actor)->post(route('admin-users.import-confirm'), [
            'batch_id' => $batch->id,
            'expected_updated_at' => $batch->updated_at->toISOString(),
            'send_invitations' => '0',
        ])->assertRedirect(route('admin-users.import-batches.show', $batch));

        $batch->refresh();
        $manager = User::where('email', 'manager.baru@example.test')->firstOrFail();
        $sales = User::where('email', 'sales.baru@example.test')->firstOrFail();
        $this->assertSame(UserImportBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame([2, 1, 0, 0], [$batch->created_rows, $batch->invitation_sent_rows, $batch->invitation_failed_rows, $batch->skipped_rows]);
        $this->assertSame($manager->id, $sales->supervisor_user_id);
        $this->assertSame($branch->id, $sales->branch_id);
        $this->assertTrue($sales->assignedProjects()->whereKey($project)->wherePivot('is_primary', true)->exists());
        $this->assertSame('not_requested', UserImportRow::where('created_user_id', $manager->id)->value('invitation_status'));
        $this->assertSame('sent', UserImportRow::where('created_user_id', $sales->id)->value('invitation_status'));
        Notification::assertSentTo($sales, UserInvitationNotification::class);
        Notification::assertNotSentTo($manager, UserInvitationNotification::class);

        $this->actingAs($actor)->post(route('admin-users.import-confirm'), [
            'batch_id' => $batch->id,
            'expected_updated_at' => $batch->updated_at->toISOString(),
            'send_invitations' => '1',
        ])->assertSessionHasErrors('batch_id');
        $this->assertSame(2, User::whereIn('email', ['manager.baru@example.test', 'sales.baru@example.test'])->count());
    }

    public function test_confirm_revalidates_current_organization_data_and_creates_nothing_on_error(): void
    {
        $actor = $this->superadmin();
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $batch = $this->preview($actor, [
            ['User Baru', 'stale.scope@example.test', 'manager', 'Solo', '', '', '', '', 'pending_invitation'],
        ]);
        $branch->update(['is_active' => false]);

        $this->actingAs($actor)->post(route('admin-users.import-confirm'), [
            'batch_id' => $batch->id,
            'expected_updated_at' => $batch->updated_at->toISOString(),
            'send_invitations' => '0',
        ])->assertSessionHasErrors('batch_id');

        $this->assertDatabaseMissing('users', ['email' => 'stale.scope@example.test']);
        $this->assertSame(UserImportBatch::STATUS_VALIDATION_FAILED, $batch->fresh()->status);
        $this->assertSame(UserImportRow::VALIDATION_ERROR, $batch->rows()->firstOrFail()->validation_status);
    }

    public function test_completed_result_export_uses_explicit_text_cells(): void
    {
        Notification::fake();
        $actor = $this->superadmin();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $batch = $this->preview($actor, [
            ['Aman', 'aman.export@example.test', 'manager', 'Solo', '', '', '', '', 'pending_invitation'],
        ]);
        $this->actingAs($actor)->post(route('admin-users.import-confirm'), [
            'batch_id' => $batch->id,
            'expected_updated_at' => $batch->updated_at->toISOString(),
            'send_invitations' => '0',
        ])->assertSessionHasNoErrors();

        $response = $this->actingAs($actor)->get(route('admin-users.import-result', $batch));
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'result-xlsx-');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertSame('CREATED', $sheet->getCell('H2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
            $this->assertSame('A2', $sheet->getFreezePane());
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($path);
        }
    }

    public function test_send_all_above_synchronous_cap_revalidates_as_error_without_creating_accounts(): void
    {
        $actor = $this->superadmin();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $rows = [];
        foreach (range(1, 101) as $number) {
            $rows[] = ["User {$number}", "cap{$number}@example.test", 'manager', 'Solo', '', '', '', '', 'pending_invitation'];
        }
        $batch = $this->preview($actor, $rows);

        $this->actingAs($actor)->post(route('admin-users.import-confirm'), [
            'batch_id' => $batch->id,
            'expected_updated_at' => $batch->updated_at->toISOString(),
            'send_invitations' => '1',
        ])->assertSessionHasErrors('batch_id');

        $batch->refresh();
        $this->assertSame(UserImportBatch::STATUS_VALIDATION_FAILED, $batch->status);
        $this->assertSame(101, $batch->error_rows);
        $this->assertSame(101, $batch->skipped_rows);
        $this->assertSame(0, User::where('email', 'like', 'cap%@example.test')->count());
    }

    public function test_email_failure_keeps_created_invited_account_and_completes_batch(): void
    {
        $actor = $this->superadmin();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $batch = $this->preview($actor, [
            ['Email Gagal', 'email.gagal@example.test', 'manager', 'Solo', '', '', '', '', 'invited'],
        ]);
        $this->app->instance(UserInvitationService::class, new class(app(AccountAuditService::class)) extends UserInvitationService
        {
            public function send(User $user, User $inviter, ?\DateTimeInterface $expiresAt = null): UserInvitation
            {
                $user->update(['account_status' => 'invited', 'invited_at' => now()]);

                throw new RuntimeException('SMTP internal detail');
            }
        });

        $this->actingAs($actor)->post(route('admin-users.import-confirm'), [
            'batch_id' => $batch->id,
            'expected_updated_at' => $batch->updated_at->toISOString(),
            'send_invitations' => '0',
        ])->assertSessionHasNoErrors();

        $batch->refresh();
        $row = $batch->rows()->firstOrFail();
        $user = User::where('email', 'email.gagal@example.test')->firstOrFail();
        $this->assertSame(UserImportBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame([1, 0, 1], [$batch->created_rows, $batch->invitation_sent_rows, $batch->invitation_failed_rows]);
        $this->assertSame('created', $row->creation_status);
        $this->assertSame('email_failed', $row->invitation_status);
        $this->assertContains('Akun dibuat, tetapi email gagal dikirim.', $row->errors);
        $this->assertStringNotContainsString('SMTP', implode(' ', $row->errors));
        $this->assertSame('invited', $user->account_status->value);
    }

    public function test_assignment_failure_rolls_back_every_account_and_marks_sanitized_failed_result(): void
    {
        $actor = $this->superadmin();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $batch = $this->preview($actor, [
            ['Satu', 'rollback.satu@example.test', 'manager', 'Solo', '', '', '', '', 'pending_invitation'],
            ['Dua', 'rollback.dua@example.test', 'manager', 'Solo', '', '', '', '', 'pending_invitation'],
        ]);
        $this->app->instance(BranchAssignmentService::class, new class(app(AccountAuditService::class)) extends BranchAssignmentService
        {
            public function assign(User $user, array $assignments, ?int $primaryBranchId, ?User $actor = null): User
            {
                throw new RuntimeException('SQL private assignment detail');
            }
        });

        $this->actingAs($actor)->post(route('admin-users.import-confirm'), [
            'batch_id' => $batch->id,
            'expected_updated_at' => $batch->updated_at->toISOString(),
            'send_invitations' => '0',
        ])->assertSessionHasErrors(['batch_id' => 'Import gagal diproses. Tidak ada akun yang dibuat.']);

        $batch->refresh();
        $this->assertSame(UserImportBatch::STATUS_FAILED, $batch->status);
        $this->assertNotNull($batch->confirmed_at);
        $this->assertNotNull($batch->completed_at);
        $this->assertSame([0, 2], [$batch->created_rows, $batch->skipped_rows]);
        $this->assertSame(0, User::where('email', 'like', 'rollback.%@example.test')->count());
        $this->assertTrue($batch->rows->every(fn (UserImportRow $row) => $row->creation_status === 'failed'
            && implode(' ', $row->errors) === 'Import gagal diproses. Tidak ada akun dari batch ini yang dibuat.'));
        $this->assertStringNotContainsString('SQL', implode(' ', $batch->rows->flatMap->errors->all()));
    }

    private function preview(User $actor, array $rows): UserImportBatch
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('IMPORT USER');
        $sheet->fromArray(UserImportTemplateExport::HEADERS, null, 'A1');
        foreach ($rows as $index => $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValueExplicit([$column + 1, $index + 2], (string) $value, DataType::TYPE_STRING);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'execution-import-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        try {
            $this->actingAs($actor)->post(route('admin-users.import-preview'), [
                'file' => new UploadedFile($path, 'users.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])->assertSessionHasNoErrors();
        } finally {
            @unlink($path);
        }

        return UserImportBatch::latest('id')->firstOrFail();
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'superadmin')->value('id'),
            'password_changed_at' => now(),
        ]);
    }
}
