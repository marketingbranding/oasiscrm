<?php

namespace App\Services;

use App\Exceptions\AiProviderException;
use App\Models\AiChatConversation;
use App\Models\DanaTalanganSyncStatus;
use App\Models\DatabaseSheetSyncStatus;
use App\Models\KonsumenProgressSyncStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AiAssistantService
{
    public function __construct(
        private readonly AiProviderService $provider,
        private readonly AiToolRegistry $tools,
    ) {}

    public function reply(User $user, string $message, ?AiChatConversation $conversation = null): array
    {
        $context = $this->contextFromConversation($conversation);
        $toolResults = $this->inferAndExecute($message, $user, $context);

        if ($toolResults !== []) {
            $content = $this->localAnswer($message, $toolResults, $user);

            return [
                'content' => $content !== '' ? $content : 'Saya belum bisa menemukan data yang relevan untuk pertanyaan itu.',
                'provider' => 'local',
                'model' => 'tools',
                'tool_results' => $toolResults,
                'actions' => $this->resolveSyncActions($toolResults, $user),
            ];
        }

        try {
            $messages = $this->baseMessages($user, $conversation);
            $messages[] = ['role' => 'user', 'content' => $message];
            $response = $this->provider->chat($messages);
            $content = trim((string) ($response['message']['content'] ?? ''));

            if ($content !== '') {
                return [
                    'content' => $content,
                    'provider' => $response['provider'] ?? 'provider',
                    'model' => $response['model'] ?? null,
                    'tool_results' => [],
                    'actions' => [],
                ];
            }
        } catch (AiProviderException) {
            // Fall through to deterministic local guidance when providers are unavailable.
        }

        return [
            'content' => $this->unsupportedAnswer(),
            'provider' => 'local',
            'model' => 'tools',
            'tool_results' => [],
            'actions' => [],
        ];
    }

    private function resolveSyncActions(array $toolResults, User $user): array
    {
        $actions = [];

        foreach ($toolResults as $toolResult) {
            $name = $toolResult['name'] ?? null;
            $branchId = $this->syncBranchId($toolResult, $user);

            $modules = match ($name) {
                'count_by_stage' => ['konsumen_progress'],
                'get_dana_talangan_summary' => ['dana_talangan'],
                'search_customer' => ['database', 'konsumen_progress', 'dana_talangan'],
                'get_today_summary' => ['database', 'konsumen_progress', 'dana_talangan'],
                default => [],
            };

            foreach ($modules as $module) {
                $action = $this->syncActionForModule($module, $branchId);
                if ($action && ! collect($actions)->contains(fn ($existing) => ($existing['key'] ?? null) === $action['key'])) {
                    $actions[] = $action;
                }
            }
        }

        return $actions;
    }

    private function syncBranchId(array $toolResult, User $user): ?int
    {
        if (! $user->canViewAllBranches()) {
            return $user->branch_id;
        }

        return filled($toolResult['arguments']['branch_id'] ?? null) ? (int) $toolResult['arguments']['branch_id'] : null;
    }

    private function syncActionForModule(string $module, ?int $branchId): ?array
    {
        $status = match ($module) {
            'database' => $branchId ? $this->latestBranchSync(DatabaseSheetSyncStatus::class, 'database_sheet_sync_statuses', $branchId) : null,
            'konsumen_progress' => $branchId ? $this->latestBranchSync(KonsumenProgressSyncStatus::class, 'konsumen_progress_sync_statuses', $branchId) : null,
            'dana_talangan' => $this->latestGlobalSync(DanaTalanganSyncStatus::class, 'dana_talangan_sync_statuses'),
            default => null,
        };

        if (! $this->syncIsStale($status)) {
            return null;
        }

        return match ($module) {
            'database' => [
                'key' => 'database',
                'label' => 'Sync Sekarang',
                'hint' => 'Database sheet belum sync atau sudah lewat '.config('ai.sync_stale_minutes', 5).' menit.',
                'route' => route('database.sync'),
                'payload' => array_filter(['branch_id' => $branchId]),
            ],
            'konsumen_progress' => [
                'key' => 'konsumen_progress',
                'label' => 'Sync Sekarang',
                'hint' => 'Konsumen Progress belum sync atau sudah lewat '.config('ai.sync_stale_minutes', 5).' menit.',
                'route' => route('konsumen-progress.sync'),
                'payload' => array_filter(['branch_id' => $branchId]),
            ],
            'dana_talangan' => [
                'key' => 'dana_talangan',
                'label' => 'Sync Sekarang',
                'hint' => 'Dana Talangan belum sync atau sudah lewat '.config('ai.sync_stale_minutes', 5).' menit.',
                'route' => route('dana-talangan.sync'),
                'payload' => [],
            ],
            default => null,
        };
    }

    /** @param class-string<Model> $model */
    private function latestBranchSync(string $model, string $table, int $branchId): ?Model
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        return $model::query()->where('branch_id', $branchId)->latest('finished_at')->first();
    }

    /** @param class-string<Model> $model */
    private function latestGlobalSync(string $model, string $table): ?Model
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        return $model::query()->latest('finished_at')->first();
    }

    private function syncIsStale(?Model $status): bool
    {
        if (! $status || ! $status->finished_at) {
            return true;
        }

        return $status->status !== 'success'
            || $status->finished_at->lt(now()->subMinutes((int) config('ai.sync_stale_minutes', 5)));
    }

    private function baseMessages(User $user, ?AiChatConversation $conversation): array
    {
        $branch = $user->branch?->name ?? 'Tidak ada cabang';
        $role = $user->role?->slug ?? 'tanpa role';
        $access = $user->canViewAllBranches() ? 'boleh melihat semua cabang' : 'hanya boleh melihat cabang sendiri';

        $messages = [[
            'role' => 'system',
            'content' => "Anda adalah Oasis AI, asisten read-only untuk Oasis CRM. Untuk pertanyaan data Oasis, wajib gunakan tools dan jangan mengarang angka, status, nama, cabang, penyebab, atau kesimpulan yang tidak ada di tool result. Jika data tidak tersedia, katakan data belum tersedia. User: {$user->name}. Role: {$role}. Cabang utama: {$branch}. Akses: {$access}. Hari ini: ".today()->toDateString().'.',
        ]];

        $history = collect($conversation?->messages ?? [])
            ->whereIn('role', ['user', 'assistant'])
            ->take(-1 * (int) config('ai.max_messages', 12));

        foreach ($history as $message) {
            $messages[] = [
                'role' => $message['role'],
                'content' => Str::limit((string) ($message['content'] ?? ''), 2000, ''),
            ];
        }

        return $messages;
    }

    private function inferAndExecute(string $message, User $user, array $context = []): array
    {
        return collect($this->tools->inferTools($message, $user, $context))
            ->map(fn (array $tool) => [
                'name' => $tool['name'],
                'arguments' => array_filter($tool['arguments'] ?? [], fn ($value) => filled($value)),
                'result' => $this->tools->execute($tool['name'], array_filter($tool['arguments'] ?? [], fn ($value) => filled($value)), $user),
            ])
            ->all();
    }

    private function contextFromConversation(?AiChatConversation $conversation): array
    {
        foreach (array_reverse($conversation?->messages ?? []) as $message) {
            $toolResult = collect($message['tool_results'] ?? [])
                ->first(fn ($result) => ($result['name'] ?? null) === 'count_by_stage');

            if ($toolResult) {
                return [
                    'stage' => $toolResult['arguments']['stage'] ?? $toolResult['result']['stage'] ?? null,
                    'branch_id' => $toolResult['arguments']['branch_id'] ?? null,
                ];
            }
        }

        return [];
    }

    private function synthesizeWithProvider(array $messages, array $toolResults, array $providerMeta, string $fallback): string
    {
        if ($toolResults === []) {
            return $fallback;
        }

        $messages[] = [
            'role' => 'system',
            'content' => 'Gunakan data JSON berikut untuk menjawab pertanyaan user. Jangan sebut JSON atau nama tool. Data: '.json_encode($toolResults, JSON_UNESCAPED_UNICODE),
        ];

        try {
            $response = $this->provider->chat($messages);

            return trim((string) ($response['message']['content'] ?? $fallback));
        } catch (AiProviderException) {
            return $fallback ?: $this->localAnswer('', $toolResults, auth()->user());
        }
    }

    private function localAnswer(string $message, array $toolResults, ?User $user): string
    {
        if ($toolResults === []) {
            return 'AI provider belum tersedia, dan saya belum bisa menentukan data yang harus dicari.';
        }

        $tool = $toolResults[0];
        $result = $tool['result'] ?? [];
        $branch = $result['branch'] ?? ($user?->branch?->name ?? 'cabangmu');

        return match ($tool['name']) {
            'count_by_stage' => 'Ada '.($result['count'] ?? 0).' data '.($result['stage'] ?? 'pipeline').' untuk '.$branch.'.',
            'get_content_schedule' => $this->localContentAnswer($result),
            'get_dana_talangan_summary' => $this->localDanaTalanganAnswer($result),
            'search_customer' => 'Ditemukan '.count($result['results'] ?? []).' hasil terkait "'.($result['query'] ?? $message).'" di '.$branch.'.',
            'get_branch_info' => $this->localBranchAnswer($result),
            'get_supported_capabilities' => $this->localCapabilitiesAnswer($result),
            'ask_clarification' => $result['message'] ?? 'Sebutkan cabang dan data yang ingin dicek.',
            default => 'Saya menemukan ringkasan data untuk '.$branch.'. Work Planner hari ini: '.($result['work_planner']['count'] ?? 0).' item; Dana Talangan: '.($result['dana_talangan']['count'] ?? 0).' data; Pipeline: '.($result['pipeline']['count'] ?? 0).' data.',
        };
    }

    private function unsupportedAnswer(): string
    {
        return 'Saya belum mengenali pertanyaan itu. Saat ini saya bisa membaca data cabang, Dana Talangan, Work Planner, Konsumen Progress/pipeline, Database hasil sync, dan pencarian konsumen. Coba tanya dengan menyebut modul, cabang, atau nama konsumen.';
    }

    private function localContentAnswer(array $result): string
    {
        $items = collect($result['items'] ?? []);
        if ($items->isEmpty()) {
            return 'Tidak ada jadwal '.($result['item_type'] ?? 'item').' pada periode '.$result['date_from'].' sampai '.$result['date_to'].' untuk '.$result['branch'].'.';
        }

        $byDate = $items->groupBy('date')->map(fn ($items, $date) => $date.': '.$items->pluck('title')->implode(', '))->values()->implode('; ');

        return 'Ada '.$items->count().' jadwal '.($result['item_type'] ?? 'item').' pada periode '.$result['date_from'].' sampai '.$result['date_to'].' untuk '.$result['branch'].'. '.$byDate.'.';
    }

    private function localDanaTalanganAnswer(array $result): string
    {
        $count = (int) ($result['count'] ?? 0);
        $branch = $result['branch'] ?? 'cabang terkait';
        $records = collect($result['records'] ?? []);
        $statuses = collect($result['by_status'] ?? [])->map(fn ($total, $status) => $status.': '.$total)->values()->implode(', ');

        if ($count === 0) {
            return 'Tidak ada data Dana Talangan untuk '.$branch.' berdasarkan filter tersebut.';
        }

        $answer = 'Ada '.$count.' data Dana Talangan untuk '.$branch.'.';
        if ($statuses !== '') {
            $answer .= ' Status: '.$statuses.'.';
        }
        $answer .= ' Belum konfirmasi keuangan: '.($result['needs_confirmation'] ?? 0).'.';

        if ($records->isNotEmpty()) {
            $names = $records->map(function (array $record) {
                $parts = array_filter([
                    $record['nama_konsumen'] ?? null,
                    $record['status'] ?? null ? 'status '.$record['status'] : null,
                    $record['kav'] ?? null ? 'kav '.$record['kav'] : null,
                    $record['project_name'] ?? null,
                ]);

                return implode(' - ', $parts);
            })->implode('; ');

            $answer .= ' Data: '.$names.'.';
        }

        return $answer;
    }

    private function localBranchAnswer(array $result): string
    {
        $branches = collect($result['branches'] ?? []);
        if ($branches->isEmpty()) {
            return 'Tidak ada data cabang yang bisa saya tampilkan untuk aksesmu.';
        }

        return 'Ada '.$branches->count().' cabang: '.$branches->pluck('name')->implode(', ').'.';
    }

    private function localCapabilitiesAnswer(array $result): string
    {
        $capabilities = collect($result['capabilities'] ?? [])->map(fn ($item) => '- '.$item)->implode("\n");
        $limitations = collect($result['limitations'] ?? [])->map(fn ($item) => '- '.$item)->implode("\n");

        return "Saya bisa membaca data berikut:\n{$capabilities}\n\nBatasan:\n{$limitations}";
    }
}
