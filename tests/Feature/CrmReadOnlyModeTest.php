<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmReadOnlyModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.read_only' => false]);
    }

    public function test_disabled_mode_allows_a_business_write_to_reach_validation(): void
    {
        $user = $this->user('superadmin');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('OASIS CRM TELAH DIBEKUKAN');
        $response = $this->actingAs($user)->postJson(route('sales-leads.store'), []);

        $response->assertUnprocessable();
    }

    public function test_read_only_mode_allows_login_viewing_and_reporting_pages(): void
    {
        $user = $this->user('superadmin');
        config(['app.read_only' => true]);

        $this->get(route('login'))->assertOk();
        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('OASIS CRM TELAH DIBEKUKAN');
        $this->actingAs($user)->get(route('notifications.index'))->assertOk();
    }

    public function test_read_only_mode_blocks_direct_lead_and_agenda_mutations(): void
    {
        $user = $this->user('superadmin');
        config(['app.read_only' => true]);

        $this->actingAs($user)->postJson(route('sales-leads.store'), [])
            ->assertStatus(423)
            ->assertJsonPath('read_only', true);
        $this->actingAs($user)->postJson(route('sales-agendas.store'), [])
            ->assertStatus(423)
            ->assertJsonPath('read_only', true);
    }

    public function test_read_only_mode_allows_notification_read_infrastructure(): void
    {
        $user = $this->user('superadmin');
        config(['app.read_only' => true]);

        $this->actingAs($user)->postJson(route('notifications.read-all'))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_browser_business_mutation_is_redirected_with_clear_message(): void
    {
        $user = $this->user('superadmin');
        config(['app.read_only' => true]);

        $this->actingAs($user)->from(route('dashboard'))
            ->post(route('sales-leads.store'), [])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('warning', 'OASIS CRM telah dibekukan. Data lama masih dapat dilihat, tetapi penambahan dan perubahan data sudah ditutup. Gunakan OASIS Next untuk aktivitas operasional baru.');
    }

    private function user(string $roleSlug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'password_changed_at' => now(),
            'account_status' => 'active',
        ]);
    }
}
