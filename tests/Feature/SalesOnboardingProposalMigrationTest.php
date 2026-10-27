<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Models\Branch;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesOnboardingProposal;
use App\Models\User;
use App\Services\SalesOnboardingProposalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesOnboardingProposalMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_preserves_duplicate_pending_rows_and_retains_one_marker_deterministically(): void
    {
        [$admin, $branch, $project, $coordinator] = $this->context();
        $this->removePendingEmailColumnAndIndex();
        $proposalIds = $this->insertDuplicatePendingProposals($admin, $branch, $project, $coordinator);

        $migration = require database_path('migrations/2026_10_27_125450_add_pending_email_to_sales_onboarding_proposals_table.php');
        $migration->up();

        $rows = DB::table('sales_onboarding_proposals')->whereIn('id', $proposalIds)->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame('duplicate@example.test', $rows[0]->pending_email);
        $this->assertNull($rows[1]->pending_email);
        $this->assertNull($rows[2]->pending_email);
        $this->assertSame(3, $rows->where('status', SalesOnboardingProposal::STATUS_PENDING)->count());
        $migration->up();

        $service = app(SalesOnboardingProposalService::class);
        $payload = [
            'name' => 'Replacement Sales',
            'email' => ' DUPLICATE@EXAMPLE.TEST ',
            'phone' => '08123456789',
            'role_id' => Role::where('slug', 'sales')->value('id'),
            'branch_id' => $branch->id,
            'project_id' => $project->id,
            'coordinator_user_id' => $coordinator->id,
        ];

        try {
            $service->create($payload, $admin);
            $this->fail('An active pending marker must block a replacement proposal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('proposal', $exception->errors());
        }

        $activeProposal = SalesOnboardingProposal::findOrFail($proposalIds[0]);
        $superadmin = User::factory()->create([
            'role_id' => Role::where('slug', 'superadmin')->value('id'),
            'account_status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);
        $service->reject($activeProposal, $superadmin, $activeProposal->decision_token, 'Duplikat lama ditinjau.');

        $replacement = $service->create($payload, $admin);
        $this->assertSame(SalesOnboardingProposal::STATUS_PENDING, $replacement->status);
        $this->assertSame('duplicate@example.test', $replacement->pending_email);
        $this->assertDatabaseHas('sales_onboarding_proposals', [
            'id' => $proposalIds[1],
            'status' => SalesOnboardingProposal::STATUS_PENDING,
            'pending_email' => null,
        ]);
    }

    private function removePendingEmailColumnAndIndex(): void
    {
        Schema::table('sales_onboarding_proposals', function (Blueprint $table): void {
            $table->dropUnique('sales_onboarding_proposals_pending_email_unique');
            $table->dropColumn('pending_email');
        });
    }

    /** @return array{0: User, 1: Branch, 2: LeadMaster, 3: User} */
    private function context(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $project = LeadMaster::create(['branch_id' => $branch->id, 'project_name' => 'Oasis Solo', 'is_active' => true]);
        $admin = User::factory()->create([
            'role_id' => Role::where('slug', 'admin')->value('id'),
            'branch_id' => $branch->id,
            'account_status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);
        $coordinator = User::factory()->create([
            'role_id' => Role::where('slug', 'sales_coordinator')->value('id'),
            'branch_id' => $branch->id,
            'account_status' => AccountStatus::Active,
            'password_changed_at' => now(),
        ]);

        return [$admin, $branch, $project, $coordinator];
    }

    /** @return array<int, int> */
    private function insertDuplicatePendingProposals(User $admin, Branch $branch, LeadMaster $project, User $coordinator): array
    {
        $ids = [];
        foreach ([' Duplicate@Example.test ', 'duplicate@example.test', 'DUPLICATE@EXAMPLE.TEST'] as $email) {
            $ids[] = DB::table('sales_onboarding_proposals')->insertGetId([
                'name' => 'Existing Sales',
                'email' => $email,
                'phone' => null,
                'role_id' => Role::where('slug', 'sales')->value('id'),
                'branch_id' => $branch->id,
                'project_id' => $project->id,
                'coordinator_user_id' => $coordinator->id,
                'submitted_by' => $admin->id,
                'submitted_scope' => json_encode([
                    'branch_id' => $branch->id,
                    'project_id' => $project->id,
                    'coordinator_user_id' => $coordinator->id,
                ], JSON_THROW_ON_ERROR),
                'submitted_at' => now(),
                'status' => SalesOnboardingProposal::STATUS_PENDING,
                'decision_token' => str()->uuid()->toString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }
}
