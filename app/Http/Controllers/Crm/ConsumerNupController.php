<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConsumerNupRequest;
use App\Models\ConsumerNup;
use App\Services\ConsumerNupService;
use App\Services\WorkspaceAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsumerNupController extends Controller
{
    public function __construct(private readonly ConsumerNupService $nups) {}

    public function index(Request $request): View
    {
        $query = $this->nups->visibleQuery($request->user());
        $search = trim($request->string('search')->toString());
        $query->when($search !== '', fn ($builder) => $builder->where(function ($nested) use ($search): void {
            $nested->where('nup_number', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }));

        return view('crm.nups.index', ['nups' => $query->latest('registered_at')->latest('id')->paginate(25)->withQueryString(), 'search' => $search]);
    }

    public function create(Request $request): View
    {
        $workspace = app(WorkspaceAccessService::class);

        return view('crm.nups.create', ['branches' => $workspace->accessibleBranches($request->user()), 'projects' => $workspace->accessibleProjects($request->user())]);
    }

    public function store(ConsumerNupRequest $request): RedirectResponse|JsonResponse
    {
        $nup = $this->nups->create($request->validated(), $request->user());
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'data' => ['id' => $nup->id]], 201);
        }

        return redirect()->route('consumer-nups.index')->with('success', 'NUP berhasil dicatat.');
    }

    public function convert(ConsumerNupRequest $request, ConsumerNup $consumerNup): RedirectResponse|JsonResponse
    {
        $application = $this->nups->convert($consumerNup, $request->validated(), $request->user());
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'data' => ['id' => $application->id, 'id_transaksi' => $application->id_transaksi]]);
        }

        return redirect()->route('consumer-database.workspace.show', $application)->with('success', 'NUP dilanjutkan menjadi konsumen.');
    }
}
