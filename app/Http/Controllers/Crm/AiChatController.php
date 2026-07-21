<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\AiChatConversation;
use App\Services\AiAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AiChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('ai_chat_conversations')) {
            return response()->json([
                'conversations' => [],
                'context' => $this->context($request),
                'error' => 'Tabel AI chat belum tersedia. Jalankan php artisan migrate.',
            ], 503);
        }

        $conversations = AiChatConversation::query()
            ->where('user_id', $request->user()->id)
            ->latest('updated_at')
            ->limit(20)
            ->get(['id', 'title', 'messages', 'provider', 'model', 'updated_at'])
            ->map(fn (AiChatConversation $conversation) => [
                'id' => $conversation->id,
                'title' => $conversation->title ?: 'Percakapan AI',
                'provider' => $conversation->provider,
                'model' => $conversation->model,
                'updated_at' => $conversation->updated_at?->diffForHumans(),
                'last_message' => Str::limit(collect($conversation->messages)->last()['content'] ?? '', 80),
            ]);

        return response()->json([
            'conversations' => $conversations,
            'context' => $this->context($request),
        ]);
    }

    public function show(Request $request, AiChatConversation $conversation): JsonResponse
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'messages' => $conversation->messages,
                'provider' => $conversation->provider,
                'model' => $conversation->model,
            ],
        ]);
    }

    public function chat(Request $request, AiAssistantService $assistant): JsonResponse
    {
        if (! Schema::hasTable('ai_chat_conversations')) {
            return response()->json([
                'message' => 'Tabel AI chat belum tersedia. Jalankan php artisan migrate.',
            ], 503);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:'.config('ai.max_input_length', 1000)],
            'conversation_id' => [
                'nullable',
                'integer',
                Rule::exists('ai_chat_conversations', 'id')->where('user_id', $request->user()->id),
            ],
        ]);

        $conversation = null;
        if (! empty($data['conversation_id'])) {
            $conversation = AiChatConversation::where('user_id', $request->user()->id)->findOrFail($data['conversation_id']);
        }

        $reply = $assistant->reply($request->user(), $data['message'], $conversation);
        $messages = $conversation?->messages ?? [];
        $userMessageAt = now()->toIso8601String();
        $assistantMessageAt = now()->toIso8601String();
        $messages[] = ['role' => 'user', 'content' => $data['message'], 'at' => $userMessageAt];
        $messages[] = [
            'role' => 'assistant',
            'content' => $reply['content'],
            'actions' => $reply['actions'] ?? [],
            'tool_results' => $this->sanitizeToolResults($reply['tool_results'] ?? []),
            'at' => $assistantMessageAt,
        ];
        $messages = array_slice($messages, -1 * (int) config('ai.max_stored_messages', 50));

        $payload = [
            'user_id' => $request->user()->id,
            'branch_id' => $request->user()->branch_id,
            'title' => $conversation?->title ?: Str::limit($data['message'], 60, ''),
            'messages' => $messages,
            'provider' => $reply['provider'],
            'model' => $reply['model'],
        ];

        if ($conversation) {
            $conversation->update($payload);
        } else {
            $conversation = AiChatConversation::create($payload);
        }

        return response()->json([
            'conversation_id' => $conversation->id,
            'message' => [
                'role' => 'assistant',
                'content' => $reply['content'],
                'actions' => $reply['actions'] ?? [],
                'at' => $assistantMessageAt,
            ],
            'provider' => $reply['provider'],
            'model' => $reply['model'],
        ]);
    }

    public function destroy(Request $request, AiChatConversation $conversation): JsonResponse
    {
        abort_unless($conversation->user_id === $request->user()->id, 404);
        $conversation->delete();

        return response()->json(['ok' => true]);
    }

    private function context(Request $request): array
    {
        return [
            'branch' => $request->user()->branch?->name,
            'role' => $request->user()->role?->name,
            'can_view_all_branches' => $request->user()->canViewAllBranches(),
        ];
    }

    private function sanitizeToolResults(array $toolResults): array
    {
        return collect($toolResults)->map(fn (array $toolResult) => [
            'name' => $toolResult['name'] ?? null,
            'arguments' => $toolResult['arguments'] ?? [],
            'result' => $toolResult['result'] ?? [],
        ])->values()->all();
    }
}
