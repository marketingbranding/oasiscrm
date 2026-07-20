<?php

namespace App\Services;

use App\Exceptions\AiProviderException;
use App\Models\AiChatConversation;
use App\Models\DanaTalanganSyncStatus;
use App\Models\DatabaseSheetSyncStatus;
use App\Models\KonsumenProgressSyncStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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
        $routingMode = Str::lower(trim((string) config('ai.routing_mode', 'hybrid')));
        if ($routingMode === 'hybrid') {
            $toolResults = $this->inferAndExecute($message, $user, $context);
            if ($toolResults !== []) {
                if (config('ai.synthesize_tool_results', false) && ($toolResults[0]['name'] ?? null) !== 'count_by_stage') {
                    try {
                        $messages = $this->baseMessages($user, $conversation);
                        $messages[] = ['role' => 'user', 'content' => $message];
                        $messages[] = [
                            'role' => 'system',
                            'content' => 'Jawab ringkas memakai data tool ter-sanitasi berikut. Jangan menambah nilai yang tidak ada di data: '.json_encode($toolResults, JSON_UNESCAPED_UNICODE),
                        ];
                        $response = $this->provider->chat($messages);
                        $content = trim((string) ($response['message']['content'] ?? ''));

                        if ($content !== '') {
                            return [
                                'content' => $content,
                                'provider' => $response['provider'] ?? 'provider',
                                'model' => $response['model'] ?? null,
                                'tool_results' => $toolResults,
                                'actions' => $this->resolveSyncActions($toolResults, $user),
                            ];
                        }
                    } catch (AiProviderException) {
                        // Keep deterministic local result if optional synthesis is unavailable.
                    }
                }

                return [
                    'content' => $this->localAnswer($message, $toolResults, $user),
                    'provider' => 'local',
                    'model' => 'tools',
                    'tool_results' => $toolResults,
                    'actions' => $this->resolveSyncActions($toolResults, $user),
                ];
            }
        }

        try {
            $this->traceProviderRoute($routingMode, null, []);
            $messages = $this->baseMessages($user, $conversation);
            $messages[] = ['role' => 'user', 'content' => $message];

            $response = $this->provider->chat($messages, $this->tools->definitions());
            $toolResults = $this->executeProviderToolCalls($response['message'] ?? [], $user);

            if ($toolResults !== []) {
                $messages[] = $this->assistantToolCallMessage($response['message']);
                foreach ($toolResults as $toolResult) {
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolResult['tool_call_id'],
                        'name' => $toolResult['name'],
                        'content' => json_encode($toolResult['result'], JSON_UNESCAPED_UNICODE),
                    ];
                }

                try {
                    $finalResponse = $this->provider->chat($messages, $this->tools->definitions());
                    $content = trim((string) ($finalResponse['message']['content'] ?? ''));

                    return [
                        'content' => $content !== '' ? $content : $this->localAnswer($message, $toolResults, $user),
                        'provider' => $finalResponse['provider'] ?? ($response['provider'] ?? 'provider'),
                        'model' => $finalResponse['model'] ?? ($response['model'] ?? null),
                        'tool_results' => $toolResults,
                        'actions' => $this->resolveSyncActions($toolResults, $user),
                    ];
                } catch (AiProviderException) {
                    return [
                        'content' => $this->localAnswer($message, $toolResults, $user),
                        'provider' => 'local',
                        'model' => 'tools',
                        'tool_results' => $toolResults,
                        'actions' => $this->resolveSyncActions($toolResults, $user),
                    ];
                }
            }

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
            $toolResults = $this->inferAndExecute($message, $user, $context);

            if ($toolResults !== []) {
                return [
                    'content' => $this->localAnswer($message, $toolResults, $user),
                    'provider' => 'local',
                    'model' => 'tools',
                    'tool_results' => $toolResults,
                    'actions' => $this->resolveSyncActions($toolResults, $user),
                ];
            }
        }

        return [
            'content' => $this->unsupportedAnswer(),
            'provider' => 'local',
            'model' => 'tools',
            'tool_results' => [],
            'actions' => [],
        ];
    }

    private function executeProviderToolCalls(array $message, User $user): array
    {
        $allowed = $this->tools->allowedToolNames();
        $seen = [];

        return collect($message['tool_calls'] ?? [])
            ->filter(fn ($call) => ($call['type'] ?? null) === 'function')
            ->take(max(0, (int) config('ai.max_tool_calls', 3)))
            ->filter(function (array $call) use ($allowed, &$seen) {
                $name = (string) ($call['function']['name'] ?? '');
                if (! in_array($name, $allowed, true)) {
                    return false;
                }

                $decoded = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                $arguments = is_array($decoded) ? array_filter($decoded, fn ($value) => filled($value)) : [];
                $key = $name.'|'.json_encode($arguments, JSON_UNESCAPED_UNICODE);
                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            })
            ->map(function (array $call) use ($user) {
                $name = (string) ($call['function']['name'] ?? '');
                $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                if (! is_array($arguments)) {
                    $arguments = [];
                }

                $arguments = array_filter($arguments, fn ($value) => filled($value));
                $this->traceProviderRoute(
                    Str::lower(trim((string) config('ai.routing_mode', 'hybrid'))),
                    $name,
                    $arguments
                );

                return [
                    'tool_call_id' => $call['id'] ?? Str::uuid()->toString(),
                    'name' => $name,
                    'arguments' => $arguments,
                    'result' => $this->tools->execute($name, $arguments, $user),
                ];
            })
            ->values()
            ->all();
    }

    private function assistantToolCallMessage(array $message): array
    {
        return [
            'role' => 'assistant',
            'content' => $message['content'] ?? null,
            'tool_calls' => $message['tool_calls'] ?? [],
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

        $branchId = $toolResult['arguments']['branch_id'] ?? $toolResult['result']['branch_id'] ?? null;

        return filled($branchId) ? (int) $branchId : null;
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

        if ($status?->status === 'running') {
            return null;
        }

        $failure = $status?->status === 'failed' ? ' Sync terakhir gagal; silakan coba lagi.' : '';

        return match ($module) {
            'database' => [
                'key' => 'database',
                'label' => 'Sync Sekarang',
                'method' => 'POST',
                'url' => route('database.sync'),
                'hint' => 'Database sheet belum sync atau sudah lewat '.config('ai.sync_stale_minutes', 5).' menit.'.$failure,
                'success_message' => 'Sync Database berhasil. Silakan ulangi pertanyaan untuk membaca data terbaru.',
                'payload' => array_filter(['branch_id' => $branchId]),
            ],
            'konsumen_progress' => [
                'key' => 'konsumen_progress',
                'label' => 'Sync Sekarang',
                'method' => 'POST',
                'url' => route('konsumen-progress.sync'),
                'hint' => 'Konsumen Progress belum sync atau sudah lewat '.config('ai.sync_stale_minutes', 5).' menit.'.$failure,
                'success_message' => 'Sync Konsumen Progress berhasil. Silakan ulangi pertanyaan untuk membaca data terbaru.',
                'payload' => array_filter(['branch_id' => $branchId]),
            ],
            'dana_talangan' => [
                'key' => 'dana_talangan',
                'label' => 'Sync Sekarang',
                'method' => 'POST',
                'url' => route('dana-talangan.sync'),
                'hint' => 'Dana Talangan belum sync atau sudah lewat '.config('ai.sync_stale_minutes', 5).' menit.'.$failure,
                'success_message' => 'Sync Dana Talangan berhasil. Silakan ulangi pertanyaan untuk membaca data terbaru.',
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
            'content' => "Anda adalah Oasis AI, asisten read-only untuk Oasis CRM. Jawab natural dalam bahasa Indonesia yang ringkas. Untuk obrolan umum, boleh menjawab langsung sebagai asisten CRM. Untuk pertanyaan data Oasis (Database, Konsumen Progress/pipeline, Dana Talangan, Work Planner, cabang, atau pencarian konsumen), wajib gunakan tools yang tersedia dan jangan mengarang angka, status, nama, cabang, penyebab, atau kesimpulan yang tidak ada di tool result. Jika data tidak tersedia, katakan data belum tersedia dan sarankan Sync Sekarang bila action sync muncul di UI. User: {$user->name}. Role: {$role}. Cabang utama: {$branch}. Akses: {$access}. Hari ini: ".today()->toDateString().'.',
        ]];

        $history = collect($conversation?->messages ?? [])
            ->whereIn('role', ['user', 'assistant'])
            ->take(-1 * (int) config('ai.max_context_messages', 12));

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
                ->first(fn ($result) => in_array($result['name'] ?? null, ['count_by_stage', 'search_customer'], true));

            if ($toolResult) {
                if (($toolResult['name'] ?? null) === 'search_customer') {
                    return [
                        'search_query' => $toolResult['arguments']['query'] ?? $toolResult['result']['query'] ?? null,
                        'branch_id' => $toolResult['arguments']['branch_id'] ?? $toolResult['result']['branch_id'] ?? null,
                    ];
                }

                return [
                    'stage' => $this->tools->canonicalStage($toolResult['arguments']['stage'] ?? $toolResult['result']['stage'] ?? null),
                    'branch_id' => $toolResult['arguments']['branch_id'] ?? $toolResult['result']['branch_id'] ?? null,
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
        if (isset($result['error'])) {
            return (string) $result['error'];
        }
        $branch = $result['branch'] ?? ($user?->branch?->name ?? 'cabangmu');

        $answer = match ($tool['name']) {
            'count_by_stage' => 'Ada '.($result['count'] ?? 0).' data '.($result['stage_label'] ?? $result['stage'] ?? 'pipeline').' untuk '.$branch.'.',
            'get_content_schedule' => $this->localContentAnswer($result),
            'get_dana_talangan_summary' => $this->localDanaTalanganAnswer($result),
            'search_customer' => $this->localCustomerSearchAnswer($result, $message),
            'get_branch_info' => $this->localBranchAnswer($result),
            'get_supported_capabilities' => $this->localCapabilitiesAnswer($result),
            'ask_clarification' => $result['message'] ?? 'Sebutkan cabang dan data yang ingin dicek.',
            default => 'Saya menemukan ringkasan data untuk '.$branch.'. Work Planner hari ini: '.($result['work_planner']['count'] ?? 0).' item; Dana Talangan: '.($result['dana_talangan']['count'] ?? 0).' data; Pipeline: '.($result['pipeline']['count'] ?? 0).' data.',
        };

        if (($result['is_stale'] ?? false) && filled($result['last_synced_at'] ?? null)) {
            $answer .= ' Data berdasarkan sync terakhir '.Carbon::parse($result['last_synced_at'])->format('d M Y H:i').', bukan real-time.';
        } elseif (($result['sync_status'] ?? null) === 'never_synced') {
            $answer .= ' Data belum pernah sync; klik Sync Sekarang untuk memperbarui cache.';
        }

        return $answer;
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

    private function localCustomerSearchAnswer(array $result, string $message): string
    {
        $count = count($result['results'] ?? []);
        $query = trim((string) ($result['query'] ?? $message));
        $branch = $result['branch'] ?? 'cabang terkait';
        if ($count > 0) {
            return 'Ditemukan '.$count.' hasil terkait '.$query.' di cabang '.$branch.'.';
        }

        $isStale = collect($result['freshness'] ?? [])->contains(fn ($freshness) => (bool) ($freshness['is_stale'] ?? false));
        if ($isStale) {
            return 'Tidak ditemukan konsumen atas nama '.$query.' di cabang '.$branch.' berdasarkan sync terakhir. Data mungkin belum terbaru.';
        }

        return 'Tidak ditemukan konsumen atas nama '.$query.' di cabang '.$branch.' berdasarkan data sinkronisasi saat ini.';
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

    private function traceProviderRoute(string $routingMode, ?string $toolName, array $arguments): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        Log::debug('Oasis AI routing trace', [
            'routing_mode' => $routingMode,
            'message_intent' => $toolName ? 'provider_tool' : 'ambiguous',
            'tool_name' => $toolName,
            'stage_argument' => $arguments['stage'] ?? null,
            'branch_id' => $arguments['branch_id'] ?? null,
            'route_source' => 'provider',
            'synthesis_enabled' => (bool) config('ai.synthesize_tool_results', false),
        ]);
    }
}
