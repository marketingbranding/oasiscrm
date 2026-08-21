<?php

namespace App\Http\Controllers\Crm\ConsumerDatabase;

use App\Http\Controllers\Controller;
use App\Services\ConsumerApplicationQueryService;
use App\Services\DatabaseModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}
