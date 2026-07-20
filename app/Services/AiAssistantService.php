<?php

namespace App\Services;

use App\Exceptions\AiProviderException;
use App\Models\AiChatConversation;
use App\Models\User;
use Illuminate\Support\Str;

class AiAssistantService
{
    public function __construct(
        private readonly AiProviderService $provider,
        private readonly AiToolRegistry $tools,
    ) {}

    public function reply(User $user, string $message, ?AiChatConversation $conversation = null): array
    {
        $messages = $this->baseMessages($user, $conversation);
        $messages[] = ['role' => 'user', 'content' => $message];

        $toolResults = [];
        $providerMeta = ['provider' => 'local', 'model' => 'tools'];

        try {
            $first = $this->provider->chat($messages, $this->tools->definitions());
            $providerMeta = ['provider' => $first['provider'], 'model' => $first['model']];
            $assistantMessage = $first['message'];

            if (! empty($assistantMessage['tool_calls'])) {
                $messages[] = $assistantMessage;
                foreach (array_slice($assistantMessage['tool_calls'], 0, 3) as $toolCall) {
                    $name = $toolCall['function']['name'] ?? '';
                    $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
                    $result = $this->tools->execute($name, $arguments, $user);
                    $toolResults[] = ['name' => $name, 'arguments' => $arguments, 'result' => $result];
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'] ?? $name,
                        'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                    ];
                }

                $content = $this->localAnswer($message, $toolResults, $user);
            } else {
                $toolResults = $this->inferAndExecute($message, $user);
                $content = $toolResults !== []
                    ? $this->localAnswer($message, $toolResults, $user)
                    : $this->unsupportedAnswer();
            }
        } catch (AiProviderException) {
            $toolResults = $this->inferAndExecute($message, $user);
            $content = $toolResults !== [] ? $this->localAnswer($message, $toolResults, $user) : $this->unsupportedAnswer();
        }

        $content = $content !== '' ? $content : 'Saya belum bisa menemukan data yang relevan untuk pertanyaan itu.';

        return [
            'content' => $content,
            'provider' => $providerMeta['provider'],
            'model' => $providerMeta['model'],
            'tool_results' => $toolResults,
        ];
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

    private function inferAndExecute(string $message, User $user): array
    {
        return collect($this->tools->inferTools($message, $user))
            ->map(fn (array $tool) => [
                'name' => $tool['name'],
                'arguments' => array_filter($tool['arguments'] ?? [], fn ($value) => filled($value)),
                'result' => $this->tools->execute($tool['name'], array_filter($tool['arguments'] ?? [], fn ($value) => filled($value)), $user),
            ])
            ->all();
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
