<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FeedbackReport;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FeedbackReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_is_persisted_when_discord_is_disabled(): void
    {
        [$branch, $user] = $this->branchAndUser();
        config(['services.feedback_discord.enabled' => false]);

        $this->actingAs($user)->postJson(route('feedback-reports.store'), $this->bugPayload($branch))
            ->assertCreated()->assertJsonPath('ok', true);

        $report = FeedbackReport::firstOrFail();
        $this->assertSame($user->id, $report->user_id);
        $this->assertSame($branch->id, $report->branch_id);
        $this->assertSame('Database', $report->module);
        $this->assertSame('dashboard', $report->route_name);
        $this->assertSame(url('/dashboard'), $report->page_url);
        $this->assertSame('Chrome / Windows', $report->user_agent_summary);
    }

    public function test_discord_failure_does_not_fail_or_delete_local_report(): void
    {
        [$branch, $user] = $this->branchAndUser();
        config(['services.feedback_discord.enabled' => true, 'services.feedback_discord.webhook_url' => 'https://discord.example.invalid/webhook']);
        Http::fake(['*' => Http::response('provider body must not be logged', 500)]);

        $this->actingAs($user)->postJson(route('feedback-reports.store'), $this->bugPayload($branch))->assertCreated();
        $this->assertSame(1, FeedbackReport::count());
    }

    public function test_legacy_webhook_and_insecure_ssl_path_are_removed(): void
    {
        $source = collect([
            file_get_contents(app_path('Services/FeedbackDiscordService.php')),
            file_get_contents(base_path('routes/web.php')),
            file_get_contents(base_path('bootstrap/app.php')),
        ])->implode("\n");

        $this->assertStringNotContainsString('discord.com/api/webhooks/', $source);
        $this->assertStringNotContainsString('CURLOPT_SSL_VERIFYPEER', $source);
        $this->assertNull(Route::getRoutes()->getByName('bug-report.store'));
    }

    public function test_user_cannot_submit_for_inaccessible_branch_and_read_only_user_can_submit_for_accessible_branch(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $other = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $user->branches()->updateExistingPivot($branch->id, ['can_view' => true, 'can_edit' => false]);

        $this->actingAs($user)->postJson(route('feedback-reports.store'), $this->bugPayload($other))->assertForbidden();
        $this->actingAs($user)->postJson(route('feedback-reports.store'), $this->bugPayload($branch))->assertCreated();
    }

    public function test_user_history_contains_only_own_reports(): void
    {
        [$branch, $user] = $this->branchAndUser();
        $other = User::factory()->create(['role_id' => $user->role_id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        FeedbackReport::create($this->modelPayload($branch, $user, 'Milik Saya'));
        FeedbackReport::create($this->modelPayload($branch, $other, 'Milik Orang Lain'));

        $response = $this->actingAs($user)->getJson(route('feedback-reports.history'))
            ->assertOk()->assertJsonCount(1, 'reports')->assertJsonPath('reports.0.title', 'Milik Saya');
        $this->assertStringNotContainsString('Milik Orang Lain', $response->getContent());
    }

    public function test_superadmin_can_review_but_branch_user_cannot(): void
    {
        [$branch, $reporter] = $this->branchAndUser();
        $super = $this->superadmin();
        $report = FeedbackReport::create($this->modelPayload($branch, $reporter));

        $this->actingAs($reporter)->get(route('feedback-reports.index'))->assertForbidden();
        $this->actingAs($reporter)->patch(route('feedback-reports.review', $report), $this->reviewPayload())->assertForbidden();
        $this->actingAs($super)->get(route('feedback-reports.index'))->assertOk();
        $this->actingAs($super)->patch(route('feedback-reports.review', $report), $this->reviewPayload())
            ->assertRedirect(route('feedback-reports.show', $report));
        $this->assertSame('reviewing', $report->fresh()->status);
    }

    public function test_status_and_assignment_changes_create_private_notifications(): void
    {
        [$branch, $reporter] = $this->branchAndUser();
        $super = $this->superadmin();
        $assignee = User::factory()->create(['role_id' => $reporter->role_id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $report = FeedbackReport::create($this->modelPayload($branch, $reporter));

        $this->actingAs($super)->patch(route('feedback-reports.review', $report), $this->reviewPayload([
            'status' => 'rejected', 'assigned_to' => $assignee->id, 'admin_note' => 'Belum sesuai kebutuhan.',
        ]))->assertRedirect();

        $this->assertDatabaseHas('user_notifications', ['user_id' => $reporter->id, 'type' => 'feedback_status_changed']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $assignee->id, 'type' => 'feedback_assigned']);
        $this->assertStringContainsString('Belum sesuai', UserNotification::where('user_id', $reporter->id)->latest()->value('message'));
    }

    public function test_notification_failure_does_not_roll_back_review(): void
    {
        [$branch, $reporter] = $this->branchAndUser();
        $super = $this->superadmin();
        $report = FeedbackReport::create($this->modelPayload($branch, $reporter));
        Schema::drop('user_notifications');

        $this->actingAs($super)->patch(route('feedback-reports.review', $report), $this->reviewPayload())->assertRedirect();
        $this->assertSame('reviewing', $report->fresh()->status);
    }

    public function test_screenshot_is_private_validated_and_authorized(): void
    {
        Storage::fake('local');
        [$branch, $user] = $this->branchAndUser();
        $other = User::factory()->create(['role_id' => $user->role_id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $payload = $this->bugPayload($branch);
        $payload['screenshot'] = UploadedFile::fake()->image('error.png', 800, 600)->size(500);

        $this->actingAs($user)->post(route('feedback-reports.store'), $payload, ['Accept' => 'application/json'])->assertCreated();
        $report = FeedbackReport::firstOrFail();
        Storage::disk('local')->assertExists($report->screenshot_path);
        $this->assertStringNotContainsString('storage/', route('feedback-reports.screenshot', $report));
        $this->actingAs($other)->get(route('feedback-reports.screenshot', $report))->assertForbidden();
        $this->actingAs($user)->get(route('feedback-reports.screenshot', $report))->assertOk();

        $invalid = $this->bugPayload($branch);
        $invalid['screenshot'] = UploadedFile::fake()->create('shell.php', 10, 'application/x-php');
        $this->actingAs($user)->post(route('feedback-reports.store'), $invalid)->assertSessionHasErrors('screenshot');
    }

    public function test_existing_statuses_remain_supported(): void
    {
        foreach (['approved', 'rejected', 'implemented', 'fixed'] as $status) {
            $report = new FeedbackReport(['status' => $status]);
            $this->assertNotSame('Menunggu', $report->statusLabel());
        }
    }

    private function bugPayload(Branch $branch): array
    {
        return [
            'type' => 'bug', 'branch_id' => $branch->id, 'module' => 'Database', 'title' => 'Data tidak tampil',
            'description' => 'Tabel tidak menampilkan data.', 'activity' => 'Membuka tab Leads',
            'actual_result' => 'Tabel kosong', 'expected_result' => 'Data terlihat', 'reproduction_frequency' => 'selalu',
            'page_url' => url('/dashboard').'?customer=secret', 'route_name' => 'dashboard',
            'user_agent_summary' => 'Chrome / Windows', 'screen_size' => '1920x1080',
        ];
    }

    private function modelPayload(Branch $branch, User $user, string $title = 'Laporan'): array
    {
        return [
            'user_id' => $user->id, 'branch_id' => $branch->id, 'type' => 'bug', 'module' => 'Database',
            'title' => $title, 'description' => 'Masalah', 'status' => 'pending', 'priority' => 'medium',
        ];
    }

    private function reviewPayload(array $overrides = []): array
    {
        return array_merge(['status' => 'reviewing', 'priority' => 'high', 'assigned_to' => null, 'admin_note' => 'Sedang diperiksa.'], $overrides);
    }

    private function branchAndUser(): array
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);

        return [$branch, $user];
    }

    private function superadmin(): User
    {
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Superadmin', 'is_superadmin' => true]);

        return User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);
    }
}
