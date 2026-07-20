<?php

namespace Tests\Feature;

use App\Models\AiChatConversation;
use App\Models\Branch;
use App\Models\ContentItem;
use App\Models\DanaTalangan;
use App\Models\DatabaseSheetRecord;
use App\Models\DatabaseSheetSyncStatus;
use App\Models\KonsumenProgressSyncStatus;
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
        $this->pipelineCustomer($branch, 'A-01', 'Budi', 'akad');
        $this->pipelineCustomer($otherBranch, 'B-01', 'Sari', 'akad');

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
            ])
            ->push([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Ada 1 data akad untuk Jepara.'],
                ]],
            ]);

        $response = $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jumlah akad ada berapa?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('provider', 'local')
            ->assertSeeText('Ada 1 data Akad untuk Jepara.');

        $conversation = AiChatConversation::firstOrFail();
        $this->assertStringContainsString('Ada 1 data Akad untuk Jepara.', $conversation->messages[1]['content']);
    }

    public function test_bast_question_resolves_branch_name_without_hallucinating(): void
    {
        [, $user] = $this->superadminUser();
        $solo = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $malang = Branch::create(['name' => 'Malang', 'code' => 'MLG', 'is_active' => true]);
        $this->pipelineCustomer($solo, 'S-01', 'Budi', 'bast');
        $this->pipelineCustomer($malang, 'M-01', 'Sari', 'bast');

        $response = $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'berapa bast untuk cabang solo?',
        ]);

        $response
            ->assertOk()
            ->assertSeeText('Ada 1 data BAST untuk Solo.');
    }

    public function test_follow_up_pipeline_question_reuses_branch_and_stage_context(): void
    {
        [, $user] = $this->superadminUser();
        $solo = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $malang = Branch::create(['name' => 'Malang', 'code' => 'MLG', 'is_active' => true]);

        foreach (range(1, 7) as $number) {
            $this->pipelineCustomer($solo, 'S-'.$number, 'Solo '.$number, 'bast');
        }

        foreach (range(1, 3) as $number) {
            $this->pipelineCustomer($malang, 'M-'.$number, 'Malang '.$number, 'bast');
        }

        $first = $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'Jumlah bast untuk cabang solo ada berapa?',
        ]);

        $first
            ->assertOk()
            ->assertSeeText('Ada 7 data BAST untuk Solo.');

        $conversationId = $first->json('conversation_id');

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'conversation_id' => $conversationId,
            'message' => 'jumlah konsumennya ada berapa?',
        ])->assertOk()->assertSeeText('Ada 7 data BAST untuk Solo.');

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'conversation_id' => $conversationId,
            'message' => 'bukan semua cabang tapi untuk cabang solo saja',
        ])->assertOk()->assertSeeText('Ada 7 data BAST untuk Solo.');
    }

    public function test_superadmin_pipeline_follow_up_without_context_asks_for_branch(): void
    {
        [, $user] = $this->superadminUser();

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jumlah konsumennya ada berapa?',
        ])->assertOk()->assertSeeText('Untuk superadmin atau pusat, sebutkan cabang dulu');
    }

    public function test_local_parser_handles_known_pipeline_question_when_provider_is_unavailable(): void
    {
        [, $user] = $this->superadminUser();
        Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        config(['ai.enabled' => false]);
        Http::fake(fn () => Http::response(['should_not' => 'be used'], 500));

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'Jumlah bast untuk cabang solo ada berapa?',
        ])->assertOk()->assertSeeText('Ada 0 data BAST untuk Solo.');

        Http::assertNothingSent();
    }

    public function test_pipeline_question_includes_sync_action_when_cache_is_stale(): void
    {
        [$branch, $user] = $this->branchAndUser();
        config(['ai.sync_stale_minutes' => 5]);
        KonsumenProgressSyncStatus::create([
            'branch_id' => $branch->id,
            'status' => 'success',
            'finished_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jumlah akad ada berapa?',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message.actions.0.label', 'Sync Sekarang')
            ->assertJsonPath('message.actions.0.route', route('konsumen-progress.sync'))
            ->assertJsonPath('message.actions.0.payload.branch_id', $branch->id);
    }

    public function test_pipeline_question_does_not_include_sync_action_when_cache_is_fresh(): void
    {
        [$branch, $user] = $this->branchAndUser();
        config(['ai.sync_stale_minutes' => 5]);
        KonsumenProgressSyncStatus::create([
            'branch_id' => $branch->id,
            'status' => 'success',
            'finished_at' => now()->subMinute(),
        ]);

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jumlah akad ada berapa?',
        ])->assertOk()->assertJsonPath('message.actions', []);
    }

    public function test_search_customer_includes_database_sync_action_when_cache_is_stale(): void
    {
        [$branch, $user] = $this->branchAndUser();
        config(['ai.sync_stale_minutes' => 5]);
        DatabaseSheetSyncStatus::create([
            'branch_id' => $branch->id,
            'status' => 'success',
            'finished_at' => now()->subMinutes(20),
        ]);

        $response = $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'cari customer budi',
        ]);

        $response
            ->assertOk()
            ->assertJsonFragment(['key' => 'database'])
            ->assertJsonFragment(['route' => route('database.sync')]);
    }

    public function test_pipeline_count_uses_current_stage_projection_and_deduplicates_id_kavling(): void
    {
        [$branch, $user] = $this->branchAndUser();
        config(['ai.enabled' => false]);
        $this->pipelineCustomer($branch, 'A-01', 'Budi', 'akad');
        KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-'.$branch->id,
            'sheet_name' => 'bast',
            'row_hash' => 'bast-'.$branch->id.'-A-01',
            'row_data' => ['id_kavling' => 'A-01'],
        ]);
        KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-'.$branch->id,
            'sheet_name' => 'bast',
            'row_hash' => 'bast-duplicate-'.$branch->id.'-A-01',
            'row_data' => ['id_kavling' => 'A-01'],
        ]);

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jumlah akad ada berapa?',
        ])->assertOk()->assertSeeText('Ada 0 data Akad untuk Jepara.');

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jumlah bast ada berapa?',
        ])->assertOk()->assertSeeText('Ada 1 data BAST untuk Jepara.');
    }

    public function test_pipeline_date_filter_only_uses_explicit_stage_date_fields(): void
    {
        [$branch, $user] = $this->branchAndUser();
        config(['ai.enabled' => false]);
        KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-'.$branch->id,
            'sheet_name' => 'data_konsumen',
            'row_hash' => 'konsumen-date-test',
            'row_data' => ['id_kavling' => 'A-99', 'nama_konsumen' => 'Tanggal Palsu'],
        ]);
        KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-'.$branch->id,
            'sheet_name' => 'akad',
            'row_hash' => 'akad-date-test',
            'row_data' => ['id_kavling' => 'A-99', 'nomor_urut' => today()->toDateString(), 'progress' => '2026'],
        ]);

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'jumlah akad hari ini ada berapa?',
        ])->assertOk()->assertSeeText('Ada 0 data Akad untuk Jepara.');
    }

    public function test_customer_search_finds_database_record_after_300_and_does_not_store_sensitive_fields(): void
    {
        [$branch, $user] = $this->branchAndUser();
        config(['ai.enabled' => false]);
        foreach (range(1, 305) as $number) {
            DatabaseSheetRecord::create([
                'branch_id' => $branch->id,
                'sheet_id' => 'sheet-'.$branch->id,
                'sheet_name' => 'Leads',
                'row_number' => $number,
                'oasis_sync_id' => 'row-'.$number,
                'headers' => ['nama_konsumen'],
                'row_data' => ['nama_konsumen' => 'Customer '.$number],
                'sync_status' => 'synced',
                'last_synced_at' => now(),
            ]);
        }
        DatabaseSheetRecord::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-'.$branch->id,
            'sheet_name' => 'Leads',
            'row_number' => 306,
            'oasis_sync_id' => 'target-row',
            'headers' => ['nama_konsumen', 'NIK', 'alamat'],
            'row_data' => ['nama_konsumen' => 'Indra Maulana Adi Yudanto', 'NIK' => '3374123456789999', 'alamat' => 'Alamat Rahasia'],
            'sync_status' => 'synced',
            'last_synced_at' => now(),
        ]);

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'cari konsumen bernama Indra Maulana Adi Yudanto',
        ])->assertOk()->assertSeeText('Ditemukan 1 hasil');

        $conversationJson = json_encode(AiChatConversation::firstOrFail()->messages);
        $this->assertStringContainsString('Indra Maulana Adi Yudanto', $conversationJson);
        $this->assertStringNotContainsString('3374123456789999', $conversationJson);
        $this->assertStringNotContainsString('Alamat Rahasia', $conversationJson);
    }

    public function test_provider_tool_calls_are_allowlisted_limited_deduplicated_and_sanitized(): void
    {
        [$branch, $user] = $this->branchAndUser();
        config(['ai.routing_mode' => 'provider', 'ai.primary.api_key' => 'test-key', 'ai.max_tool_calls' => 3]);
        DatabaseSheetRecord::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-'.$branch->id,
            'sheet_name' => 'Leads',
            'row_number' => 1,
            'oasis_sync_id' => 'provider-target',
            'headers' => ['nama_konsumen', 'NPWP'],
            'row_data' => ['nama_konsumen' => 'Budi Provider', 'NPWP' => '99.999.999.9-999.999'],
            'sync_status' => 'synced',
            'last_synced_at' => now(),
        ]);
        Http::fakeSequence()
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [
                ['id' => '1', 'type' => 'function', 'function' => ['name' => 'dangerous_sql', 'arguments' => '{}']],
                ['id' => '2', 'type' => 'function', 'function' => ['name' => 'search_customer', 'arguments' => json_encode(['query' => 'Budi Provider', 'branch_id' => $branch->id])]],
                ['id' => '3', 'type' => 'function', 'function' => ['name' => 'search_customer', 'arguments' => json_encode(['query' => 'Budi Provider', 'branch_id' => $branch->id])]],
                ['id' => '4', 'type' => 'function', 'function' => ['name' => 'get_branch_info', 'arguments' => '{}']],
            ]]]]])
            ->push(['choices' => [['message' => ['role' => 'assistant', 'content' => 'Ditemukan Budi Provider.']]]]);

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'tolong bantu cari data budi provider',
        ])->assertOk()->assertSeeText('Ditemukan Budi Provider.');

        $messages = AiChatConversation::firstOrFail()->messages;
        $toolResults = $messages[1]['tool_results'];
        $this->assertCount(1, $toolResults);
        $this->assertSame('search_customer', $toolResults[0]['name']);
        $this->assertStringNotContainsString('99.999.999.9-999.999', json_encode($messages));
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

        config(['ai.enabled' => false]);
        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'di dashboard cabang mana yang perlu dicek?',
        ])->assertOk()->assertSeeText('Saya belum mengenali pertanyaan itu.');
    }

    public function test_unknown_question_uses_configured_provider(): void
    {
        [, $user] = $this->superadminUser();
        config([
            'ai.primary.provider' => 'openrouter',
            'ai.primary.api_key' => 'test-key',
            'ai.primary.model' => 'test-model',
            'ai.fallback.provider' => 'nvidia',
            'ai.fallback.api_key' => null,
        ]);
        Http::fake(fn () => Http::response([
            'choices' => [[
                'message' => ['role' => 'assistant', 'content' => 'Jawaban dari provider.'],
            ]],
        ]));

        $this->actingAs($user)->postJson(route('ai-chat.chat'), [
            'message' => 'tolong jelaskan cara membaca dashboard ini',
        ])->assertOk()
            ->assertJsonPath('provider', 'openrouter')
            ->assertJsonPath('model', 'test-model')
            ->assertJsonPath('message.content', 'Jawaban dari provider.');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/chat/completions'));
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

    private function pipelineCustomer(Branch $branch, string $idKavling, string $name, string $stage): void
    {
        KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-'.$branch->id,
            'sheet_name' => 'data_konsumen',
            'row_hash' => 'konsumen-'.$branch->id.'-'.$idKavling,
            'row_data' => ['id_kavling' => $idKavling, 'nama_konsumen' => $name, 'project_name' => 'Oasis '.$branch->name],
        ]);
        KonsumenProgressSheetRow::create([
            'branch_id' => $branch->id,
            'sheet_id' => 'sheet-'.$branch->id,
            'sheet_name' => $stage,
            'row_hash' => $stage.'-'.$branch->id.'-'.$idKavling,
            'row_data' => ['id_kavling' => $idKavling],
        ]);
    }
}
