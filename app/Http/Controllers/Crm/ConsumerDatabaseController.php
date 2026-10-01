<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ConsumerApplication;
use App\Services\ConsumerDatabaseWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ConsumerDatabaseController extends Controller
{
    public function __construct(private readonly ConsumerDatabaseWorkspaceService $workspace) {}

    public function index(Request $request): View
    {
        return view('crm.consumer-workspace.index', $this->workspace->index($request->user(), $request));
    }

    public function show(Request $request, ConsumerApplication $consumerApplication): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'data' => $this->workspace->detail($request->user(), $consumerApplication),
        ]);
    }
}
