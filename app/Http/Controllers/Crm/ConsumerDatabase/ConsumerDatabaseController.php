<?php

namespace App\Http\Controllers\Crm\ConsumerDatabase;

use App\Http\Controllers\Controller;
use App\Services\ConsumerApplicationQueryService;
use App\Services\ConsumerDatabaseWriteService;
use App\Services\DatabaseModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

final class ConsumerDatabaseController extends Controller
{
    public function root(): RedirectResponse
    {
        return redirect()->route('consumer-database.module', ['module' => 'data-konsumen']);
    }

    public function module(Request $request, string $module, DatabaseModuleRegistry $registry, ConsumerApplicationQueryService $query): View
    {
        abort_unless(in_array($module, $registry->slugs(), true), 404);
        $view = in_array($request->query('view'), ['table', 'sheet'], true) ? $request->query('view') : 'table';

        return view('crm.consumer-database.index', $query->dataset($request->user(), $module, $request) + [
            'registry' => $registry->all(),
            'moduleRegistry' => $registry,
            'moduleSlug' => $module,
            'viewMode' => $view,
        ]);
    }

    public function updateCell(Request $request, string $module, int $application, ConsumerDatabaseWriteService $writer): JsonResponse
    {
        $validator = Validator::make($request->all(), ['column' => ['required', 'string'], 'expected_updated_at' => ['required', 'date']]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Nilai tidak valid.', 'errors' => $validator->errors()], 422);
        }

        return response()->json($writer->update($request->user(), $module, $application, $request->all()));
    }
}
