<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchMigrationState;
use App\Models\ConsumerParitySnapshot;
use App\Services\BranchMigrationStateService;
use App\Services\SystemAdministrationAuthorizationService;
use Illuminate\Http\Request;

class BranchMigrationStateController extends Controller
{
    public function __construct(private readonly BranchMigrationStateService $states) {}

    public function index()
    {
        abort_unless(request()->user()?->isSuperadmin(), 403);

        $branches = Branch::query()->with(['migrationState.changedBy'])->orderBy('name')->paginate(25);
        $branchIds = $branches->getCollection()->pluck('id');
        $paritySnapshots = ConsumerParitySnapshot::query()->whereIn('branch_id', $branchIds)->latest('checked_at')->get()->unique('branch_id')->keyBy('branch_id');
        $migrationOverrides = ActivityLog::query()
            ->where('event', 'branch_migration_state_changed')
            ->whereIn('subject_id', $branchIds)
            ->latest('id')
            ->get()
            ->unique('subject_id')
            ->filter(fn (ActivityLog $log): bool => (bool) data_get($log->properties, 'parity_override', false))
            ->mapWithKeys(fn (ActivityLog $log): array => [(int) $log->subject_id => true]);

        return view('crm.branch-migration-states.index', compact('branches', 'paritySnapshots', 'migrationOverrides'));
    }

    public function update(Request $request, Branch $branch)
    {
        abort_unless($request->user()?->isSuperadmin(), 403);

        $data = $request->validate([
            'expected_state' => ['required', 'string', 'in:'.implode(',', BranchMigrationState::STATES)],
            'state' => ['required', 'string', 'in:'.implode(',', BranchMigrationState::STATES)],
            'reason' => ['required', 'string', 'max:1000', 'not_regex:/^\s*$/'],
            'override' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer'],
        ]);
        $override = (bool) ($data['override'] ?? false);
        if ($override && ! ($data['expected_state'] === BranchMigrationState::STATE_PARALEL && $data['state'] === BranchMigrationState::STATE_OASIS_FULL)) {
            abort(422, 'Override hanya tersedia untuk cutover paralel ke oasis_penuh.');
        }
        if ($override && ! app(SystemAdministrationAuthorizationService::class)->canManage($request->user(), SystemAdministrationAuthorizationService::SETTINGS)) {
            abort(403, 'Override cutover hanya boleh dilakukan pemilik produk.');
        }

        $this->states->transition($request->user(), $branch, $data['expected_state'], $data['state'], $data['reason'], $override);

        return redirect()->route('admin.branch-migration-states.index')->with('success', 'Status migrasi cabang diperbarui.');
    }
}
