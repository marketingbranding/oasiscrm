<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsumerIssueRequest;
use App\Http\Requests\ConsumerProcessRequest;
use App\Http\Requests\ConsumerWarrantyRequest;
use App\Models\ConsumerApplication;
use App\Models\ConsumerIssue;
use App\Services\ConsumerApplicationLifecycleService;
use App\Services\ConsumerDatabaseWorkspaceService;
use App\Services\ConsumerProcessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsumerProcessController extends Controller
{
    public function __construct(
        private readonly ConsumerApplicationLifecycleService $lifecycle,
        private readonly ConsumerProcessService $processes,
        private readonly ConsumerDatabaseWorkspaceService $workspace,
    ) {}

    public function index(Request $request, ?string $process = null): View
    {
        if ($process !== null) {
            $request->merge(['stage' => $process]);
        }

        return view('crm.consumer-workspace.index', [...$this->workspace->index($request->user(), $request), 'processTitle' => $process]);
    }

    public function selesai(Request $request): View
    {
        $applications = $this->processes->completedQuery($request->user())->latest('updated_at')->paginate(25)->withQueryString();

        return view('crm.consumer-process.selesai', compact('applications'));
    }

    public function slik(ConsumerProcessRequest $request, ConsumerApplication $consumerApplication): RedirectResponse|JsonResponse
    {
        return $this->respond($request, 'SLIK berhasil dicatat.', $this->lifecycle->recordBiChecking($consumerApplication, $request->validated(), $request->user()));
    }

    public function psjb(ConsumerProcessRequest $request, ConsumerApplication $consumerApplication): RedirectResponse|JsonResponse
    {
        return $this->respond($request, 'PSJB berhasil dicatat.', $this->lifecycle->recordPsjb($consumerApplication, $request->validated(), $request->user()));
    }

    public function pemberkasan(ConsumerProcessRequest $request, ConsumerApplication $consumerApplication): RedirectResponse|JsonResponse
    {
        return $this->respond($request, 'Pemberkasan berhasil dicatat.', $this->lifecycle->recordPemberkasan($consumerApplication, $request->validated(), $request->user()));
    }

    public function bank(ConsumerProcessRequest $request, ConsumerApplication $consumerApplication): RedirectResponse|JsonResponse
    {
        return $this->respond($request, 'Proses Bank berhasil diperbarui.', $this->lifecycle->recordProsesBank($consumerApplication, $request->validated(), $request->user()));
    }

    public function sp3k(ConsumerProcessRequest $request, ConsumerApplication $consumerApplication): RedirectResponse|JsonResponse
    {
        return $this->respond($request, 'SP3K berhasil dicatat.', $this->lifecycle->recordSp3k($consumerApplication, $request->validated(), $request->user()));
    }

    public function ppjb(ConsumerProcessRequest $request, ConsumerApplication $consumerApplication): RedirectResponse|JsonResponse
    {
        return $this->respond($request, 'PPJB berhasil dicatat.', $this->lifecycle->recordPpjb($consumerApplication, $request->validated(), $request->user()));
    }

    public function warranty(ConsumerWarrantyRequest $request, ConsumerApplication $consumerApplication): RedirectResponse|JsonResponse
    {
        return $this->respond($request, 'Form Garansi berhasil dicatat.', $this->processes->recordWarranty($consumerApplication, $request->validated(), $request->user()));
    }

    public function issue(ConsumerIssueRequest $request, ConsumerApplication $consumerApplication): RedirectResponse|JsonResponse
    {
        return $this->respond($request, 'Kendala berhasil dicatat.', $this->processes->createIssue($consumerApplication, $request->validated(), $request->user()));
    }

    public function resolveIssue(ConsumerIssueRequest $request, ConsumerIssue $consumerIssue): RedirectResponse|JsonResponse
    {
        return $this->respond($request, 'Kendala ditandai selesai.', $this->processes->resolveIssue($consumerIssue, $request->validated(), $request->user()));
    }

    private function respond(Request $request, string $message, object $result): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message, 'data' => ['id' => $result->getKey()]]);
        }

        return back()->with('success', $message);
    }
}
