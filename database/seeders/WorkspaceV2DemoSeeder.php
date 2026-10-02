<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\ConsumerAkadRecord;
use App\Models\ConsumerApplication;
use App\Models\ConsumerBankProcess;
use App\Models\ConsumerBastRecord;
use App\Models\ConsumerIssue;
use App\Models\ConsumerKavlingAssignment;
use App\Models\ConsumerNup;
use App\Models\ConsumerPpjbDeveloper;
use App\Models\ConsumerProcessApplicability;
use App\Models\ConsumerPsjb;
use App\Models\ConsumerStageEvent;
use App\Models\ConsumerWarranty;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\Role;
use App\Models\SalesLead;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class WorkspaceV2DemoSeeder extends Seeder
{
    private const MARKER = '[W2-DEMO]';

    public function run(): void
    {
        $this->assertSafeEnvironment();

        DB::transaction(function (): void {
            $branches = $this->demoBranches();
            $projects = $this->demoProjects($branches);
            $sales = $this->demoSales($branches);
            $kavlings = $this->demoKavlings($projects);

            $this->clearExistingApplications();
            $this->demoApplications($branches, $projects, $sales, $kavlings);

            $this->demoWaitingList($branches, $projects);
            $this->demoLeads($branches, $projects, $sales);
        });

        $this->command?->info('Workspace V2 demo data seeded locally: 28 transactions, 3 NUP, 5 leads.');
    }

    private function assertSafeEnvironment(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'sqlite') {
            throw new LogicException('Workspace V2 demo data is restricted to local/testing SQLite environments.');
        }
    }

    /** @return array<string, Branch> */
    private function demoBranches(): array
    {
        $definitions = [
            'W2-DEMO-MGL' => 'Demo Magelang',
            'W2-DEMO-SLM' => 'Demo Sleman',
            'W2-DEMO-BKS' => 'Demo Bekasi',
        ];

        $branches = [];
        foreach ($definitions as $code => $name) {
            $branches[$code] = Branch::query()->updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'is_active' => true, 'address' => 'Alamat demo lokal OASIS'],
            );
        }

        return $branches;
    }

    /** @param array<string, Branch> $branches @return array<string, LeadMaster> */
    private function demoProjects(array $branches): array
    {
        $projects = [];
        foreach ($branches as $branchCode => $branch) {
            foreach (['A' => 'Demo Asri', 'B' => 'Demo Harmoni'] as $suffix => $name) {
                $key = $branchCode.'-'.$suffix;
                $projects[$key] = LeadMaster::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'project_name' => $name],
                    ['is_active' => true, 'is_nup_eligible' => true, 'category' => 'Workspace V2 Demo'],
                );
            }
        }

        return $projects;
    }

    /** @param array<string, Branch> $branches @return array<int, User> */
    private function demoSales(array $branches): array
    {
        $salesRole = Role::query()->where('slug', 'sales')->first() ?? Role::query()->firstOrFail();
        $sales = [];
        foreach (array_values($branches) as $index => $branch) {
            $email = 'workspace-v2-demo-sales-'.($index + 1).'@example.test';
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill([
                'name' => 'Sales Demo '.($index + 1),
                'password' => 'workspace-v2-demo-only',
                'role_id' => $salesRole->id,
                'branch_id' => $branch->id,
                'is_active' => true,
                'account_status' => 'active',
                'email_verified_at' => now(),
                'password_changed_at' => now(),
                'must_change_password' => false,
            ])->save();
            $sales[] = $user->fresh();
        }

        return $sales;
    }

    /** @param array<string, LeadMaster> $projects @return array<string, Kavling> */
    private function demoKavlings(array $projects): array
    {
        $kavlings = [];
        foreach (array_values($projects) as $projectIndex => $project) {
            for ($number = 1; $number <= 10; $number++) {
                $code = 'W2-'.($projectIndex + 1).'-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
                $kavlings[$code] = Kavling::query()->updateOrCreate(
                    ['project_id' => $project->id, 'kavling_code' => $code],
                    ['name' => 'Rumah Demo '.$code],
                );
            }
        }

        return $kavlings;
    }

    private function clearExistingApplications(): void
    {
        $ids = ConsumerApplication::withTrashed()
            ->where('notes', 'like', self::MARKER.'%')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        foreach ([
            'consumer_kavling_assignments', 'consumer_process_applicabilities', 'consumer_issues',
            'consumer_warranties', 'consumer_documents', 'consumer_stage_events', 'consumer_psjbs',
            'consumer_bank_processes', 'consumer_ppjb_developers', 'consumer_akad_records',
            'consumer_bast_records', 'consumer_legacy_identities',
        ] as $table) {
            DB::table($table)->whereIn('consumer_application_id', $ids)->delete();
        }

        DB::table('consumer_nups')->whereIn('converted_application_id', $ids)->update(['converted_application_id' => null]);
        ConsumerApplication::withTrashed()->whereIn('id', $ids)->forceDelete();
    }

    /** @param array<string, Branch> $branches @param array<string, LeadMaster> $projects @param array<int, User> $sales @param array<string, Kavling> $kavlings @return array<string, ConsumerApplication> */
    private function demoApplications(array $branches, array $projects, array $sales, array $kavlings): array
    {
        $scenarios = [
            ['key' => 'data', 'name' => 'Alya Demo Data Konsumen', 'process' => 'data_konsumen', 'status' => 'draft', 'payment' => 'kpr'],
            ['key' => 'psjb', 'name' => 'Bima Demo PSJB', 'process' => 'psjb', 'status' => 'Lanjut', 'payment' => 'kpr'],
            ['key' => 'bi', 'name' => 'Citra Demo BI Checking', 'process' => 'bi_checking', 'status' => 'Lanjut', 'payment' => 'kpr'],
            ['key' => 'berkas', 'name' => 'Danu Demo Pemberkasan', 'process' => 'pemberkasan', 'status' => 'Lanjut', 'payment' => 'kpr'],
            ['key' => 'bank-pending', 'name' => 'Eka Demo Proses Bank Tanpa SP3K', 'process' => 'proses_bank', 'status' => 'Lanjut', 'payment' => 'kpr', 'bank' => 'BTN'],
            ['key' => 'bank-sp3k', 'name' => 'Fajar Demo Proses Bank SP3K', 'process' => 'proses_bank', 'status' => 'Lanjut', 'payment' => 'kpr', 'bank' => 'BSI', 'sp3k' => true],
            ['key' => 'ganti-bank', 'name' => 'Gita Demo Ganti Bank', 'process' => 'proses_bank', 'status' => 'Lanjut', 'payment' => 'kpr', 'multiple_banks' => true],
            ['key' => 'ppjb', 'name' => 'Hana Demo PPJB Dev', 'process' => 'ppjb_dev', 'status' => 'Lanjut', 'payment' => 'kpr'],
            ['key' => 'akad', 'name' => 'Indra Demo Akad', 'process' => 'akad', 'status' => 'Lanjut', 'payment' => 'kpr'],
            ['key' => 'bast', 'name' => 'Joko Demo BAST', 'process' => 'bast', 'status' => 'Lanjut', 'payment' => 'kpr'],
            ['key' => 'garansi-proses', 'name' => 'Karin Demo Garansi Proses', 'process' => 'garansi', 'status' => 'Lanjut', 'payment' => 'kpr', 'warranty' => 'Proses'],
            ['key' => 'garansi-none', 'name' => 'Laras Demo Garansi Tidak Ada Komplain', 'process' => 'garansi', 'status' => 'Lanjut', 'payment' => 'kpr', 'warranty' => 'Tidak Ada Komplain'],
            ['key' => 'selesai', 'name' => 'Miko Demo Selesai', 'process' => 'selesai', 'status' => 'Lanjut', 'payment' => 'kpr', 'completed' => true],
            ['key' => 'mundur', 'name' => 'Nia Demo Mundur', 'process' => 'proses_bank', 'status' => 'Mundur', 'payment' => 'kpr'],
            ['key' => 'kendala', 'name' => 'Oki Demo Kendala Aktif', 'process' => 'proses_bank', 'status' => 'Lanjut', 'payment' => 'kpr', 'issue' => true],
            ['key' => 'cash', 'name' => 'Pandu Demo Cash', 'process' => 'psjb', 'status' => 'Lanjut', 'payment' => 'cash'],
            ['key' => 'historical', 'name' => 'Qori Demo Historical Migration', 'process' => 'akad', 'status' => 'Lanjut', 'payment' => 'kpr', 'historical' => true],
            ['key' => 'pindah', 'name' => 'Rani Demo Pindah Kavling', 'process' => 'psjb', 'status' => 'Lanjut', 'payment' => 'kpr', 'moved' => true],
            ['key' => 'ganti-konsumen-original', 'name' => 'Sari Demo Konsumen Lama', 'process' => 'proses_bank', 'status' => 'Mundur', 'payment' => 'kpr'],
            ['key' => 'ganti-konsumen', 'name' => 'Tio Demo Konsumen Pengganti', 'process' => 'proses_bank', 'status' => 'Lanjut', 'payment' => 'kpr', 'replacement_of' => 'ganti-konsumen-original'],
        ];

        for ($index = 21; $index <= 28; $index++) {
            $scenarios[] = ['key' => 'extra-'.$index, 'name' => 'Konsumen Demo Pagination '.$index, 'process' => $index % 2 === 0 ? 'data_konsumen' : 'pemberkasan', 'status' => 'Lanjut', 'payment' => $index % 3 === 0 ? 'cash_bertahap' : 'kpr'];
        }

        $applications = [];
        foreach ($scenarios as $index => $scenario) {
            $branch = array_values($branches)[$index % count($branches)];
            $project = array_values($projects)[$index % count($projects)];
            $salesUser = $sales[$index % count($sales)];
            $projectKavlings = array_values(array_filter(
                $kavlings,
                static fn (Kavling $item): bool => $item->project_id === $project->id,
            ));
            $kavling = $projectKavlings[$index % count($projectKavlings)];
            $replacementKavling = $projectKavlings[($index + 5) % count($projectKavlings)] ?? null;
            $marker = self::MARKER.' '.$scenario['key'];
            $customer = Customer::query()->updateOrCreate(
                ['email' => 'workspace-v2-'.$scenario['key'].'@example.test'],
                ['name' => $scenario['name'], 'phone' => '0800'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT), 'occupation' => 'Demo Profesional'],
            );

            $application = new ConsumerApplication;
            $application->fill([
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'project_id' => $project->id,
                'sales_user_id' => $salesUser->id,
                'kavling_id' => $kavling->id,
                'id_kavling' => $kavling->kavling_code,
                'nama_konsumen' => $customer->name,
                'application_status' => 'active',
                'consumer_status' => $scenario['status'],
                'transaction_status' => ($scenario['completed'] ?? false) ? 'selesai' : strtolower($scenario['status']),
                'current_stage' => $scenario['process'],
                'current_process' => $scenario['process'],
                'current_process_source' => 'workspace_v2_demo',
                'entry_mode' => ($scenario['historical'] ?? false) ? 'migration' : 'new',
                'acquisition_source' => 'workspace_v2_demo',
                'payment_method' => $scenario['payment'],
                'status_cash' => $scenario['payment'] === 'cash',
                'historical_entered_at' => ($scenario['historical'] ?? false) ? now()->subMonths(3) : null,
                'booking_date' => now()->subDays(40 - $index),
                'notes' => $marker.' '.(($scenario['key'] === 'long' || $index === 27) ? 'Catatan panjang demo untuk menguji truncation, drawer, dan responsive layout tanpa data nyata.' : 'Data demo lokal untuk UAT Workspace V2.'),
            ]);
            $application->save();
            $applications[$scenario['key']] = $application;

            $this->addProcessData($application, $scenario, $salesUser, $kavling, $replacementKavling, $index);
            if (isset($scenario['replacement_of'], $applications[$scenario['replacement_of']])) {
                $application->replacement_application_id = $applications[$scenario['replacement_of']]->id;
                $application->save();
            }
        }

        return $applications;
    }

    private function addProcessData(ConsumerApplication $application, array $scenario, User $sales, Kavling $kavling, ?Kavling $replacementKavling, int $index): void
    {
        $orderedStages = ['data_konsumen', 'psjb', 'bi_checking', 'pemberkasan', 'proses_bank', 'ppjb_dev', 'akad', 'bast', 'garansi', 'selesai'];
        $currentIndex = array_search($scenario['process'], $orderedStages, true);
        $currentIndex = $currentIndex === false ? 0 : $currentIndex;
        $events = [];
        foreach (array_slice($orderedStages, 0, $currentIndex + 1) as $stageIndex => $stage) {
            $events[$stage] = ConsumerStageEvent::create([
                'consumer_application_id' => $application->id,
                'stage' => $stage,
                'status' => 'completed',
                'event_date' => now()->subDays(45 - $index - $stageIndex)->toDateString(),
                'occurred_at' => now()->subDays(45 - $index - $stageIndex),
                'completed_at' => now()->subDays(44 - $index - $stageIndex),
                'actor_id' => $sales->id,
                'source' => 'workspace_v2_demo',
                'notes' => 'Riwayat demo lokal.',
            ]);
        }

        ConsumerProcessApplicability::create(['consumer_application_id' => $application->id, 'process_key' => 'garansi', 'applicability' => 'required', 'source' => 'workspace_v2_demo']);
        if ($scenario['payment'] === 'cash') {
            foreach (['bi_checking', 'pemberkasan', 'proses_bank', 'sp3k'] as $process) {
                ConsumerProcessApplicability::create([
                    'consumer_application_id' => $application->id,
                    'process_key' => $process,
                    'applicability' => 'not_applicable',
                    'reason' => 'Cara pembayaran Cash tidak melalui proses bank.',
                    'source' => 'workspace_v2_demo',
                ]);
            }
        }
        if ($currentIndex >= 1) {
            ConsumerPsjb::create(['consumer_application_id' => $application->id, 'consumer_stage_event_id' => $events['psjb']->id ?? null, 'id_kavling' => $kavling->kavling_code, 'id_kons' => 'W2-KONS-'.$application->id, 'id_psjb' => 'W2-PSJB-'.$application->id, 'tanggal_psjb' => now()->subDays(35), 'harga_unit' => 450000000, 'cara_pembayaran' => $scenario['payment'], 'status' => 'completed']);
        }
        if ($currentIndex >= 3) {
            $this->addBankAttempt($application, $scenario, $index, false);
        }
        if ($currentIndex >= 5) {
            ConsumerPpjbDeveloper::create(['consumer_application_id' => $application->id, 'consumer_stage_event_id' => $events['ppjb_dev']->id ?? null, 'tanggal_sp3k' => now()->subDays(20), 'tanggal_ttd_ppjb' => now()->subDays(15), 'status' => 'completed']);
        }
        if ($currentIndex >= 6) {
            ConsumerAkadRecord::create(['consumer_application_id' => $application->id, 'consumer_stage_event_id' => $events['akad']->id ?? null, 'tanggal_akad' => now()->subDays(10), 'kualitas_akad' => 'Baik', 'status_bangunan' => 'Siap', 'status_dp_konsumen' => 'Lunas', 'status_utilitas' => 'Aktif', 'status_konsumen' => 'Aktif']);
            $application->akad_date = now()->subDays(10);
            $application->save();
        }
        $bast = null;
        if ($currentIndex >= 7 || isset($scenario['warranty'])) {
            $bast = ConsumerBastRecord::create(['consumer_application_id' => $application->id, 'consumer_stage_event_id' => $events['bast']->id ?? null, 'tanggal_bast' => now()->subDays(5), 'no_bast' => 'BAST-DEMO-'.$application->id, 'status' => 'completed']);
        }
        if (isset($scenario['warranty'])) {
            ConsumerWarranty::create(['consumer_application_id' => $application->id, 'consumer_bast_record_id' => $bast?->id, 'status_komplain' => $scenario['warranty'] === 'Proses' ? 'Ada Komplain' : 'Tidak Ada Komplain', 'tgl_sales_ke_sam' => now()->subDays(4), 'detail_garansi' => $scenario['warranty'] === 'Proses' ? 'Retak rambut pada area teras, menunggu kunjungan teknisi demo.' : null, 'tanggal_selesai' => $scenario['warranty'] === 'Proses' ? null : now()->subDay(), 'status_garansi' => $scenario['warranty']]);
        }
        if ($scenario['issue'] ?? false) {
            ConsumerIssue::create(['consumer_application_id' => $application->id, 'process_key' => 'proses_bank', 'category' => 'Dokumen', 'description' => 'Kendala demo: dokumen tambahan bank belum diterima dari konsumen.', 'opened_at' => now()->subDays(2), 'pic_user_id' => $sales->id, 'status' => 'open']);
        }
        if ($scenario['multiple_banks'] ?? false) {
            $this->addBankAttempt($application, $scenario, $index, true);
        }
        if ($scenario['moved'] ?? false) {
            ConsumerKavlingAssignment::create(['consumer_application_id' => $application->id, 'kavling_id' => $kavling->id, 'assigned_at' => now()->subDays(25), 'released_at' => now()->subDays(15), 'release_reason' => 'Pindah kavling demo', 'assignment_status' => 'released']);
            $newKavling = $replacementKavling;
            if ($newKavling) {
                $application->kavling_id = $newKavling->id;
                $application->id_kavling = $newKavling->kavling_code;
                $application->save();
                ConsumerKavlingAssignment::create(['consumer_application_id' => $application->id, 'kavling_id' => $newKavling->id, 'assigned_at' => now()->subDays(14), 'assignment_status' => 'active']);
            }
        } else {
            ConsumerKavlingAssignment::create(['consumer_application_id' => $application->id, 'kavling_id' => $kavling->id, 'assigned_at' => now()->subDays(30), 'assignment_status' => 'active']);
        }
    }

    private function addBankAttempt(ConsumerApplication $application, array $scenario, int $index, bool $secondAttempt): void
    {
        $bank = $secondAttempt ? 'BSI' : ($scenario['bank'] ?? 'BTN');
        $approved = ($scenario['sp3k'] ?? false) || $secondAttempt;
        ConsumerBankProcess::create([
            'consumer_application_id' => $application->id,
            'bank_name' => $bank,
            'status' => $approved ? 'approved' : 'pending',
            'tanggal_terima_bank' => now()->subDays(25 - $index),
            'request_plafond' => 350000000,
            'request_tenor' => 180,
            'approved_plafond' => $approved ? 340000000 : null,
            'approved_tenor' => $approved ? 180 : null,
            'response_type' => $approved ? 'Disetujui' : 'Menunggu',
            'no_sp3k' => ($scenario['sp3k'] ?? false) && ! $secondAttempt ? 'SP3K-DEMO-'.$application->id : null,
            'sp3k_at' => ($scenario['sp3k'] ?? false) && ! $secondAttempt ? now()->subDays(12) : null,
            'source' => 'workspace_v2_demo',
        ]);
    }

    /** @param array<string, Branch> $branches @param array<string, LeadMaster> $projects */
    private function demoWaitingList(array $branches, array $projects): void
    {
        foreach (range(1, 3) as $index) {
            $branch = array_values($branches)[$index - 1];
            $project = array_values($projects)[$index - 1];
            $customer = Customer::query()->updateOrCreate(['email' => 'workspace-v2-demo-nup-'.$index.'@example.test'], ['name' => 'NUP Demo '.$index, 'phone' => '0811'.str_pad((string) $index, 8, '0', STR_PAD_LEFT)]);
            ConsumerNup::query()->updateOrCreate(['branch_id' => $branch->id, 'nup_number' => 'W2-NUP-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT)], ['customer_id' => $customer->id, 'project_id' => $project->id, 'registered_at' => now()->subDays($index), 'status' => 'waiting', 'source' => 'workspace_v2_demo', 'notes' => 'NUP demo lokal.']);
        }
    }

    /** @param array<string, Branch> $branches @param array<string, LeadMaster> $projects @param array<int, User> $sales */
    private function demoLeads(array $branches, array $projects, array $sales): void
    {
        foreach (range(1, 5) as $index) {
            SalesLead::query()->updateOrCreate(
                ['external_lead_id' => 'W2-DEMO-LEAD-'.$index],
                ['branch_id' => array_values($branches)[$index % count($branches)]->id, 'project_id' => array_values($projects)[$index % count($projects)]->id, 'sales_user_id' => $sales[$index % count($sales)]->id, 'lead_date' => now()->subDays($index), 'customer_name' => 'Lead Demo '.$index, 'phone' => '0822'.str_pad((string) $index, 8, '0', STR_PAD_LEFT), 'source' => $index % 2 === 0 ? 'Instagram' : 'Referral', 'current_status' => $index % 2 === 0 ? 'discussion' : 'no_response', 'notes' => 'Lead demo lokal untuk UAT.'],
            );
        }
    }
}
