<?php

namespace Tests\Feature;

use App\Models\AiChatConversation;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\KonsumenProgressSheetRow;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_user_gets_read_only_local_answer_when_provider_is_unavailable(): void
    {
        [$branch, $user] = $this->branchAndUser();
        ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'content',
            'visibility' => 'team',
            'title' => 'Reels Akad Jepara',
            'scheduled_date' => today(),
            'deadline_date' => today(),
            'status' => 'idea',
            'created_by' => $user->id,
        ]);

        Http::fake(fn () => Http::response(['error' => 'down'], 500));

        $response = $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jadwal konten hari ini apa saja?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('provider', 'local')
            ->assertJsonFragment(['content' => 'Ada 1 jadwal content pada periode '.today()->toDateString().' sampai '.today()->toDateString().' untuk Jepara. '.today()->toDateString().': Reels Akad Jepara.']);

        $this->assertDatabaseHas('ai_chat_conversations', [
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'provider' => 'local',
        ]);
    }

    public function test_missing_primary_api_key_does_not_trigger_http_call_or_500(): void
    {
        [$branch, $user] = $this->branchAndUser();
        config([
            'ai.primary.provider' => 'openrouter',
            'ai.primary.api_key' => null,
            'ai.fallback.provider' => 'nvidia',
            'ai.fallback.api_key' => null,
        ]);
        ContentItem::create([
            'branch_id' => $branch->id,
            'item_type' => 'content',
            'visibility' => 'team',
            'title' => 'Konten Tanpa Provider',
            'scheduled_date' => today(),
            'deadline_date' => today(),
            'status' => 'idea',
            'created_by' => $user->id,
        ]);

        Http::fake(fn () => Http::response(['should_not' => 'be called'], 500));

        $response = $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jadwal konten hari ini apa saja?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('provider', 'local')
            ->assertJsonFragment(['role' => 'assistant']);

        Http::assertNothingSent();
    }

    public function test_ai_tool_call_is_executed_with_branch_scope(): void
    {
        [$branch, $user] = $this->branchAndUser();
        config(['ai.primary.api_key' => 'test-key']);
        $otherBranch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-a',
            'sheet_name' => 'Akad',
            'row_hash' => 'a',
            'row_data' => ['nama' => 'Budi'],
        ]);
        KonsumenProgressSheetRow::create([
            'branch_id' => $otherBranch->id,
            'sheet_id' => 'sheet-b',
            'sheet_name' => 'Akad',
            'row_hash' => 'b',
            'row_data' => ['nama' => 'Sari'],
        ]);

        Http::fakeSequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'count_by_stage', 'arguments' => json_encode(['stage' => 'akad'])],
                        ]],
                    ],
                ]],
            ]);

        $response = $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jumlah akad ada berapa?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message.content', 'Ada 1 data akad untuk Jepara.');

        $conversation = AiChatConversation::firstOrFail();
        $this->assertSame('Ada 1 data akad untuk Jepara.', $conversation->messages[1]['content']);
    }

    public function test_bast_question_resolves_branch_name_without_hallucinating(): void
    {
        [, $user] = $this->superadminUser();
        $solo = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $malang = Branch::create(['name' => 'Malang', 'code' => 'MLG', 'is_active' => true]);
        KonsumenProgressSheetRow::create([
            'branch_id' => $solo->id,
            'sheet_id' => 'sheet-solo',
            'sheet_name' => 'BAST',
            'row_hash' => 'solo-bast',
            'row_data' => ['nama' => 'Budi'],
        ]);
        KonsumenProgressSheetRow::create([
            'branch_id' => $malang->id,
            'sheet_id' => 'sheet-malang',
            'sheet_name' => 'BAST',
            'row_hash' => 'malang-bast',
            'row_data' => ['nama' => 'Sari'],
        ]);

        $response = $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'berapa bast untuk cabang solo?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message.content', 'Ada 1 data bast untuk Solo.');
    }

    public function test_follow_up_pipeline_question_reuses_branch_and_stage_context(): void
    {
        [, $user] = $this->superadminUser();
        $solo = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $malang = Branch::create(['name' => 'Malang', 'code' => 'MLG', 'is_active' => true]);

        foreach (range(1, 7) as $number) {
            KonsumenProgressSheetRow::create([
                'branch_id' => $solo->id,
                'sheet_id' => 'solo-sheet',
                'sheet_name' => 'BAST',
                'row_hash' => 'solo-bast-'.$number,
                'row_data' => ['nama' => 'Solo '.$number],
            ]);
        }

        foreach (range(1, 3) as $number) {
            KonsumenProgressSheetRow::create([
                'branch_id' => $malang->id,
                'sheet_id' => 'malang-sheet',
                'sheet_name' => 'BAST',
                'row_hash' => 'malang-bast-'.$number,
                'row_data' => ['nama' => 'Malang '.$number],
            ]);
        }

        $first = $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'Jumlah bast untuk cabang solo ada berapa?',
        ]);

        $first
            ->assertOk()
            ->assertJsonPath('message.content', 'Ada 7 data bast untuk Solo.');

        $conversationId = $first->json('conversation_id');

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'conversation_id' => $conversationId,
            'message' => 'jumlah konsumennya ada berapa?',
        ])->assertOk()->assertJsonPath('message.content', 'Ada 7 data bast untuk Solo.');

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'conversation_id' => $conversationId,
            'message' => 'bukan semua cabang tapi untuk cabang solo saja',
        ])->assertOk()->assertJsonPath('message.content', 'Ada 7 data bast untuk Solo.');
    }

    public function test_superadmin_pipeline_follow_up_without_context_asks_for_branch(): void
    {
        [, $user] = $this->superadminUser();

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jumlah konsumennya ada berapa?',
        ])->assertOk()->assertSeeText('Untuk superadmin atau pusat, sebutkan cabang dulu');
    }

    public function test_local_parser_ignores_provider_tool_choice_for_known_pipeline_question(): void
    {
        [, $user] = $this->superadminUser();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        config(['ai.primary.api_key' => 'test-key']);
        Http::fake(fn () => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'tool_calls' => [[
                        'id' => 'wrong_tool',
                        'type' => 'function',
                        'function' => ['name' => 'search_customer', 'arguments' => json_encode(['query' => 'bast'])],
                    ]],
                ],
            ]],
        ]));

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'Jumlah bast untuk cabang solo ada berapa?',
        ])->assertOk()->assertJsonPath('message.content', 'Ada 0 data bast untuk Solo.');

        Http::assertNothingSent();
    }

    public function test_dana_talangan_answer_includes_grounded_record_detail(): void
    {
        [, $user] = $this->superadminUser();
        $malang = Branch::create(['name' => 'Malang', 'code' => 'MLG', 'is_active' => true]);
        DanaTalangan::create([
            'branch_id' => $malang->id,
            'created_by' => $user->id,
            'tanggal' => today(),
            'nama_konsumen' => 'Andre Sutikna',
            'kav' => 'A-12',
            'project_name' => 'Oasis Malang',
            'status' => 'lunas',
            'konfirmasi_keuangan' => true,
        ]);

        $response = $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'berapa jumlah dana talangan malang?',
        ]);

        $response
            ->assertOk()
            ->assertSeeText('Ada 1 data Dana Talangan untuk Malang.')
            ->assertSeeText('Andre Sutikna - status lunas - kav A-12 - Oasis Malang');
    }

    public function test_capabilities_and_unknown_questions_are_safe(): void
    {
        [, $user] = $this->superadminUser();

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'data apa yang bisa kamu cari?',
        ])->assertOk()->assertSeeText('Saya bisa membaca data berikut:')->assertSeeText('Dana Talangan');

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'di dashboard cabang mana yang perlu dicek?',
        ])->assertOk()->assertSeeText('Saya belum mengenali pertanyaan itu.');
    }

    public function test_user_cannot_open_other_users_conversation(): void
    {
        [$branch, $owner] = $this->branchAndUser();
        [, $otherUser] = $this->branchAndUser('Pati', 'PTI');
        $conversation = AiChatConversation::create([
            'user_id' => $owner->id,
            'branch_id' => $branch->id,
            'title' => 'Rahasia',
            'messages' => [],
        ]);

        $this->actingAs($otherUser)->getJson(route('ai-chat.show', $conversation))->assertNotFound();
        $this->actingAs($otherUser)->deleteJson(route('ai-chat.destroy', $conversation))->assertNotFound();
    }

    private function branchAndUser(string $branchName = 'Jepara', string $branchCode = 'JPR'): array
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $branch = Branch::create(['name' => $branchName, 'code' => $branchCode, 'is_active' => true]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'password_changed_at' => now(),
        ]);

        return [$branch, $user];
    }

    private function superadminUser(): array
    {
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Superadmin', 'is_superadmin' => true]);
        $user = User::factory()->create([
            'role_id' => $role->id,
            'password_changed_at' => now(),
        ]);

        return [$role, $user];
    }
}
