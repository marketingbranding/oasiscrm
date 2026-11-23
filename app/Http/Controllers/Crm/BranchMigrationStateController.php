<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchMigrationState;
use App\Services\BranchMigrationStateService;
use Illuminate\Http\Request;

class BranchMigrationStateController extends Controller
{
    public function __construct(private readonly BranchMigrationStateService $states) {}

    public function index()
    {
        abort_unless(request()->user()?->isSuperadmin(), 403);

        $branches = Branch::query()->with(['migrationState.changedBy'])->orderBy('name')->paginate(25);

        return view('crm.branch-migration-states.index', compact('branches'));
    }

    public function update(Request $request, Branch $branch)
    {
        abort_unless($request->user()?->isSuperadmin(), 403);

        $data = $request->validate([
            'expected_state' => ['required', 'string', 'in:'.implode(',', BranchMigrationState::STATES)],
            'state' => ['required', 'string', 'in:'.implode(',', BranchMigrationState::STATES)],
            'reason' => ['required', 'string', 'max:1000', 'not_regex:/^\s*$/'],
        ]);

        $this->states->transition($request->user(), $branch, $data['expected_state'], $data['state'], $data['reason']);

        return redirect()->route('admin.branch-migration-states.index')->with('success', 'Status migrasi cabang diperbarui.');
    }
}
