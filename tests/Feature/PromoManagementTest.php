<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Promo;
use App\Models\Role;
use App\Models\User;
use App\Services\PromoOptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_sees_and_manages_every_branch_and_legacy_rows(): void
    {
        [$first, $second] = $this->branches();
        $user = $this->user('superadmin', $first);
        $legacy = Promo::create(['code' => 'legacy', 'name' => 'Legacy', 'is_active' => true]);
        $foreign = $this->promo($second, 'foreign', 'Foreign');

        $this->actingAs($user)->get(route('promos.index'))->assertOk()->assertSee('Legacy')->assertSee('Foreign');
        $this->assertNull($legacy->fresh()->branch_id);
        $this->actingAs($user)->patch(route('promos.toggle', $foreign))->assertRedirect();
        $this->assertFalse($foreign->fresh()->is_active);
    }

    public function test_admin_can_manage_only_own_branch_and_cannot_forge_branch(): void
    {
        [$own, $foreign] = $this->branches();
        $admin = $this->user('admin', $own, true);
        $ownPromo = $this->promo($own, 'own', 'Own');
        $foreignPromo = $this->promo($foreign, 'foreign', 'Foreign');

        $this->actingAs($admin)->get(route('promos.index'))->assertOk()->assertSee('Own')->assertDontSee('Foreign');
        $this->actingAs($admin)->post(route('promos.store'), $this->data($own, 'new', 'New'))->assertRedirect();
        $this->actingAs($admin)->post(route('promos.store'), $this->data($foreign, 'forged', 'Forged'))->assertForbidden();
        $this->actingAs($admin)->put(route('promos.update', $foreignPromo), $this->data($own, 'stolen', 'Stolen'))->assertForbidden();
        $this->actingAs($admin)->patch(route('promos.toggle', $foreignPromo))->assertForbidden();
        $this->assertTrue($ownPromo->fresh()->is_active);
        $this->assertDatabaseMissing('promos', ['code' => 'forged']);
    }

    public function test_manual_create_edit_toggle_normalizes_values_locks_branch_and_audits(): void
    {
        [$branch] = $this->branches();
        $admin = $this->user('admin', $branch, true);

        $this->actingAs($admin)->post(route('promos.store'), $this->data($branch, '  Promo Baru  ', '  Promo   Baru  ', ['description' => ' catatan ']))->assertRedirect()->assertSessionHasNoErrors();
        $promo = Promo::where('code', 'PROMO-BARU')->sole();
        $this->assertSame('Promo Baru', $promo->name);
        $this->assertSame('catatan', $promo->description);

        $this->actingAs($admin)->put(route('promos.update', $promo), $this->data($branch, 'promo_baru', 'Nama Ubah'))->assertRedirect();
        $this->actingAs($admin)->patch(route('promos.toggle', $promo))->assertRedirect();
        $this->assertSame(['promo_created', 'promo_updated', 'promo_status_changed'], ActivityLog::where('subject_type', Promo::class)->orderBy('id')->pluck('event')->all());
        $this->assertFalse($promo->fresh()->is_active);
    }

    public function test_supplemental_privileged_role_does_not_grant_promo_access(): void
    {
        [$branch] = $this->branches();
        $user = $this->user('staff', $branch, true);
        $user->roles()->attach(Role::where('slug', 'superadmin')->firstOrFail());

        $this->actingAs($user)->get(route('promos.index'))->assertForbidden();
        $this->actingAs($user)->post(route('promos.store'), $this->data($branch, 'denied', 'Denied'))->assertForbidden();
    }

    public function test_promo_options_cover_open_periods_boundaries_visibility_and_historical_value(): void
    {
        [$branch, $other] = $this->branches();
        $this->promo($branch, 'open', 'Open', null, null);
        $this->promo($branch, 'start', 'Start', '2026-08-14', null);
        $this->promo($branch, 'end', 'End', null, '2026-08-14');
        $this->promo($branch, 'expired', 'Expired', null, '2026-08-13');
        $this->promo($branch, 'future', 'Future', '2026-08-15', null);
        $this->promo($branch, 'inactive', 'Inactive', null, null, false);
        $this->promo($other, 'other', 'Other', null, null);
        $this->promo($branch, 'duplicate', 'Open', null, null);

        $options = app(PromoOptionService::class)->availableForBranchAndDate($branch->id, '2026-08-14', 'Historical');
        $this->assertSame(['No Promo', 'End', 'Open', 'Start', 'Historical'], $options->all());
        $this->assertTrue(app(PromoOptionService::class)->accepts($branch->id, '2026-08-14', 'Historical', 'Historical'));
        $this->assertFalse($options->contains('Expired'));
        $this->assertFalse($options->contains('Future'));
        $this->assertFalse($options->contains('Inactive'));
        $this->assertFalse($options->contains('Other'));
    }

    private function branches(): array
    {
        return [Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]), Branch::create(['name' => 'Jogja', 'code' => 'JOG', 'is_active' => true])];
    }

    private function user(string $role, Branch $branch, bool $editable = false): User
    {
        $user = User::factory()->create(['role_id' => Role::where('slug', $role)->value('id'), 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $user->branches()->syncWithoutDetaching([$branch->id => ['membership_role' => 'primary', 'can_view' => true, 'can_edit' => $editable]]);

        return $user;
    }

    private function promo(Branch $branch, string $code, string $name, ?string $start = '2026-01-01', ?string $end = '2026-12-31', bool $active = true): Promo
    {
        return Promo::create(['branch_id' => $branch->id, 'code' => $code, 'name' => $name, 'start_date' => $start, 'end_date' => $end, 'is_active' => $active]);
    }

    private function data(Branch $branch, string $code, string $name, array $extra = []): array
    {
        return $extra + ['branch_id' => $branch->id, 'code' => $code, 'name' => $name, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_active' => true];
    }
}
