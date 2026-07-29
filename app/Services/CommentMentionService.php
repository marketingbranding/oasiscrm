<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommentMentionService
{
    private const MODULES = [
        'sales-lead' => 'sales_pocketbook',
        'sales-agenda' => 'sales_pocketbook',
        'planner-item' => 'work_planner',
        'expense' => 'expenses',
        'bridge-fund' => 'bridge_fund',
    ];

    public function __construct(
        private readonly CommentableAccessService $access,
        private readonly OrganizationScopeService $scope,
        private readonly ReportingHierarchyService $hierarchy,
    ) {}

    /** @return Collection<int, User> */
    public function validate(User $viewer, Model $target, array $userIds): Collection
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        if (! $viewer->hasPermission('comments.view') || ! $viewer->hasPermission('comments.mention')) {
            throw $this->validationError('Anda tidak memiliki izin untuk menyebut pengguna.');
        }

        $candidateIds = array_flip($this->candidateIds($viewer, $target));
        $users = User::query()
            ->with(['role:id,name,slug,is_superadmin', 'role.permissions:id,slug', 'branch:id,name,code'])
            ->whereIntegerInRaw('id', $ids->all())
            ->where('is_active', true)
            ->where('account_status', AccountStatus::Active->value)
            ->get()
            ->filter(fn (User $user) => isset($candidateIds[$user->id]) && $this->access->canView($user, $target))
            ->keyBy('id');

        if ($users->count() !== $ids->count()) {
            throw $this->validationError('Satu atau beberapa pengguna yang disebut tidak dapat mengakses data ini.');
        }

        return $ids->map(fn (int $id) => $users->get($id));
    }

    /** @return array<int, array{id: int, name: string, role: ?string, context: ?string, initials: string}> */
    public function search(User $viewer, Model $target, ?string $search = null): array
    {
        $query = User::query()
            ->with(['role:id,name,slug,is_superadmin', 'role.permissions:id,slug', 'branch:id,name,code'])
            ->whereIntegerInRaw('id', $this->candidateIds($viewer, $target))
            ->where('is_active', true)
            ->where('account_status', AccountStatus::Active->value);

        $search = trim((string) $search);
        if ($search !== '') {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }

        $users = collect();
        $query->orderBy('name')->orderBy('id')->chunk(100, function (Collection $chunk) use ($target, $users): bool {
            foreach ($chunk as $user) {
                if ($this->access->canView($user, $target)) {
                    $users->push($user);
                }
                if ($users->count() === 10) {
                    return false;
                }
            }

            return true;
        });

        $projectId = $this->access->projectId($target);
        $projectName = $projectId ? DB::table('lead_master')->where('id', $projectId)->value('project_name') : null;
        $projectUserIds = $projectId ? $this->currentProjectUserIds($projectId) : [];

        return $users->map(function (User $user) use ($projectName, $projectUserIds): array {
            $contexts = array_filter([
                $user->branch?->code ?: $user->branch?->name,
                in_array((int) $user->id, $projectUserIds, true) ? Str::limit($projectName, 30) : null,
            ]);

            return [
                'id' => (int) $user->id,
                'name' => $user->name,
                'role' => $user->role?->name,
                'context' => $contexts === [] ? null : implode(' / ', $contexts),
                'initials' => Str::of($user->name)->explode(' ')->filter()->take(2)
                    ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode(''),
            ];
        })->all();
    }

    /** @return array<int> */
    private function candidateIds(User $viewer, Model $target): array
    {
        $alias = $this->access->aliasFor($target);
        $module = $alias ? self::MODULES[$alias] ?? null : null;
        if (! $module) {
            return [];
        }

        $ids = $this->scope->visibleUserIds($viewer, $module);
        $ids[] = (int) $viewer->id;
        if ($viewer->supervisor_user_id) {
            $ids[] = (int) $viewer->supervisor_user_id;
        }
        $ids = [...$ids, ...$this->hierarchy->descendantIds($viewer)];

        $projectId = $this->access->projectId($target);
        if ($projectId && in_array($projectId, $this->scope->projectIds($viewer, $module), true)) {
            $ids = [...$ids, ...$this->currentProjectUserIds($projectId)];
        }

        return collect($ids)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return array<int> */
    private function currentProjectUserIds(int $projectId): array
    {
        $today = today()->toDateString();

        return DB::table('project_user')
            ->where('project_id', $projectId)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('assignment_start_date')->orWhereDate('assignment_start_date', '<=', $today))
            ->where(fn ($query) => $query->whereNull('assignment_end_date')->orWhereDate('assignment_end_date', '>=', $today))
            ->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function validationError(string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => ['mentioned_user_ids' => [$message]],
        ], 422));
    }
}
