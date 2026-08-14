<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Promo;
use App\Models\PromoImportBatch;
use App\Models\Role;
use App\Models\User;
use App\Services\PromoTsvParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PromoImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_supports_header_no_header_tabs_whitespace_dates_order_and_duplicates(): void
    {
        $parser = app(PromoTsvParser::class);
        $rows = $parser->parse(" id_promo \t nama_promo \t tanggal_mulai \t tanggal_selesai \t keterangan \n P1 \t Promo 1 \t 8/1/2026 \t 08/01/2026 \t Catatan ");
        $this->assertSame('2026-08-01', $rows[0]['normalized_data']['start_date']);
        $this->assertSame('2026-08-01', $rows[0]['normalized_data']['end_date']);
        $this->assertSame([], $rows[0]['errors']);

        $rows = $parser->parse("P2\tPromo 2\t01/08/2026\t2026-08-02\t\nP2\tPromo Duplikat\t2026-08-03\t2026-08-02\t");
        $this->assertSame([1, 2], array_column($rows, 'line_number'));
        $this->assertSame(['Duplikat Input', 'Duplikat Input'], array_column($rows, 'status'));
        $this->assertContains('Tanggal selesai harus sama atau setelah tanggal mulai.', $rows[1]['errors']);
    }

    public function test_parser_rejects_partial_or_reordered_header(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(PromoTsvParser::class)->parse("nama_promo\tid_promo\ttanggal_mulai\ttanggal_selesai\tketerangan\nPromo\tP1\t\t\t");
    }

    public function test_preview_stages_rows_without_mutating_promos(): void
    {
        [$branch, $admin] = $this->context();
        $before = Promo::count();

        $this->actingAs($admin)->post(route('promos.import.preview'), ['branch_id' => $branch->id, 'tsv' => $this->tsv()])->assertRedirect();

        $this->assertSame($before, Promo::count());
        $batch = PromoImportBatch::sole();
        $this->assertSame('preview_ready', $batch->status);
        $this->assertSame(2, $batch->rows()->count());
    }

    public function test_confirm_creates_updates_preserves_inactive_and_skips_invalid_with_one_audit(): void
    {
        [$branch, $admin] = $this->context();
        $existing = Promo::create(['branch_id' => $branch->id, 'code' => 'existing', 'name' => 'Old', 'is_active' => false]);
        $tsv = "existing\tUpdated\t2026-01-01\t2026-12-31\tChanged\nnew\tNew\t\t\t\nbad\tBad\t31/2/2026\t\t";
        $this->actingAs($admin)->post(route('promos.import.preview'), ['branch_id' => $branch->id, 'tsv' => $tsv]);
        $batch = PromoImportBatch::sole();

        $this->actingAs($admin)->post(route('promos.import.confirm', $batch), ['expected_updated_at' => $batch->updated_at->toISOString()])->assertRedirect();

        $this->assertSame('Updated', $existing->fresh()->name);
        $this->assertFalse($existing->fresh()->is_active);
        $this->assertDatabaseHas('promos', ['branch_id' => $branch->id, 'code' => 'NEW', 'is_active' => true]);
        $this->assertDatabaseMissing('promos', ['code' => 'bad']);
        $batch->refresh();
        $this->assertSame([1, 1, 1], [$batch->created_rows, $batch->updated_rows, $batch->skipped_rows]);
        $audit = ActivityLog::where('event', 'promo_imported')->sole();
        $this->assertSame(['branch_id', 'total_rows', 'created_count', 'updated_count', 'invalid_count', 'actor_id'], array_keys($audit->properties));
    }

    public function test_same_code_is_independent_per_branch(): void
    {
        [$branch, $admin] = $this->context();
        $other = Branch::create(['name' => 'Other', 'code' => 'OTH', 'is_active' => true]);
        Promo::create(['branch_id' => $other->id, 'code' => 'same', 'name' => 'Other Name', 'is_active' => true]);

        $this->actingAs($admin)->post(route('promos.import.preview'), ['branch_id' => $branch->id, 'tsv' => "same\tOwn Name\t\t\t"]);
        $batch = PromoImportBatch::sole();
        $this->actingAs($admin)->post(route('promos.import.confirm', $batch), ['expected_updated_at' => $batch->updated_at->toISOString()])->assertRedirect();

        $this->assertDatabaseHas('promos', ['branch_id' => $branch->id, 'code' => 'SAME', 'name' => 'Own Name']);
        $this->assertDatabaseHas('promos', ['branch_id' => $other->id, 'code' => 'same', 'name' => 'Other Name']);
    }

    public function test_confirm_rechecks_actor_branch_authorization_and_stale_token(): void
    {
        [$branch, $admin] = $this->context();
        $this->actingAs($admin)->post(route('promos.import.preview'), ['branch_id' => $branch->id, 'tsv' => $this->tsv()]);
        $batch = PromoImportBatch::sole();
        $outsider = $this->user('admin', Branch::create(['name' => 'Foreign', 'code' => 'FOR', 'is_active' => true]), true);

        $this->actingAs($outsider)->post(route('promos.import.confirm', $batch), ['expected_updated_at' => $batch->updated_at->toISOString()])->assertForbidden();
        $this->actingAs($admin)->post(route('promos.import.confirm', $batch), ['expected_updated_at' => now()->subDay()->toISOString()])->assertConflict();
        $this->assertDatabaseMissing('promos', ['branch_id' => $branch->id, 'code' => 'first']);
        $this->assertDatabaseMissing('promos', ['branch_id' => $branch->id, 'code' => 'second']);
    }

    public function test_preview_and_confirm_reject_impersonation_session(): void
    {
        [$branch, $admin] = $this->context();
        $session = ['impersonation.original_user_id' => 999, 'impersonation.target_user_id' => $admin->id];
        $this->actingAs($admin)->withSession($session)->post(route('promos.import.preview'), ['branch_id' => $branch->id, 'tsv' => $this->tsv()])->assertForbidden();
    }

    private function context(): array
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);

        return [$branch, $this->user('admin', $branch, true)];
    }

    private function user(string $role, Branch $branch, bool $editable): User
    {
        $user = User::factory()->create(['role_id' => Role::where('slug', $role)->value('id'), 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['can_view' => true, 'can_edit' => $editable]]);

        return $user;
    }

    private function tsv(): string
    {
        return "id_promo\tnama_promo\ttanggal_mulai\ttanggal_selesai\tketerangan\nfirst\tFirst\t2026-01-01\t2026-12-31\t\nsecond\tSecond\t\t\t";
    }
}
