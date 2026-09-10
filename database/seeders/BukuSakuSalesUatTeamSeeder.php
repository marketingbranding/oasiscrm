<?php

namespace Database\Seeders;

use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local smoke/UAT team data: 4 sales under sales_coordinator@oasis.local,
 * each holding 10-50 leads dated 2026-09-01..2026-09-10.
 *
 * LOCAL ONLY - never run in production.
 * Leads use sync_status "local" so the coordinator push service never picks
 * them up for real spreadsheet writes. Re-running is safe (idempotent guard).
 */
class BukuSakuSalesUatTeamSeeder extends Seeder
{
    public function run(): void
    {
        if (User::query()->where('email', 'like', 'uat.sales%@oasis.local')->exists()) {
            $this->command->info('BukuSakuSalesUatTeamSeeder: UAT team already present, skipping.');

            return;
        }

        $coordinator = User::query()->where('email', 'sales_coordinator@oasis.local')->firstOrFail();
        $supervisor = User::query()->where('email', 'supervisor@oasis.local')->firstOrFail();
        $branchId = (int) $coordinator->branch_id;
        $projects = [37, 38, 39, 40, 41, 42, 43];
        $salesRoleId = User::query()->where('email', 'sales@oasis.local')->value('role_id');

        $plan = [
            ['short' => 'S1', 'leads' => 14],
            ['short' => 'S2', 'leads' => 21],
            ['short' => 'S3', 'leads' => 33],
            ['short' => 'S4', 'leads' => 47],
        ];
        $statuses = ['no_response', 'discussion', 'face_to_face', 'site_visit'];
        $sources = ['Referensi', 'Pameran', 'TikTok', 'Instagram', 'Spanduk'];
        $platforms = ['WhatsApp', 'Telepon', 'Kunjungan Langsung'];
        $activities = ['Chat WhatsApp', 'Telepon', 'Kunjungan', 'Live TikTok', 'Pameran Perumahan'];

        foreach ($plan as $index => $row) {
            $sales = User::query()->create([
                'name' => 'UAT Sales '.$row['short'],
                'email' => 'uat.sales'.($index + 1).'@oasis.local',
                'password' => Hash::make('password'),
                'role_id' => $salesRoleId,
                'branch_id' => $branchId,
                'is_active' => true,
                'account_status' => 'active',
                'password_changed_at' => now(),
                'must_change_password' => false,
                'supervisor_user_id' => $supervisor->id,
            ]);
            // email_verified_at is not mass-assignable on User.
            $sales->forceFill(['email_verified_at' => now()])->save();

            $sales->assignedProjects()->attach($projects[$index % count($projects)], [
                'is_primary' => true,
                'is_active' => true,
            ]);
            SalesCoordinatorSales::query()->updateOrCreate(
                ['coordinator_user_id' => $coordinator->id, 'sales_user_id' => $sales->id],
                ['is_active' => true],
            );

            foreach (range(1, $row['leads']) as $i) {
                $leadDate = today()->subDays(($i - 1) % 10)->toDateString(); // 2026-09-01..2026-09-10
                SalesLead::query()->create([
                    'branch_id' => $branchId,
                    'project_id' => $projects[($i - 1) % count($projects)],
                    'sales_user_id' => $sales->id,
                    'lead_date' => $leadDate,
                    'customer_name' => sprintf('UAT %s Konsumen %02d', $row['short'], $i),
                    'phone' => sprintf('0813%d%06d', $index + 1, $i),
                    'source' => $sources[($i - 1) % count($sources)],
                    'platform' => $platforms[($i - 1) % count($platforms)],
                    'campaign_name' => $activities[($i - 1) % count($activities)],
                    'notes' => 'Data UAT tim koordinator.',
                    'current_status' => $statuses[($i - 1) % count($statuses)],
                    'current_status_changed_at' => $leadDate,
                    'current_status_source' => 'manual',
                    'current_status_source_id' => (string) $sales->id,
                    'sync_status' => 'local',
                    'created_by' => $sales->id,
                ]);
            }
        }

        $this->command->info('BukuSakuSalesUatTeamSeeder: 4 sales + 115 leads created.');
    }
}
