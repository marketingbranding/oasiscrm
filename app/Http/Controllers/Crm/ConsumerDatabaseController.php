<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsumerEntryRequest;
use App\Models\ConsumerApplication;
use App\Services\ConsumerDatabaseWorkspaceService;
use App\Services\ConsumerEntryService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ConsumerDatabaseController extends Controller
{
    public function __construct(
        private readonly ConsumerDatabaseWorkspaceService $workspace,
        private readonly ConsumerEntryService $entry,
    ) {}

    public function index(Request $request): View
    {
        return view('crm.consumer-workspace.index', [...$this->workspace->index($request->user(), $request), 'processTitle' => null]);
    }

    public function create(Request $request): View
    {
        return view('crm.consumer-workspace.create', [
            'branches' => app(WorkspaceAccessService::class)->accessibleBranches($request->user()),
            'projects' => app(WorkspaceAccessService::class)->accessibleProjects($request->user()),
        ]);
    }

    public function store(ConsumerEntryRequest $request): RedirectResponse|JsonResponse
    {
        $application = $this->entry->create($request->validated(), $request->user());
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'data' => ['id' => $application->id, 'id_transaksi' => $application->id_transaksi]], 201);
        }

        return redirect()->route('consumer-database.workspace.show', $application)->with('success', 'Data konsumen berhasil disimpan.');
    }

    public function show(Request $request, ConsumerApplication $consumerApplication): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => $this->workspace->detail($request->user(), $consumerApplication),
        ]);
    }
}
