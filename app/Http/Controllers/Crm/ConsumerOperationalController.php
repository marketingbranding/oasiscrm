<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ConsumerApplication;
use App\Models\Customer;
use App\Models\Kavling;
use App\Models\LeadMaster;
use App\Models\Promo;
use App\Models\User;
use App\Services\ConsumerKavlingLifecycleService;
use App\Services\ConsumerOperationalService;
use App\Services\OrganizationScopeService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConsumerOperationalController extends Controller
{
    public function __construct(
        private ConsumerOperationalService $service,
        private OrganizationScopeService $scope,
        private ConsumerKavlingLifecycleService $lifecycle,
    ) {}

    public function create(Request $request): View
    {
        $this->authorizeManage($request);
        [$projects, $sales] = $this->options($request);
        $kavlings = Kavling::query()->whereIn('project_id', $projects->pluck('id'))->with('project')->orderBy('kavling_code')->get();

        return view('crm.consumer-local.create', compact('projects', 'sales', 'kavlings'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $this->validatedApplication($request);
        $this->assertScope($request, (int) $data['project_id']);
        $customer = Customer::create([
            'name' => $data['nama_konsumen'], 'phone' => $data['no_hp'], 'nik_encrypted' => $data['nik'],
            'date_of_birth' => $data['tanggal_lahir'], 'occupation' => $data['pekerjaan'], 'occupation_detail' => $data['detail_pekerjaan'] ?? null,
            'address' => $data['alamat'], 'kelurahan' => $data['kelurahan'], 'kecamatan' => $data['kecamatan'], 'kabupaten_kota' => $data['kabupaten_kota'],
            'emergency_contact_name' => $data['nama_kondar'] ?? null, 'emergency_contact_phone' => $data['no_hp_kondar'] ?? null,
        ]);
        try {
            $this->service->create($data + ['customer_id' => $customer->id, 'branch_id' => LeadMaster::findOrFail($data['project_id'])->branch_id], $request->user(), $this->lifecycle);
        } catch (DomainException $exception) {
            $customer->delete();

            return back()->withInput()->withErrors(['kavling_id' => $exception->getMessage()]);
        }

        return redirect()->route('consumer-local.index')->with('success', 'Data konsumen berhasil dibuat.');
    }

    public function edit(Request $request, ConsumerApplication $application): View
    {
        $this->authorizeManage($request, $application);
        [$projects, $sales] = $this->options($request);
        $kavlings = Kavling::query()->where('project_id', $application->project_id)->orderBy('kavling_code')->get();

        return view('crm.consumer-local.edit', compact('application', 'projects', 'sales', 'kavlings'));
    }

    public function update(Request $request, ConsumerApplication $application): RedirectResponse
    {
        $this->authorizeManage($request, $application);
        $data = $request->validate([
            'sales_user_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')], 'promo_id' => ['nullable', 'integer', Rule::exists(Promo::class, 'id')],
            'status_cash' => ['nullable', 'boolean'], 'consumer_status' => ['required', Rule::in($this->service->statuses())],
            'target_kavling_id' => ['nullable', 'integer', Rule::exists(Kavling::class, 'id')], 'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        try {
            $this->service->update($application, $data, $request->user(), $this->lifecycle);
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['consumer_status' => $exception->getMessage()]);
        }

        return redirect()->route('consumer-local.show', $application)->with('success', 'Data konsumen berhasil diperbarui.');
    }

    public function biCheckingCreate(Request $request, ConsumerApplication $application): View
    {
        $this->authorizeManage($request, $application);

        return view('crm.consumer-local.bi-checking', compact('application'));
    }

    public function biCheckingStore(Request $request, ConsumerApplication $application): RedirectResponse
    {
        $this->authorizeManage($request, $application);
        $data = $request->validate(['tanggal_slik' => ['required', 'date'], 'hasil_slik' => ['required', 'string', 'max:100'], 'keterangan' => ['nullable', 'string', 'max:5000']]);
        $this->service->recordBiChecking($application, $data, $request->user());

        return redirect()->route('consumer-local.show', $application)->with('success', 'BI Checking berhasil dicatat.');
    }

    public function psjbCreate(Request $request, ConsumerApplication $application): View
    {
        $this->authorizeManage($request, $application);

        return view('crm.consumer-local.psjb', compact('application'));
    }

    public function psjbStore(Request $request, ConsumerApplication $application): RedirectResponse
    {
        $this->authorizeManage($request, $application);
        $data = $request->validate([
            'tanggal_psjb' => ['required', 'date'], 'harga_unit' => ['nullable', 'numeric', 'min:0'], 'tanggal_utj' => ['nullable', 'date'], 'utj' => ['nullable', 'numeric', 'min:0'],
            'tanggal_dp_klt' => ['nullable', 'date'], 'dp_all_in' => ['nullable', 'numeric', 'min:0'], 'nominal_cicilan' => ['nullable', 'numeric', 'min:0'], 'jumlah_cicilan' => ['nullable', 'integer', 'min:0'],
            'luas_klt' => ['nullable', 'numeric', 'min:0'], 'harga_klt_m' => ['nullable', 'numeric', 'min:0'], 'harga_klt_total' => ['nullable', 'numeric', 'min:0'],
            'cara_pembayaran' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:100'], 'keterangan' => ['nullable', 'string', 'max:5000'],
        ]);
        try {
            $this->service->recordPsjb($application, $data, $request->user());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['tanggal_psjb' => $exception->getMessage()]);
        }

        return redirect()->route('consumer-local.show', $application)->with('success', 'PSJB berhasil dicatat.');
    }

    private function authorizeManage(Request $request, ?ConsumerApplication $application = null): void
    {
        abort_unless($request->user()->hasPermission('consumer_progress.manage') || $request->user()->hasScopedPermission('consumer_progress', 'manage'), 403);
        if ($application) {
            $this->assertScope($request, (int) $application->project_id);
            abort_unless(in_array((int) $application->branch_id, $this->scope->branchIds($request->user(), 'consumer_progress', 'manage'), true), 403);
        }
    }

    private function assertScope(Request $request, int $projectId): void
    {
        abort_unless(in_array($projectId, $this->scope->projectIds($request->user(), 'consumer_progress', 'manage'), true), 403);
    }

    private function options(Request $request): array
    {
        $projectIds = $this->scope->projectIds($request->user(), 'consumer_progress', 'manage');

        return [LeadMaster::query()->whereIn('id', $projectIds)->where('is_active', true)->orderBy('project_name')->get(), User::query()->whereIn('id', $this->scope->visibleUserIds($request->user(), 'consumer_progress', 'manage'))->orderBy('name')->get(['id', 'name'])];
    }

    private function validatedApplication(Request $request): array
    {
        return $request->validate([
            'project_id' => ['required', 'integer', Rule::exists(LeadMaster::class, 'id')], 'kavling_id' => ['nullable', 'integer', Rule::exists(Kavling::class, 'id')],
            'sales_user_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')], 'promo_id' => ['nullable', 'integer', Rule::exists(Promo::class, 'id')],
            'nik' => ['required', 'digits:16'], 'nama_konsumen' => ['required', 'string', 'max:255'], 'tanggal_lahir' => ['required', 'date'],
            'pekerjaan' => ['required', 'string', 'max:255'], 'detail_pekerjaan' => ['nullable', 'string', 'max:255'], 'alamat' => ['required', 'string', 'max:2000'],
            'kelurahan' => ['required', 'string', 'max:255'], 'kecamatan' => ['required', 'string', 'max:255'], 'kabupaten_kota' => ['required', 'string', 'max:255'],
            'no_hp' => ['required', 'string', 'max:50'], 'nama_kondar' => ['required', 'string', 'max:255'], 'no_hp_kondar' => ['required', 'string', 'max:50'],
            'status_cash' => ['nullable', 'boolean'], 'consumer_status' => ['required', Rule::in($this->service->statuses())], 'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
