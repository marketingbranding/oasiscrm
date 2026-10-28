<?php

namespace Database\Seeders;

use App\Enums\AccountStatus;
use App\Enums\SalesLeadStatus;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesCoordinatorSales;
use App\Models\SalesLead;
use App\Models\SalesLeadAkadLink;
use App\Models\SalesLeadConsumerLink;
use App\Models\SalesLeadFreelanceLink;
use App\Models\SalesLeadSiteVisit;
use App\Models\SalesLeadSlikAttempt;
use App\Models\SalesLeadStatusHistory;
use App\Models\SalesLeadTarget;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class SalesCoordinatorWeekDemoSeeder extends Seeder
{
    private const MARKER = '[SC-DEMO]';

    private const COORDINATOR_EMAIL = 'demo.koordinator@oasis.local';

    private const SUPERVISOR_EMAIL = 'demo.supervisor@oasis.local';

    private const SALES_EMAIL_PREFIX = 'demo.sales.';

    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            BranchSeeder::class,
        ]);

        $branch = Branch::query()->where('code', 'MLG')->firstOrFail();
        $projects = $this->projects($branch);
        $supervisor = $this->demoUser(
            self::SUPERVISOR_EMAIL,
            'Demo Supervisor Sales',
            Role::query()->where('slug', 'supervisor')->firstOrFail(),
            $branch,
        );
        $coordinator = $this->demoUser(
            self::COORDINATOR_EMAIL,
            'Demo Koordinator Sales',
            Role::query()->where('slug', 'sales_coordinator')->firstOrFail(),
            $branch,
            $supervisor,
        );
        $sales = collect(range(1, 4))->map(
            fn (int $number): User => $this->demoUser(
                self::SALES_EMAIL_PREFIX.str_pad((string) $number, 2, '0', STR_PAD_LEFT).'@oasis.local',
                'Demo Sales '.str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                Role::query()->where('slug', 'sales')->firstOrFail(),
                $branch,
                $coordinator,
            ),
        );

        $this->assignProjects($sales, $projects);
        $this->assignTeam($coordinator, $sales);

        DB::transaction(function () use ($branch, $projects, $coordinator, $sales): void {
            $weekStart = CarbonImmutable::today()->subDays(6);

            $this->seedTargets($sales, $coordinator);
            $this->seedLeads($branch, $projects, $coordinator, $sales, $weekStart);
            $this->seedAgendas($branch, $projects, $coordinator, $sales, $weekStart);
        });

        $this->command?->info('SalesCoordinatorWeekDemoSeeder: 1 minggu data Sales → Koordinator berhasil dibuat.');
        $this->command?->info('Login Koordinator: '.self::COORDINATOR_EMAIL.' / password');
    }

    /** @return array<int, LeadMaster> */
    private function projects(Branch $branch): array
    {
        $projects = [];
        foreach (['Jabung Malang', 'Wagir', 'Demo MLG Residence'] as $name) {
            $projects[] = LeadMaster::query()->updateOrCreate(
                ['branch_id' => $branch->id, 'project_name' => $name],
                [
                    'sheet_project_name' => $name,
                    'category' => 'Demo Buku Saku Sales',
                    'is_active' => true,
                    'is_nup_eligible' => true,
                ],
            );
        }

        return $projects;
    }

    private function demoUser(
        string $email,
        string $name,
        Role $role,
        Branch $branch,
        ?User $supervisor = null,
    ): User {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'supervisor_user_id' => $supervisor?->id,
            'is_active' => true,
            'account_status' => AccountStatus::Active->value,
            'email_verified_at' => now(),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();

        return $user->fresh(['role', 'branch']);
    }

    /** @param Collection<int, User> $sales @param array<int, LeadMaster> $projects */
    private function assignProjects(Collection $sales, array $projects): void
    {
        foreach ($sales->values() as $salesIndex => $salesUser) {
            foreach ($projects as $projectIndex => $project) {
                if (($salesIndex + $projectIndex) % 2 !== 0) {
                    continue;
                }

                DB::table('project_user')->updateOrInsert(
                    ['user_id' => $salesUser->id, 'project_id' => $project->id],
                    [
                        'is_primary' => $projectIndex === $salesIndex % count($projects),
                        'assignment_start_date' => CarbonImmutable::today()->subDays(6)->toDateString(),
                        'assignment_end_date' => null,
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    /** @param Collection<int, User> $sales */
    private function assignTeam(User $coordinator, Collection $sales): void
    {
        $startDate = CarbonImmutable::today()->subDays(6)->toDateString();
        foreach ($sales as $salesUser) {
            SalesCoordinatorSales::query()->updateOrCreate(
                [
                    'coordinator_user_id' => $coordinator->id,
                    'sales_user_id' => $salesUser->id,
                ],
                [
                    'is_active' => true,
                    'started_at' => $startDate,
                    'ended_at' => null,
                ],
            );
        }
    }

    /** @param Collection<int, User> $sales */
    private function seedTargets(Collection $sales, User $coordinator): void
    {
        $month = CarbonImmutable::today()->startOfMonth();
        foreach ($sales->values() as $index => $salesUser) {
            SalesLeadTarget::query()->updateOrCreate(
                [
                    'sales_user_id' => $salesUser->id,
                    'period_year' => $month->year,
                    'period_month' => $month->month,
                ],
                [
                    'target_month' => $month->toDateString(),
                    'lead_target' => 20 + ($index * 5),
                    'target_leads' => 20 + ($index * 5),
                    'created_by' => $coordinator->id,
                    'updated_by' => $coordinator->id,
                ],
            );
        }
    }

    /** @param array<int, LeadMaster> $projects @param Collection<int, User> $sales */
    private function seedLeads(
        Branch $branch,
        array $projects,
        User $coordinator,
        Collection $sales,
        CarbonImmutable $weekStart,
    ): void {
        $statuses = SalesLeadStatus::PRIMARY_PRECEDENCE;
        $sources = ['Referensi', 'Instagram', 'TikTok', 'Pameran', 'Spanduk', 'Google Ads'];
        $platforms = ['WhatsApp', 'Telepon', 'Kunjungan Langsung'];
        $leads = [];

        foreach ($sales->values() as $salesIndex => $salesUser) {
            foreach (range(0, 6) as $dayIndex) {
                $sequence = ($salesIndex * 7) + $dayIndex;
                $status = $statuses[$sequence % count($statuses)];
                $leadDate = $weekStart->addDays($dayIndex);
                $lead = SalesLead::query()->updateOrCreate(
                    ['customer_name' => self::MARKER.' Lead S'.($salesIndex + 1).' D'.($dayIndex + 1)],
                    [
                        'branch_id' => $branch->id,
                        'project_id' => $projects[$sequence % count($projects)]->id,
                        'sales_user_id' => $salesUser->id,
                        'lead_date' => $leadDate->toDateString(),
                        'customer_name' => self::MARKER.' Lead S'.($salesIndex + 1).' D'.($dayIndex + 1),
                        'phone' => sprintf('081299%06d', $sequence + 1),
                        'normalized_phone' => '6281299'.str_pad((string) ($sequence + 1), 6, '0', STR_PAD_LEFT),
                        'source_name_snapshot' => $sources[$sequence % count($sources)],
                        'source' => $sources[$sequence % count($sources)],
                        'platform' => $platforms[$sequence % count($platforms)],
                        'campaign_name' => self::MARKER.' Campaign Minggu '.($dayIndex + 1),
                        'notes' => 'Data demo lokal untuk menguji alur Koordinator, follow-up, dan lifecycle Buku Saku Sales.',
                        'current_status' => $status->value,
                        'current_status_changed_at' => $leadDate->setTime(16, 0),
                        'current_status_source' => $status->isManual() ? 'demo_manual' : 'demo_lifecycle',
                        'current_status_source_id' => self::MARKER.'-'.$sequence,
                        'created_by' => $salesUser->id,
                        'updated_by' => $coordinator->id,
                        'sync_status' => 'local',
                        'external_sync_id' => 'sc-demo-lead-'.str_pad((string) ($sequence + 1), 3, '0', STR_PAD_LEFT),
                        ...$this->stageDates($status, $leadDate),
                        'consumer_converted_at' => $status->precedence() !== null && $status->precedence() >= SalesLeadStatus::Utj->precedence()
                            ? $leadDate->setTime(15, 0)
                            : null,
                        'freelance_converted_at' => $sequence === 5 ? $leadDate->setTime(14, 0) : null,
                    ],
                );
                $leads[] = [$lead, $status, $salesUser, $sequence, $leadDate];
            }
        }

        foreach ($leads as [$lead, $status, $salesUser, $sequence, $leadDate]) {
            $this->seedStatusHistory($lead, $branch, $salesUser, $status, $sequence, $leadDate);
            $this->seedSiteVisit($lead, $branch, $salesUser, $status, $sequence, $leadDate);
            $consumerLink = $this->seedConsumerLink($lead, $branch, $salesUser, $status, $sequence, $leadDate);
            $slikAttempt = $this->seedSlikAttempt($lead, $branch, $salesUser, $status, $sequence, $leadDate, $consumerLink);
            $this->seedFreelanceLink($lead, $branch, $salesUser, $coordinator, $sequence, $leadDate);
            $this->seedAkadLink($lead, $branch, $salesUser, $status, $sequence, $leadDate, $consumerLink, $slikAttempt);
        }
    }

    private function seedSiteVisit(
        SalesLead $lead,
        Branch $branch,
        User $actor,
        SalesLeadStatus $status,
        int $sequence,
        CarbonImmutable $leadDate,
    ): void {
        if (($status->precedence() ?? 0) < SalesLeadStatus::SiteVisit->precedence()) {
            return;
        }

        SalesLeadSiteVisit::query()->updateOrCreate(
            ['branch_id' => $branch->id, 'oasis_sync_id' => 'sc-demo-visit-'.$sequence],
            [
                'sales_lead_id' => $lead->id,
                'actor_id' => $actor->id,
                'operation_uuid' => (string) Str::uuid(self::MARKER.'-visit-'.$sequence),
                'status' => 'completed',
                'visited_at' => $leadDate->setTime(10, 0),
                'visit_date' => $leadDate->toDateString(),
                'visit_time' => '10:00',
                'visit_status' => 'completed',
                'outcome' => $status === SalesLeadStatus::SiteVisit ? 'tertarik' : 'lanjut proses',
                'notes' => 'Hasil kunjungan demo untuk menguji detail lifecycle lead.',
                'is_completed' => true,
                'metadata' => ['demo_marker' => self::MARKER],
            ],
        );
    }

    /** @return array<string, CarbonImmutable|null> */
    private function stageDates(SalesLeadStatus $status, CarbonImmutable $leadDate): array
    {
        $stageCount = match ($status) {
            SalesLeadStatus::NoResponse => 0,
            SalesLeadStatus::Discussion => 1,
            SalesLeadStatus::FaceToFace => 2,
            SalesLeadStatus::SiteVisit => 3,
            SalesLeadStatus::Utj => 4,
            SalesLeadStatus::SlikCheck, SalesLeadStatus::SlikRejected => 5,
            SalesLeadStatus::Akad => 6,
            SalesLeadStatus::Freelance => 1,
        };
        $stages = ['contacted_at', 'met_at', 'surveyed_at', 'utj_at', 'documents_completed_at', 'akad_at'];
        $dates = [];
        foreach ($stages as $index => $stage) {
            $dates[$stage] = $index < $stageCount ? $leadDate->setTime(9 + $index, 0) : null;
        }

        return $dates;
    }

    private function seedStatusHistory(
        SalesLead $lead,
        Branch $branch,
        User $actor,
        SalesLeadStatus $currentStatus,
        int $sequence,
        CarbonImmutable $leadDate,
    ): void {
        $statuses = SalesLeadStatus::PRIMARY_PRECEDENCE;
        $currentIndex = $currentStatus->precedence() ?? 0;
        foreach (array_slice($statuses, 0, $currentIndex + 1) as $statusIndex => $status) {
            SalesLeadStatusHistory::query()->updateOrCreate(
                [
                    'sales_lead_id' => $lead->id,
                    'source' => 'demo_lifecycle',
                    'source_id' => self::MARKER.'-'.$sequence.'-'.$status->value,
                    'status' => $status->value,
                ],
                [
                    'branch_id' => $branch->id,
                    'actor_id' => $actor->id,
                    'operation_uuid' => (string) Str::uuid(self::MARKER.'-history-'.$sequence.'-'.$status->value),
                    'changed_at' => $leadDate->setTime(9, $statusIndex * 10),
                    'metadata' => ['source' => 'local_demo'],
                ],
            );
        }

        if ($sequence === 5) {
            SalesLeadStatusHistory::query()->updateOrCreate(
                [
                    'sales_lead_id' => $lead->id,
                    'source' => 'demo_freelance',
                    'source_id' => self::MARKER.'-freelance-'.$sequence,
                    'status' => SalesLeadStatus::Freelance->value,
                ],
                [
                    'branch_id' => $branch->id,
                    'actor_id' => $actor->id,
                    'operation_uuid' => (string) Str::uuid(self::MARKER.'-freelance-history-'.$sequence),
                    'changed_at' => $leadDate->setTime(14, 0),
                    'metadata' => ['source' => 'local_demo'],
                ],
            );
        }
    }

    private function seedConsumerLink(
        SalesLead $lead,
        Branch $branch,
        User $actor,
        SalesLeadStatus $status,
        int $sequence,
        CarbonImmutable $leadDate,
    ): ?SalesLeadConsumerLink {
        if (($status->precedence() ?? 0) < SalesLeadStatus::Utj->precedence()) {
            return null;
        }

        return SalesLeadConsumerLink::query()->updateOrCreate(
            ['branch_id' => $branch->id, 'oasis_sync_id' => 'sc-demo-consumer-'.$sequence],
            [
                'sales_lead_id' => $lead->id,
                'actor_id' => $actor->id,
                'operation_uuid' => (string) Str::uuid(self::MARKER.'-consumer-'.$sequence),
                'status' => 'completed',
                'sheet_name' => 'data_konsumen',
                'sheet_type' => 'data_konsumen',
                'consumer_id' => 'SC-CONS-'.str_pad((string) ($sequence + 1), 3, '0', STR_PAD_LEFT),
                'consumer_reference' => self::MARKER.' Konsumen '.($sequence + 1),
                'nik' => '3573'.str_pad((string) ($sequence + 1), 12, '0', STR_PAD_LEFT),
                'id_kavling' => 'SC-DEMO-KAV-'.str_pad((string) ($sequence + 1), 2, '0', STR_PAD_LEFT),
                'converted_at' => $leadDate->setTime(15, 0),
                'payload' => ['source' => 'local_demo'],
                'metadata' => ['demo_marker' => self::MARKER],
            ],
        );
    }

    private function seedSlikAttempt(
        SalesLead $lead,
        Branch $branch,
        User $actor,
        SalesLeadStatus $status,
        int $sequence,
        CarbonImmutable $leadDate,
        ?SalesLeadConsumerLink $consumerLink,
    ): ?SalesLeadSlikAttempt {
        if (! in_array($status, [SalesLeadStatus::SlikCheck, SalesLeadStatus::SlikRejected, SalesLeadStatus::Akad], true)) {
            return null;
        }

        $isRejected = $status === SalesLeadStatus::SlikRejected;
        $result = $isRejected ? 'rejected' : ($status === SalesLeadStatus::Akad ? 'passed' : 'submitted');

        return SalesLeadSlikAttempt::query()->updateOrCreate(
            ['branch_id' => $branch->id, 'oasis_sync_id' => 'sc-demo-slik-'.$sequence],
            [
                'sales_lead_id' => $lead->id,
                'actor_id' => $actor->id,
                'consumer_link_id' => $consumerLink?->id,
                'operation_uuid' => (string) Str::uuid(self::MARKER.'-slik-'.$sequence),
                'status' => $isRejected ? 'rejected' : 'completed',
                'nik' => '3573'.str_pad((string) ($sequence + 1), 12, '0', STR_PAD_LEFT),
                'id_kavling' => 'SC-DEMO-KAV-'.str_pad((string) ($sequence + 1), 2, '0', STR_PAD_LEFT),
                'attempt_number' => 1,
                'checked_at' => $leadDate->setTime(13, 0),
                'slik_date' => $leadDate->toDateString(),
                'result' => $result,
                'slik_result' => $result,
                'notes' => $isRejected ? 'Data demo: penghasilan tidak memenuhi ketentuan.' : 'Data demo: pemeriksaan SLIK berjalan.',
                'rejected_at' => $isRejected ? $leadDate->setTime(14, 0) : null,
                'metadata' => ['demo_marker' => self::MARKER],
            ],
        );
    }

    private function seedFreelanceLink(
        SalesLead $lead,
        Branch $branch,
        User $actor,
        User $coordinator,
        int $sequence,
        CarbonImmutable $leadDate,
    ): void {
        if ($sequence !== 5) {
            return;
        }

        SalesLeadFreelanceLink::query()->updateOrCreate(
            ['branch_id' => $branch->id, 'oasis_sync_id' => 'sc-demo-freelance-'.$sequence],
            [
                'sales_lead_id' => $lead->id,
                'actor_id' => $actor->id,
                'coordinator_user_id' => $coordinator->id,
                'freelancer_user_id' => $actor->id,
                'operation_uuid' => (string) Str::uuid(self::MARKER.'-freelance-'.$sequence),
                'status' => 'completed',
                'freelance_id' => 'SC-FREELANCE-'.$sequence,
                'freelance_reference' => self::MARKER.' Freelance '.$sequence,
                'nik_sales' => '3573'.str_pad((string) ($sequence + 1), 12, '0', STR_PAD_LEFT),
                'sales_name' => $actor->name,
                'coordinator_name' => $coordinator->name,
                'converted_at' => $leadDate->setTime(14, 0),
                'metadata' => ['demo_marker' => self::MARKER],
            ],
        );
    }

    private function seedAkadLink(
        SalesLead $lead,
        Branch $branch,
        User $actor,
        SalesLeadStatus $status,
        int $sequence,
        CarbonImmutable $leadDate,
        ?SalesLeadConsumerLink $consumerLink,
        ?SalesLeadSlikAttempt $slikAttempt,
    ): void {
        if ($status !== SalesLeadStatus::Akad) {
            return;
        }

        SalesLeadAkadLink::query()->updateOrCreate(
            ['branch_id' => $branch->id, 'oasis_sync_id' => 'sc-demo-akad-'.$sequence],
            [
                'sales_lead_id' => $lead->id,
                'actor_id' => $actor->id,
                'consumer_link_id' => $consumerLink?->id,
                'slik_attempt_id' => $slikAttempt?->id,
                'operation_uuid' => (string) Str::uuid(self::MARKER.'-akad-'.$sequence),
                'status' => 'completed',
                'akad_id' => 'SC-AKAD-'.$sequence,
                'akad_reference' => self::MARKER.' Akad '.$sequence,
                'id_kavling' => 'SC-DEMO-KAV-'.str_pad((string) ($sequence + 1), 2, '0', STR_PAD_LEFT),
                'akad_at' => $leadDate->setTime(16, 0),
                'metadata' => ['demo_marker' => self::MARKER],
            ],
        );
    }

    /** @param array<int, LeadMaster> $projects @param Collection<int, User> $sales */
    private function seedAgendas(
        Branch $branch,
        array $projects,
        User $coordinator,
        Collection $sales,
        CarbonImmutable $weekStart,
    ): void {
        $agendaStatuses = ['planned', 'confirmed', 'done', 'cancelled', 'rescheduled'];
        $categories = ContentItem::SALES_ACTIVITY_CATEGORIES;

        foreach ($sales->values() as $salesIndex => $salesUser) {
            foreach (range(0, 6) as $dayIndex) {
                $date = $weekStart->addDays($dayIndex);
                ContentItem::query()->updateOrCreate(
                    ['title' => self::MARKER.' Agenda S'.($salesIndex + 1).' D'.($dayIndex + 1)],
                    [
                        'branch_id' => $branch->id,
                        'project_name' => $projects[($salesIndex + $dayIndex) % count($projects)]->project_name,
                        'sales_project_id' => $projects[($salesIndex + $dayIndex) % count($projects)]->id,
                        'item_type' => 'agenda',
                        'visibility' => 'team',
                        'agenda_type' => ContentItem::SALES_AGENDA_TYPE,
                        'title' => self::MARKER.' Agenda S'.($salesIndex + 1).' D'.($dayIndex + 1),
                        'sales_activity_category' => $categories[($salesIndex + $dayIndex) % count($categories)],
                        'location' => 'Proyek demo / cabang MLG',
                        'start_date' => $date->toDateString(),
                        'scheduled_date' => $date->toDateString(),
                        'deadline_date' => $date->toDateString(),
                        'start_time' => '10:00',
                        'end_time' => '12:00',
                        'duration_minutes' => 120,
                        'status' => $agendaStatuses[($salesIndex * 7 + $dayIndex) % count($agendaStatuses)],
                        'completed_at' => $dayIndex < 5 ? $date->setTime(12, 0) : null,
                        'activity_result' => $dayIndex < 5 ? 'Hasil agenda demo sudah dicatat.' : null,
                        'owner_user_id' => $salesUser->id,
                        'created_by' => $salesUser->id,
                        'updated_by' => $coordinator->id,
                        'notes' => 'Agenda demo untuk menguji monitoring Koordinator Sales.',
                    ],
                );
            }
        }
    }
}
