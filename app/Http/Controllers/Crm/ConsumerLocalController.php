<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ConsumerApplication;
use App\Models\LeadMaster;
use App\Models\User;
use App\Services\OrganizationScopeService;
use Illuminate\Http\Request;

class ConsumerLocalController extends Controller
{
    public function index(Request $request, OrganizationScopeService $scope)
    {
        $user = $request->user();
        $branchIds = $scope->branchIds($user, 'consumer_progress');
        $projectIds = $scope->projectIds($user, 'consumer_progress');
        $query = ConsumerApplication::query()
            ->with(['customer:id,name,phone', 'branch:id,name,code', 'project:id,project_name', 'sales:id,name', 'kavling:id,kavling_code,name', 'promo:id,name'])
            ->whereIn('branch_id', $branchIds)
            ->whereIn('project_id', $projectIds);

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                    ->orWhereHas('kavling', fn ($kavling) => $kavling->where('kavling_code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
            });
        }

        foreach (['project_id', 'sales_user_id', 'source_completeness_status', 'consumer_status', 'source_last_process', 'application_status'] as $filter) {
            if ($request->filled($filter)) {
                if ($request->query($filter) === 'Belum Diisi') {
                    $query->where(fn ($nested) => $nested->whereNull($filter)->orWhere($filter, ''));
                } else {
                    $query->where($filter, $request->query($filter));
                }
            }
        }

        $applications = $query->latest('id')->paginate(25)->withQueryString();
        $projects = LeadMaster::query()->whereIn('id', $projectIds)->where('is_active', true)->orderBy('project_name')->get(['id', 'project_name']);
        $sales = User::query()->whereIn('id', $applications->getCollection()->pluck('sales_user_id')->filter()->unique())->orderBy('name')->get(['id', 'name']);

        return view('crm.consumer-local.index', compact('applications', 'projects', 'sales'));
    }

    public function show(Request $request, ConsumerApplication $application, OrganizationScopeService $scope)
    {
        abort_unless(in_array((int) $application->branch_id, $scope->branchIds($request->user(), 'consumer_progress'), true), 403);
        abort_unless(in_array((int) $application->project_id, $scope->projectIds($request->user(), 'consumer_progress'), true), 403);
        $application->load(['customer:id,name,phone,date_of_birth,occupation,occupation_detail,address,kelurahan,kecamatan,kabupaten_kota,emergency_contact_name,emergency_contact_phone,nik_encrypted', 'branch:id,name,code', 'project:id,project_name', 'sales:id,name', 'kavling:id,kavling_code,name', 'promo:id,name', 'stageEvents:id,consumer_application_id,stage,status,occurred_at,completed_at,source', 'bankProcesses:id,consumer_application_id,bank_name,status,submitted_at,verified_at,sp3k_at,rejected_at,source']);
        $customer = $application->customer;
        $empty = fn ($value) => $value ?: 'Belum Ada Data';
        $stageLabels = ['bi_checking' => 'BI Checking', 'PSJB' => 'PSJB', 'pemberkasan' => 'Pemberkasan', 'proses_bank' => 'Proses Bank', 'ppjb_dev' => 'PPJB Developer', 'akad' => 'Akad', 'bast' => 'BAST'];
        $stageOrder = array_keys($stageLabels);
        $timeline = $application->stageEvents->sortBy(fn ($event) => [array_search($event->stage, $stageOrder, true), $event->occurred_at?->timestamp ?? PHP_INT_MAX, $event->id])->values()->map(fn ($event) => ['stage' => $event->stage, 'stage_label' => $stageLabels[$event->stage] ?? $event->stage, 'status' => $empty($event->status), 'date' => $event->occurred_at?->format('Y-m-d H:i') ?? 'Belum Ada Data', 'source' => $empty($event->source)])->all();
        $attention = [];
        if ($application->source_completeness_status === 'Data Belum Lengkap') {
            $attention[] = 'Data Belum Lengkap';
        }
        if (! $application->consumer_status) {
            $attention[] = 'Consumer Status belum diisi';
        }
        if (! $application->source_last_process) {
            $attention[] = 'Proses terakhir belum ada';
        }
        if (! $application->sales_user_id) {
            $attention[] = 'Sales belum terhubung';
        }
        if (! $application->kavling_id) {
            $attention[] = 'Kavling belum terhubung';
        }
        if ($application->bankProcesses->isEmpty()) {
            $attention[] = 'Bank process belum ada';
        }
        $currentStageCount = $application->stageEvents->where('status', 'current')->count();
        $integrity = [];
        if ($currentStageCount > 1) {
            $integrity[] = 'Terdapat beberapa proses aktif yang perlu diperiksa.';
        }
        $banks = $application->bankProcesses->sortBy(fn ($bank) => $bank->submitted_at?->timestamp ?? PHP_INT_MAX)->values()->map(fn ($bank) => ['bank_name' => $empty($bank->bank_name), 'status' => $empty($bank->status), 'source' => $empty($bank->source), 'date' => ($bank->submitted_at ?? $bank->verified_at ?? $bank->sp3k_at ?? $bank->rejected_at)?->format('Y-m-d H:i') ?? 'Belum Ada Data'])->all();

        return response()->json(['data' => [
            'id' => $application->id,
            'name' => $empty($customer?->name),
            'phone' => $empty($customer?->phone),
            'date_of_birth' => $customer?->date_of_birth?->format('Y-m-d') ?? 'Belum Ada Data',
            'occupation' => $empty($customer?->occupation),
            'occupation_detail' => $empty($customer?->occupation_detail),
            'address' => $empty($customer?->address),
            'kelurahan' => $empty($customer?->kelurahan),
            'kecamatan' => $empty($customer?->kecamatan),
            'kabupaten_kota' => $empty($customer?->kabupaten_kota),
            'emergency_contact_name' => $empty($customer?->emergency_contact_name),
            'emergency_contact_phone' => $empty($customer?->emergency_contact_phone),
            'nik' => $customer?->nik_encrypted ? '••••••••••••••••' : 'Belum Ada Data',
            'branch' => $empty($application->branch?->name),
            'project' => $empty($application->project?->project_name),
            'sales' => $empty($application->sales?->name),
            'kavling' => $empty($application->kavling?->kavling_code ?: $application->kavling?->name),
            'promo' => $empty($application->promo?->name),
            'status_cash' => $application->status_cash === null ? 'Belum Ada Data' : ($application->status_cash ? 'Ya' : 'Tidak'),
            'completeness_status' => $empty($application->source_completeness_status),
            'consumer_status' => $empty($application->consumer_status),
            'last_process' => $empty($application->source_last_process),
            'application_status' => $empty($application->application_status),
            'booking_date' => $application->booking_date?->format('Y-m-d') ?? 'Belum Ada Data',
            'akad_date' => $application->akad_date?->format('Y-m-d') ?? 'Belum Ada Data',
            'notes' => $empty($application->notes),
            'current_process' => $application->current_stage ? ($stageLabels[$application->current_stage] ?? $application->current_stage) : 'Belum Ada Data',
            'timeline' => $timeline,
            'banks' => $banks,
            'attention' => $attention,
            'integrity' => $integrity,
        ]]);
    }
}
