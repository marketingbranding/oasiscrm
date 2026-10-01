<?php

namespace Tests\Feature;

use App\Models\ConsumerApplication;
use App\Models\Kavling;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsumerKavlingLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConsumerLifecycleHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_mundur_endpoint_releases_the_current_kavling_assignment(): void
    {
        $application = ConsumerApplication::factory()->create();
        $kavling = Kavling::create([
            'project_id' => $application->project_id,
            'kavling_code' => 'A-11',
            'name' => 'A-11',
        ]);
        app(ConsumerKavlingLifecycleService::class)->assign($application, $kavling);
        $actor = $this->superadmin($application);

        $response = $this->actingAs($actor)->postJson(route('consumer-applications.mundur', $application));

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('consumer_applications', [
            'id' => $application->id,
            'consumer_status' => 'Mundur',
            'kavling_id' => null,
        ]);
        $this->assertDatabaseHas('consumer_kavling_assignments', [
            'consumer_application_id' => $application->id,
            'assignment_status' => 'released',
            'release_reason' => 'mundur',
        ]);
    }

    public function test_pindah_kavling_endpoint_validates_and_applies_the_target(): void
    {
        $application = ConsumerApplication::factory()->create();
        $target = Kavling::create([
            'project_id' => $application->project_id,
            'kavling_code' => 'A-12',
            'name' => 'A-12',
        ]);
        $actor = $this->superadmin($application);

        $response = $this->actingAs($actor)->postJson(route('consumer-applications.pindah-kavling', $application), [
            'target_kavling_id' => $target->id,
        ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('consumer_applications', [
            'id' => $application->id,
            'consumer_status' => 'Pindah Kavling',
            'kavling_id' => $target->id,
        ]);
    }

    public function test_lifecycle_endpoint_rejects_an_actor_without_the_application_scope(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'staff')->value('id'),
            'branch_id' => $application->branch_id,
            'password_changed_at' => now(),
        ]);

        $this->actingAs($actor)
            ->postJson(route('consumer-applications.mundur', $application))
            ->assertForbidden();
    }

    public function test_ganti_bank_endpoint_validates_the_bank_identity(): void
    {
        $application = ConsumerApplication::factory()->create();
        $actor = $this->superadmin($application);

        $this->actingAs($actor)
            ->postJson(route('consumer-applications.ganti-bank', $application), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['bank_name']);
    }

    public function test_lifecycle_endpoint_requires_authentication(): void
    {
        $application = ConsumerApplication::factory()->create();

        $this->postJson(route('consumer-applications.mundur', $application))
            ->assertUnauthorized();
    }

    public function test_phase_2_5_changelogs_are_idempotent_and_visible(): void
    {
        $actor = $this->superadmin(ConsumerApplication::factory()->create());

        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', 'Perlindungan Penulisan Eksternal Google')->count());
        $this->assertSame(1, DB::table('changelogs')->whereNull('version')->where('title', 'Kontrak HTTP Lifecycle Konsumen')->count());

        $this->actingAs($actor)
            ->get(route('changelogs.index'))
            ->assertOk()
            ->assertSeeText('Perlindungan Penulisan Eksternal Google')
            ->assertSeeText('Kontrak HTTP Lifecycle Konsumen');
    }

    private function superadmin(ConsumerApplication $application): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'superadmin')->value('id'),
            'branch_id' => $application->branch_id,
            'password_changed_at' => now(),
        ]);
    }
}
