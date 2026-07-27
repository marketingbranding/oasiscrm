<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\DatabaseSheetRecord;
use App\Models\SalesLead;
use App\Models\UserPresence;
use App\Services\WorkspaceAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PresenceController extends Controller
{
    private const RECORD_TYPES = [
        'dana_talangan' => DanaTalangan::class,
        'content_item' => ContentItem::class,
        'database_sheet_record' => DatabaseSheetRecord::class,
        'sales_lead' => SalesLead::class,
    ];

    public function __construct(private readonly WorkspaceAccessService $workspaceAccess) {}

    public function heartbeat(Request $request): JsonResponse
    {
        abort_unless(config('presence.enabled', true), 404);
        $data = $this->validatedContext($request, true);
        [$branchId, $record] = $this->authorizeContext($request, $data);
        if ($data['mode'] === 'editing') {
            abort_unless($branchId && $this->workspaceAccess->canEditBranch($request->user(), $branchId), 403);
            abort_unless(! $record || $this->recordBranchId($record) === $branchId, 403);
        }

        UserPresence::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'session_key' => $data['session_key'],
                'page_key' => $data['page_key'],
                'context_key' => $this->contextKey($data),
            ],
            [
                'branch_id' => $branchId,
                'record_type' => $data['record_type'] ?? null,
                'record_id' => $data['record_id'] ?? null,
                'mode' => $data['mode'],
                'last_seen_at' => now(),
            ]
        );

        return response()->json(['ok' => true, 'last_seen_at' => now()->toIso8601String()]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(config('presence.enabled', true), 404);
        $data = $this->validatedContext($request, false);
        [$branchId] = $this->authorizeContext($request, $data);

        $presences = UserPresence::query()
            ->join('users', 'users.id', '=', 'user_presences.user_id')
            ->where('users.is_active', true)
            ->where('user_presences.page_key', $data['page_key'])
            ->where('user_presences.last_seen_at', '>=', now()->subSeconds((int) config('presence.offline_seconds', 60)))
            ->when($branchId, fn ($query) => $query->where('user_presences.branch_id', $branchId), fn ($query) => $query->whereNull('user_presences.branch_id'))
            ->when($data['record_type'] ?? null, fn ($query, $type) => $query->where('user_presences.record_type', $type)->where('user_presences.record_id', $data['record_id']))
            ->when(! ($data['record_type'] ?? null), fn ($query) => $query->whereNull('user_presences.record_type')->whereNull('user_presences.record_id'))
            ->orderByDesc('user_presences.last_seen_at')
            ->limit(100)
            ->get([
                'user_presences.user_id',
                'users.name as display_name',
                'user_presences.branch_id',
                'user_presences.page_key',
                'user_presences.record_type',
                'user_presences.record_id',
                'user_presences.mode',
                'user_presences.last_seen_at',
            ])
            ->unique('user_id')
            ->take(25)
            ->map(fn ($presence) => [
                'user_id' => (int) $presence->user_id,
                'display_name' => $presence->display_name,
                'branch_id' => $presence->branch_id ? (int) $presence->branch_id : null,
                'page_key' => $presence->page_key,
                'record_type' => $presence->record_type,
                'record_id' => $presence->record_id ? (int) $presence->record_id : null,
                'mode' => $presence->mode,
                'last_seen_at' => $presence->last_seen_at,
                'is_current_user' => (int) $presence->user_id === $request->user()->id,
            ])->values();

        return response()->json(['ok' => true, 'presences' => $presences]);
    }

    public function destroy(Request $request): JsonResponse
    {
        abort_unless(config('presence.enabled', true), 404);
        $data = $this->validatedContext($request, true);
        $deleted = UserPresence::where('user_id', $request->user()->id)
            ->where('session_key', $data['session_key'])
            ->where('page_key', $data['page_key'])
            ->where('context_key', $this->contextKey($data))
            ->delete();

        return response()->json(['ok' => true, 'deleted' => $deleted]);
    }

    private function validatedContext(Request $request, bool $requireSession): array
    {
        return $request->validate([
            'page_key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'branch_id' => ['nullable', 'integer'],
            'record_type' => ['nullable', 'required_with:record_id', Rule::in(array_keys(self::RECORD_TYPES))],
            'record_id' => ['nullable', 'integer', 'min:1', 'required_with:record_type'],
            'mode' => [$requireSession ? 'required' : 'nullable', Rule::in(['viewing', 'editing', 'idle'])],
            'session_key' => [$requireSession ? 'required' : 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);
    }

    private function authorizeContext(Request $request, array $data): array
    {
        $user = $request->user();
        if ($user->isSales()) {
            abort_unless(in_array($data['page_key'], ['sales-pocketbook', 'work-planner'], true), 403);
            abort_unless(! isset($data['record_type']) || in_array($data['record_type'], ['sales_lead', 'content_item'], true), 403);
        }
        $branch = null;
        if (filled($data['branch_id'] ?? null)) {
            $branch = $this->workspaceAccess->resolveRequestedBranch($user, $data['branch_id']);
            abort_unless($branch, 403);
        } elseif (! $user->canViewAllBranches()) {
            $branch = $this->workspaceAccess->resolveRequestedBranch($user, null);
            abort_unless($branch, 403);
        }

        $record = null;
        if (filled($data['record_type'] ?? null)) {
            $model = self::RECORD_TYPES[$data['record_type']];
            $record = $model::findOrFail($data['record_id']);
            $recordBranchId = $this->recordBranchId($record);
            abort_unless($recordBranchId && $this->workspaceAccess->canViewBranch($user, $recordBranchId), 403);
            if ($record instanceof ContentItem) {
                Gate::forUser($user)->authorize(($data['mode'] ?? 'viewing') === 'editing' ? 'update' : 'view', $record);
            }
            if ($record instanceof SalesLead) {
                Gate::forUser($user)->authorize(($data['mode'] ?? 'viewing') === 'editing' ? 'update' : 'view', $record);
            }
            if ($branch && $recordBranchId !== $branch->id) {
                abort(403);
            }
            $branch ??= $this->workspaceAccess->resolveRequestedBranch($user, $recordBranchId);
        }

        return [$branch?->id, $record];
    }

    private function recordBranchId(Model $record): ?int
    {
        return filled($record->branch_id) ? (int) $record->branch_id : null;
    }

    private function contextKey(array $data): string
    {
        return filled($data['record_type'] ?? null)
            ? $data['record_type'].':'.$data['record_id']
            : 'page';
    }
}
