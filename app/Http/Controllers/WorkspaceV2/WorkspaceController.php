<?php

namespace App\Http\Controllers\WorkspaceV2;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsumerEntryRequest;
use App\Models\ActivityLog;
use App\Models\ConsumerApplication;
use App\Models\ConsumerIssue;
use App\Models\ConsumerWarranty;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ConsumerDatabaseWorkspaceService;
use App\Services\ConsumerEntryService;
use App\Services\ConsumerNupService;
use App\Services\ConsumerOperationalService;
use App\Services\ConsumerProcessService;
use App\Services\OrganizationScopeService;
use App\Services\WorkspaceAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class WorkspaceController extends Controller
{
    private const PROCESS_VIEWS = [
        'semua' => 'Semua Transaksi',
        'data-konsumen' => 'Data Konsumen',
        'psjb' => 'PSJB',
        'bi-checking' => 'BI Checking',
        'pemberkasan' => 'Pemberkasan',
        'proses-bank' => 'Proses Bank',
        'ppjb-dev' => 'PPJB Dev',
        'akad' => 'Akad',
        'bast' => 'BAST',
    ];

    private const PROCESS_STAGES = [
        'data-konsumen' => 'data_konsumen',
        'psjb' => 'PSJB',
        'bi-checking' => 'bi_checking',
        'pemberkasan' => 'pemberkasan',
        'proses-bank' => 'proses_bank',
        'ppjb-dev' => 'ppjb_dev',
        'akad' => 'akad',
        'bast' => 'bast',
    ];

    private const FORM_TITLES = [
        ...self::PROCESS_VIEWS,
        'garansi' => 'Garansi',
        'kendala' => 'Kendala',
    ];

    public function __construct(
        private readonly ConsumerDatabaseWorkspaceService $consumerWorkspace,
        private readonly ConsumerEntryService $consumerEntry,
        private readonly ConsumerOperationalService $consumerOperations,
        private readonly ConsumerProcessService $consumerProcesses,
        private readonly ConsumerNupService $nups,
        private readonly OrganizationScopeService $organizationScope,
        private readonly WorkspaceAccessService $workspaceAccess,
    ) {}

    public function dashboard(Request $request): View
    {
        $this->authorizeWorkspace($request->user());
        $applications = $this->scopedApplications($request);
        $issues = ConsumerIssue::query()
            ->whereIn('consumer_application_id', $applications->clone()->select('consumer_applications.id'))
            ->whereIn('status', ['open', 'in_progress'])
            ->count();
        $overdue = ConsumerWarranty::query()
            ->whereIn('consumer_application_id', $applications->clone()->select('consumer_applications.id'))
            ->whereNull('tanggal_selesai')
            ->where('status_garansi', 'Proses')
            ->count();

        return view('workspace-v2.dashboard.index', [
            'activeTransactions' => (clone $applications)->whereNotIn('consumer_status', ['Mundur', 'Reject'])->count(),
            'completedTransactions' => (clone $applications)->whereIn('transaction_status', ['SELESAI', 'selesai', 'completed'])->count(),
            'attentionCount' => $issues + $overdue,
            'processCounts' => (clone $applications)
                ->selectRaw("COALESCE(NULLIF(current_process, ''), NULLIF(current_stage, ''), 'data_konsumen') as process_key, COUNT(*) as total")
                ->groupBy('process_key')
                ->orderByDesc('total')
                ->limit(5)
                ->get(),
            'attentionItems' => ConsumerIssue::query()
                ->with(['application.customer', 'application.project'])
                ->whereIn('consumer_application_id', $applications->clone()->select('consumer_applications.id'))
                ->whereIn('status', ['open', 'in_progress'])
                ->latest('opened_at')
                ->limit(5)
                ->get(),
        ]);
    }

    public function transactions(Request $request, string $view = 'semua'): View
    {
        $this->authorizeConsumerView($request->user());
        abort_unless(array_key_exists($view, self::PROCESS_VIEWS), Response::HTTP_NOT_FOUND);

        if (isset(self::PROCESS_STAGES[$view])) {
            $request->merge(['stage' => self::PROCESS_STAGES[$view]]);
        }

        $workspace = $this->consumerWorkspace->index($request->user(), $request);
        $workspace['processViews'] = self::PROCESS_VIEWS;
        $workspace['activeProcessView'] = $view;
        $workspace['pageTitle'] = self::PROCESS_VIEWS[$view];
        $workspace['pageDescription'] = $view === 'semua'
            ? 'Satu daftar kerja untuk seluruh transaksi konsumen dalam scope Anda.'
            : 'Process view dari transaksi yang sama, mengikuti urutan operasional spreadsheet.';

        return view('workspace-v2.transactions.index', $workspace);
    }

    public function transactionDetail(Request $request, ConsumerApplication $consumerApplication): JsonResponse
    {
        $this->authorizeConsumerView($request->user());

        return response()->json(['ok' => true, 'data' => $this->consumerWorkspace->detail($request->user(), $consumerApplication)]);
    }

    public function consumerForm(Request $request): View
    {
        $this->authorizeConsumerManage($request->user());

        return view('workspace-v2.transactions.form', [
            'process' => 'data-konsumen',
            'title' => 'Data Konsumen',
            'description' => 'Catat identitas konsumen dan konteks transaksi sesuai field spreadsheet.',
            'action' => route('workspace-v2.transactions.data-konsumen.store'),
            'method' => 'POST',
            'fields' => $this->consumerFields(),
            'branches' => $this->workspaceAccess->accessibleBranches($request->user()),
            'projects' => $this->workspaceAccess->accessibleProjects($request->user()),
        ]);
    }

    public function storeConsumer(ConsumerEntryRequest $request): RedirectResponse|JsonResponse
    {
        $application = $this->consumerEntry->create($request->validated(), $request->user());

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'data' => $this->consumerWorkspace->detail($request->user(), $application),
            ], Response::HTTP_CREATED);
        }

        return redirect()
            ->route('workspace-v2.transactions.data-konsumen')
            ->with('success', 'Data konsumen berhasil disimpan.');
    }

    public function processForm(Request $request, ConsumerApplication $consumerApplication, string $process): View
    {
        $this->authorizeConsumerManage($request->user());
        abort_unless(array_key_exists($process, self::PROCESS_STAGES) || in_array($process, ['garansi', 'kendala'], true), Response::HTTP_NOT_FOUND);
        $this->consumerWorkspace->detail($request->user(), $consumerApplication);

        $definitions = $this->processDefinitions($consumerApplication, $process);

        return view('workspace-v2.transactions.form', [
            'process' => $process,
            'title' => self::FORM_TITLES[$process],
            'description' => $definitions['description'],
            'action' => route($definitions['route'], $consumerApplication),
            'method' => 'POST',
            'fields' => $definitions['fields'],
            'branches' => collect(),
            'projects' => collect(),
            'application' => $consumerApplication->load(['customer', 'project', 'kavling']),
        ]);
    }

    public function lead(Request $request): View
    {
        $this->authorizeSalesView($request->user());
        $search = trim($request->string('search')->toString());
        $query = SalesLead::query()->visibleTo($request->user())->with(['branch', 'project', 'sales']);
        $query->when($search !== '', fn (Builder $builder): Builder => $builder->where(function (Builder $nested) use ($search): void {
            $nested->where('customer_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('external_lead_id', 'like', "%{$search}%")
                ->orWhere('source', 'like', "%{$search}%");
        }));

        return view('workspace-v2.lead.index', [
            'leads' => $query->latest('updated_at')->paginate(25)->withQueryString(),
            'search' => $search,
        ]);
    }

    public function nup(Request $request): View
    {
        $this->authorizeConsumerView($request->user());
        $search = trim($request->string('search')->toString());
        $query = $this->nups->visibleQuery($request->user())->with(['customer', 'branch', 'project', 'convertedApplication']);
        $query->when($search !== '', fn (Builder $builder): Builder => $builder->where(function (Builder $nested) use ($search): void {
            $nested->where('nup_number', 'like', "%{$search}%")
                ->orWhereHas('customer', fn (Builder $customer): Builder => $customer->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }));

        return view('workspace-v2.nup.index', [
            'nups' => $query->latest('registered_at')->latest('id')->paginate(25)->withQueryString(),
            'search' => $search,
        ]);
    }

    public function aggregate(Request $request, string $workspace): View
    {
        $this->authorizeConsumerView($request->user());
        $allowed = ['mundur', 'kendala', 'garansi', 'selesai'];
        abort_unless(in_array($workspace, $allowed, true), Response::HTTP_NOT_FOUND);
        $applications = $this->scopedApplications($request);

        $data = match ($workspace) {
            'mundur' => (clone $applications)->where('consumer_status', 'Mundur')->with(['customer', 'project', 'kavling', 'sales'])->latest('updated_at')->paginate(25)->withQueryString(),
            'selesai' => $this->consumerProcesses->completedQuery($request->user())->with(['customer', 'project', 'kavling', 'sales'])->latest('updated_at')->paginate(25)->withQueryString(),
            'kendala' => ConsumerIssue::query()->with(['application.customer', 'application.project', 'pic'])->whereIn('consumer_application_id', $applications->clone()->select('consumer_applications.id'))->latest('opened_at')->paginate(25)->withQueryString(),
            'garansi' => ConsumerWarranty::query()->with(['application.customer', 'application.project', 'application.kavling'])->whereIn('consumer_application_id', $applications->clone()->select('consumer_applications.id'))->latest('created_at')->paginate(25)->withQueryString(),
        };

        return view('workspace-v2.aggregate.index', [
            'workspace' => $workspace,
            'title' => Str::headline($workspace),
            'records' => $data,
        ]);
    }

    public function activity(Request $request): View
    {
        $this->authorizeWorkspace($request->user());

        return view('workspace-v2.activity.index', [
            'notifications' => UserNotification::query()->where('user_id', $request->user()->id)->latest()->paginate(20)->withQueryString(),
            'activities' => ActivityLog::query()->with('causer:id,name')->where('causer_id', $request->user()->id)->latest()->limit(30)->get(),
        ]);
    }

    public function reports(Request $request): View
    {
        $this->authorizeConsumerView($request->user());
        $applications = $this->scopedApplications($request);

        return view('workspace-v2.reports.index', [
            'total' => (clone $applications)->count(),
            'active' => (clone $applications)->whereNotIn('consumer_status', ['Mundur', 'Reject'])->count(),
            'completed' => (clone $applications)->whereIn('transaction_status', ['SELESAI', 'selesai', 'completed'])->count(),
            'branches' => $this->workspaceAccess->accessibleBranches($request->user()),
        ]);
    }

    public function settings(Request $request): View
    {
        $this->authorizeWorkspace($request->user());

        return view('workspace-v2.settings.index');
    }

    private function authorizeWorkspace(User $user): void
    {
        abort_unless(
            $user->hasScopedPermission('sales_pocketbook')
                || $user->hasScopedPermission('consumer_progress')
                || $user->hasScopedPermission('work_planner'),
            Response::HTTP_FORBIDDEN,
        );
    }

    private function authorizeSalesView(User $user): void
    {
        abort_unless($user->hasScopedPermission('sales_pocketbook'), Response::HTTP_FORBIDDEN);
    }

    private function authorizeConsumerView(User $user): void
    {
        abort_unless(
            $user->hasPermission('consumer_progress.view') && $user->hasScopedPermission('consumer_progress'),
            Response::HTTP_FORBIDDEN,
        );
    }

    private function authorizeConsumerManage(User $user): void
    {
        abort_unless(
            $user->hasPermission('consumer_progress.manage') && $user->hasScopedPermission('consumer_progress', 'manage'),
            Response::HTTP_FORBIDDEN,
        );
    }

    /** @return Builder<ConsumerApplication> */
    private function scopedApplications(Request $request): Builder
    {
        return $this->consumerOperations->visibleQuery($request->user(), $this->organizationScope);
    }

    /** @return list<array<string, mixed>> */
    private function consumerFields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Nama Konsumen', 'type' => 'text', 'required' => true],
            ['name' => 'nik', 'label' => 'NIK', 'type' => 'text'],
            ['name' => 'date_of_birth', 'label' => 'Tanggal Lahir', 'type' => 'date'],
            ['name' => 'occupation', 'label' => 'Pekerjaan', 'type' => 'text'],
            ['name' => 'occupation_detail', 'label' => 'Detail Pekerjaan', 'type' => 'text'],
            ['name' => 'phone', 'label' => 'No HP', 'type' => 'text'],
            ['name' => 'emergency_contact_name', 'label' => 'Nama Kontak Darurat', 'type' => 'text'],
            ['name' => 'emergency_contact_phone', 'label' => 'No HP Kontak Darurat', 'type' => 'text'],
            ['name' => 'address', 'label' => 'Alamat', 'type' => 'textarea', 'wide' => true],
            ['name' => 'kelurahan', 'label' => 'Kelurahan', 'type' => 'text'],
            ['name' => 'kecamatan', 'label' => 'Kecamatan', 'type' => 'text'],
            ['name' => 'kabupaten_kota', 'label' => 'Kabupaten/Kota', 'type' => 'text'],
            ['name' => 'branch_id', 'label' => 'Cabang', 'type' => 'select', 'options' => 'branches', 'required' => true],
            ['name' => 'project_id', 'label' => 'Proyek', 'type' => 'select', 'options' => 'projects', 'required' => true],
            ['name' => 'payment_method', 'label' => 'Cara Pembayaran', 'type' => 'select', 'values' => ['kpr' => 'KPR', 'cash' => 'Cash', 'cash_bertahap' => 'Cash Bertahap']],
            ['name' => 'notes', 'label' => 'Keterangan', 'type' => 'textarea', 'wide' => true],
        ];
    }

    /** @return array{route: string, description: string, fields: list<array<string, mixed>>} */
    private function processDefinitions(ConsumerApplication $application, string $process): array
    {
        $commonDate = ['name' => 'tanggal_terima_bank', 'label' => 'Tanggal Terima Bank', 'type' => 'date'];

        return match ($process) {
            'psjb' => ['route' => 'consumer-process.psjb', 'description' => 'Catat PSJB tanpa mengulang konteks konsumen.', 'fields' => $this->fields(['tanggal_psjb' => ['Tanggal PSJB', 'date', true], 'harga_unit' => ['Harga Unit', 'number'], 'tanggal_utj' => ['Tanggal UTJ', 'date'], 'utj' => ['UTJ', 'number'], 'tanggal_dp_klt' => ['Tanggal DP KLT', 'date'], 'dp_all_in' => ['DP All In', 'number'], 'nominal_cicilan' => ['Nominal Cicilan', 'number'], 'jumlah_cicilan' => ['Jumlah Cicilan', 'number'], 'luas_klt' => ['Luas KLT', 'number'], 'harga_klt_m' => ['Harga KLT/m', 'number'], 'harga_klt_total' => ['Harga KLT Total', 'number'], 'cara_pembayaran' => ['Cara Pembayaran', 'text'], 'nama_promo' => ['Nama Promo', 'text'], 'keterangan' => ['Keterangan', 'textarea']])],
            'bi-checking' => ['route' => 'consumer-process.slik', 'description' => 'Catat hasil BI Checking / SLIK sesuai data operasional.', 'fields' => $this->fields(['tanggal_slik' => ['Tanggal SLIK', 'date', true], 'hasil_slik' => ['Hasil SLIK', 'text', true], 'keputusan' => ['Keputusan', 'text'], 'keterangan' => ['Keterangan', 'textarea']])],
            'pemberkasan' => ['route' => 'consumer-process.pemberkasan', 'description' => 'Catat penerimaan berkas oleh bank.', 'fields' => $this->fields(['bank_name' => ['Bank', 'text', true], ...[$commonDate['name'] => [$commonDate['label'], $commonDate['type']]], 'kc_unit' => ['KC / Unit', 'text'], 'request_plafond' => ['Request Plafond', 'number'], 'request_tenor' => ['Request Tenor', 'number'], 'tipe_pemberkasan' => ['Tipe Pemberkasan', 'text']])],
            'proses-bank' => ['route' => 'consumer-process.bank', 'description' => 'Catat respons bank. Detail SP3K tetap berada di Proses Bank.', 'fields' => $this->fields(['bank_name' => ['Bank', 'text', true], ...[$commonDate['name'] => [$commonDate['label'], $commonDate['type']]], 'response_type' => ['Jenis Respon', 'text'], 'approved_plafond' => ['Approved Plafond', 'number'], 'approved_tenor' => ['Approved Tenor', 'number'], 'revision_category' => ['Kategori Revisi', 'text'], 'revision_detail' => ['Detail Revisi', 'textarea'], 'obstacle' => ['Kendala', 'textarea'], 'status' => ['Status', 'text']])],
            'ppjb-dev' => ['route' => 'consumer-process.ppjb', 'description' => 'Catat tanda tangan PPJB Developer. Tanggal SP3K dibaca dari Proses Bank.', 'fields' => $this->fields(['tanggal_ttd_ppjb' => ['Tanggal TTD PPJB', 'date', true], 'notes' => ['Keterangan', 'textarea']])],
            'akad' => ['route' => 'consumer-applications.akad', 'description' => 'Catat Akad dan status bangunan/konsumen.', 'fields' => $this->fields(['tanggal_akad' => ['Tanggal Akad', 'date', true], 'kualitas_akad' => ['Kualitas Akad', 'text'], 'status_bangunan' => ['Status Bangunan', 'text'], 'status_dp_konsumen' => ['Status DP Konsumen', 'text'], 'status_utilitas' => ['Status Utilitas', 'text'], 'status_konsumen' => ['Status Konsumen', 'text'], 'keterangan_terlambat' => ['Keterangan Terlambat', 'textarea']])],
            'bast' => ['route' => 'consumer-applications.bast', 'description' => 'Catat BAST ketika readiness existing terpenuhi.', 'fields' => $this->fields(['tanggal_bast' => ['Tanggal BAST', 'date', true], 'no_bast' => ['No. BAST', 'text'], 'status' => ['Status', 'text'], 'notes' => ['Keterangan', 'textarea']])],
            'garansi' => ['route' => 'consumer-process.garansi', 'description' => 'Catat pekerjaan after-sales setelah BAST.', 'fields' => $this->fields(['status_komplain' => ['Status Komplain', 'text', true], 'tgl_sales_ke_sam' => ['Tanggal Sales ke SAM', 'date'], 'tgl_sam_ke_sat' => ['Tanggal SAM ke SAT', 'date'], 'tgl_sat_ke_sam' => ['Tanggal SAT ke SAM', 'date'], 'tgl_sam_ke_sales' => ['Tanggal SAM ke Sales', 'date'], 'tgl_sales_ke_kons' => ['Tanggal Sales ke Konsumen', 'date'], 'detail_garansi' => ['Detail Garansi', 'textarea'], 'tanggal_selesai' => ['Tanggal Selesai', 'date'], 'status_garansi' => ['Status Garansi', 'text', true]])],
            'kendala' => ['route' => 'consumer-process.kendala', 'description' => 'Catat kendala lintas proses dan PIC penyelesaian.', 'fields' => $this->fields(['process_key' => ['Proses', 'text', true], 'category' => ['Kategori', 'text'], 'description' => ['Kendala', 'textarea', true], 'opened_at' => ['Tanggal Dibuka', 'date'], 'status' => ['Status', 'text']])],
            default => abort(Response::HTTP_NOT_FOUND),
        };
    }

    /** @param array<string, array{0: string, 1: string, 2?: bool}> $definitions */
    private function fields(array $definitions): array
    {
        return collect($definitions)->map(fn (array $definition, string $name): array => [
            'name' => $name,
            'label' => $definition[0],
            'type' => $definition[1],
            'required' => $definition[2] ?? false,
            'wide' => $definition[1] === 'textarea',
        ])->values()->all();
    }
}
