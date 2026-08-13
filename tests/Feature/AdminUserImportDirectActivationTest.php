<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserImportBatch;
use App\Models\UserInvitation;
use App\Policies\UserImportBatchPolicy;
use App\Services\AccountAuditService;
use App\Services\BranchAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Tests\Concerns\CreatesUserImportWorkbooks;
use Tests\TestCase;

class AdminUserImportDirectActivationTest extends TestCase
{
    use CreatesUserImportWorkbooks;
    use RefreshDatabase;

    public function test_omitted_activation_mode_preserves_invitation_flow_for_legacy_and_current_workbooks(): void
    {
        Notification::fake();
        $actor = $this->importActor();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);

        foreach ([
            ['Legacy', 'legacy.invite@example.test', 'manager', 'Solo', '', '', '', '', 'invited'],
            ['Current', 'current.invite@example.test', 'manager', 'Solo', '', '', '', '', 'invited', ''],
        ] as $values) {
            $this->uploadImport($actor, [$values])->assertSessionHasNoErrors();
            $batch = UserImportBatch::latest('id')->firstOrFail();
            $this->confirm($actor, $batch)->assertSessionHasNoErrors();

            $user = User::where('email', $values[1])->firstOrFail();
            $this->assertSame(UserImportBatch::ACTIVATION_MODE_INVITATION, $batch->fresh()->activation_mode);
            $this->assertSame(AccountStatus::Invited, $user->account_status);
            $this->assertTrue(UserInvitation::where('user_id', $user->id)->exists());
            $this->assertNull($batch->fresh()->credential_payload);
        }
    }

    public function test_primary_superadmin_directly_activates_batch_with_assignments_and_secure_credentials(): void
    {
        Notification::fake();
        $actor = $this->importActor();
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $supervisor = User::factory()->create(['role_id' => Role::where('slug', 'supervisor')->value('id'), 'password_changed_at' => now()]);
        $coordinator = User::factory()->create(['role_id' => Role::where('slug', 'sales_coordinator')->value('id'), 'password_changed_at' => now()]);
        foreach ([$supervisor, $coordinator] as $existing) {
            $existing->branches()->attach($branch->id, ['can_view' => true]);
            $existing->forceFill(['branch_id' => $branch->id])->save();
        }
        $rows = [
            ['Sales Assigned', 'direct.sales@example.test', 'sales', 'Solo', '', 'Oasis Solo', '', $supervisor->email, 'pending_invitation', $coordinator->email],
            ['Sales Empty', 'direct.empty@example.test', 'sales', 'Solo', '', 'Oasis Solo', '', $supervisor->email, 'pending_invitation', ''],
        ];
        $this->uploadImport($actor, $rows)->assertSessionHasNoErrors();
        $batch = UserImportBatch::firstOrFail();

        $this->confirm($actor, $batch, UserImportBatch::ACTIVATION_MODE_DIRECT)->assertSessionHasNoErrors();

        $batch->refresh();
        $credentials = $batch->credential_payload;
        $this->assertCount(2, $credentials);
        foreach ($rows as $index => $values) {
            $user = User::where('email', $values[1])->firstOrFail();
            $this->assertSame(AccountStatus::Active, $user->account_status);
            $this->assertTrue($user->is_active);
            $this->assertNotNull($user->email_verified_at);
            $this->assertTrue($user->must_change_password);
            $this->assertNull($user->password_changed_at);
            $this->assertTrue(Hash::check($credentials[$index]['temporary_password'], $user->password));
            $this->assertSame($branch->id, $user->branch_id);
            $this->assertSame($supervisor->id, $user->supervisor_user_id);
            $this->assertTrue($user->assignedProjects()->whereKey($project->id)->wherePivot('is_primary', true)->exists());
            $this->assertFalse(UserInvitation::where('user_id', $user->id)->exists());
        }
        $assigned = User::where('email', 'direct.sales@example.test')->firstOrFail();
        $empty = User::where('email', 'direct.empty@example.test')->firstOrFail();
        $this->assertTrue($assigned->currentSalesCoordinators()->whereKey($coordinator->id)->exists());
        $this->assertFalse($empty->currentSalesCoordinators()->exists());
        Notification::assertNothingSent();
        $this->assertSame(1, ActivityLog::where('subject_type', UserImportBatch::class)->where('subject_id', $batch->id)->where('event', 'user_import_direct_activation_completed')->count());
        $this->assertSame(2, ActivityLog::where('event', 'user_directly_activated_bulk')->whereIn('subject_id', [$assigned->id, $empty->id])->count());

        $plaintext = $credentials[0]['temporary_password'];
        $rawBatch = DB::table('user_import_batches')->where('id', $batch->id)->first();
        $this->assertStringNotContainsString($plaintext, (string) $rawBatch->credential_payload);
        $this->assertStringNotContainsString($plaintext, DB::table('user_import_rows')->where('batch_id', $batch->id)->get()->toJson());
        $this->assertStringNotContainsString($plaintext, ActivityLog::where('subject_id', $batch->id)->orWhereIn('subject_id', [$assigned->id, $empty->id])->get()->toJson());
    }

    public function test_temporary_password_requires_change_before_protected_access(): void
    {
        $actor = $this->importActor();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $this->uploadImport($actor, [['Direct Manager', 'login.direct@example.test', 'manager', 'Solo', '', '', '', '', 'pending_invitation', '']]);
        $batch = UserImportBatch::firstOrFail();
        $this->confirm($actor, $batch, UserImportBatch::ACTIVATION_MODE_DIRECT)->assertSessionHasNoErrors();
        $password = $batch->fresh()->credential_payload[0]['temporary_password'];
        $this->post(route('logout'));

        $this->post('/login', ['email' => 'login.direct@example.test', 'password' => $password])->assertRedirect(route('password.change'));
        $user = User::where('email', 'login.direct@example.test')->firstOrFail();
        $this->get(route($user->landingRouteName()))->assertRedirect(route('password.change'));
        $this->put(route('password.change.update'), ['password' => 'ChangedPassword123', 'password_confirmation' => 'ChangedPassword123'])
            ->assertRedirect(route($user->landingRouteName()));
        $this->get(route($user->landingRouteName()))->assertOk();
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_non_superadmin_and_supplemental_superadmin_cannot_forge_direct_mode(): void
    {
        $role = Role::where('slug', 'staff')->firstOrFail();
        $role->permissions()->sync(Permission::whereIn('slug', UserImportBatchPolicy::REQUIRED_PERMISSIONS)->pluck('id'));
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);

        foreach ([false, true] as $supplemental) {
            $actor = $this->importActor('staff');
            if ($supplemental) {
                $actor->roles()->attach(Role::where('slug', 'superadmin')->firstOrFail());
            }
            $email = $supplemental ? 'supplemental@example.test' : 'staff@example.test';
            $this->uploadImport($actor, [['Forged', $email, 'manager', $branch->name, '', '', '', '', 'pending_invitation', '']]);
            $batch = UserImportBatch::latest('id')->firstOrFail();
            $this->confirm($actor, $batch, UserImportBatch::ACTIVATION_MODE_DIRECT)->assertForbidden();
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public function test_existing_email_is_untouched_and_assignment_failure_rolls_back_users_and_credentials(): void
    {
        $actor = $this->importActor();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $existing = User::factory()->create(['email' => 'existing@example.test', 'name' => 'Existing', 'password' => Hash::make('Original123'), 'password_changed_at' => now()]);
        $this->uploadImport($actor, [['Replacement', $existing->email, 'manager', 'Solo', '', '', '', '', 'pending_invitation', '']]);
        $invalid = UserImportBatch::firstOrFail();
        $this->assertSame(UserImportBatch::STATUS_VALIDATION_FAILED, $invalid->status);
        $this->assertSame('Existing', $existing->fresh()->name);
        $this->assertTrue(Hash::check('Original123', $existing->fresh()->password));

        $this->uploadImport($actor, [['Rollback', 'rollback.batch@example.test', 'manager', 'Solo', '', '', '', '', 'pending_invitation', '']]);
        $batch = UserImportBatch::latest('id')->firstOrFail();
        $this->app->instance(BranchAssignmentService::class, new class(app(AccountAuditService::class)) extends BranchAssignmentService
        {
            public function assign(User $user, array $assignments, ?int $primaryBranchId, ?User $actor = null): User
            {
                throw new RuntimeException('Injected assignment failure');
            }
        });
        $this->confirm($actor, $batch, UserImportBatch::ACTIVATION_MODE_DIRECT)->assertSessionHasErrors('batch_id');
        $this->assertDatabaseMissing('users', ['email' => 'rollback.batch@example.test']);
        $this->assertNull($batch->fresh()->credential_payload);
        $this->assertSame(0, $batch->fresh()->created_rows);
    }

    public function test_signed_credentials_are_uploader_only_single_use_expiring_xlsx_and_audited_once(): void
    {
        $actor = $this->importActor();
        $other = $this->importActor();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $this->uploadImport($actor, [['Download User', 'download@example.test', 'manager', 'Solo', '', '', '', '', 'pending_invitation', '']]);
        $batch = UserImportBatch::firstOrFail();
        $this->confirm($actor, $batch, UserImportBatch::ACTIVATION_MODE_DIRECT)->assertSessionHasNoErrors();
        $credential = $batch->fresh()->credential_payload[0];
        $url = URL::temporarySignedRoute('admin-users.import-credentials', now()->addMinutes(10), $batch);

        $this->actingAs($other)->get($url)->assertNotFound();
        $response = $this->actingAs($actor)->get($url);
        $response->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'credential-test-');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $this->assertSame('KREDENSIAL USER', $sheet->getTitle());
            $this->assertSame(['Nama', 'Email', 'Role', 'Cabang Utama', 'Password Sementara'], $sheet->rangeToArray('A1:E1')[0]);
            $this->assertSame([$credential['name'], $credential['email'], $credential['role'], $credential['primary_branch'], $credential['temporary_password']], $sheet->rangeToArray('A2:E2')[0]);
            foreach (range('A', 'E') as $column) {
                $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($column.'2')->getDataType());
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            @unlink($path);
        }
        $batch->refresh();
        $this->assertNull($batch->credential_payload);
        $this->assertNotNull($batch->credential_downloaded_at);
        $this->actingAs($actor)->get($url)->assertNotFound();
        $this->assertSame(1, ActivityLog::where('subject_type', UserImportBatch::class)->where('subject_id', $batch->id)->where('event', 'user_import_credentials_downloaded')->count());

        $batch->update(['credential_payload' => [$credential], 'credential_downloaded_at' => null, 'credential_expires_at' => now()->subSecond()]);
        $expiredUrl = URL::temporarySignedRoute('admin-users.import-credentials', now()->addMinutes(10), $batch);
        $this->actingAs($actor)->get($expiredUrl)->assertGone();
    }

    private function confirm(User $actor, UserImportBatch $batch, ?string $activationMode = null)
    {
        $payload = [
            'batch_id' => $batch->id,
            'expected_updated_at' => $batch->fresh()->updated_at->toISOString(),
            'send_invitations' => '0',
        ];
        if ($activationMode !== null) {
            $payload['activation_mode'] = $activationMode;
        }

        return $this->actingAs($actor)->post(route('admin-users.import-confirm'), $payload);
    }
}
