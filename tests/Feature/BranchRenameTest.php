<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_superadmin_renames_active_branch_and_preserves_identity_fields(): void
    {
        $actor = $this->user('superadmin');
        $branch = Branch::create(['name' => 'Cabang Lama', 'code' => 'LMA', 'sheet_id' => 'sheet-123', 'is_active' => true]);

        $this->actingAs($actor)->put(route('branches.update', $branch), ['name' => "  Cabang\t Baru  "])
            ->assertRedirect(route('branches.index'));

        $branch->refresh();
        $this->assertSame('Cabang Baru', $branch->name);
        $this->assertSame('LMA', $branch->code);
        $this->assertSame('sheet-123', $branch->sheet_id);
        $this->assertTrue($branch->is_active);
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'name' => 'Cabang Baru']);
    }

    public function test_non_superadmin_and_supplemental_superadmin_cannot_rename_branch(): void
    {
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $manager = $this->user('manager');
        $supplemental = $this->user('manager');
        $supplemental->roles()->attach(Role::query()->where('slug', 'superadmin')->firstOrFail());

        $this->actingAs($manager)->put(route('branches.update', $branch), ['name' => 'Baru'])->assertForbidden();
        $this->actingAs($supplemental)->put(route('branches.update', $branch), ['name' => 'Baru'])->assertForbidden();
        $this->assertSame('Solo', $branch->fresh()->name);
    }

    public function test_duplicate_active_name_is_rejected_case_and_whitespace_insensitively(): void
    {
        $actor = $this->user('superadmin');
        Branch::create(['name' => 'Kantor Pusat', 'code' => 'PST', 'is_active' => true]);
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);

        $this->actingAs($actor)->from(route('branches.edit', $branch))
            ->put(route('branches.update', $branch), ['name' => ' kantor   PUSAT '])
            ->assertRedirect(route('branches.edit', $branch))
            ->assertSessionHasErrors('name');

        $this->assertSame('Solo', $branch->fresh()->name);
    }

    public function test_rename_records_branch_subject_and_actor_audit(): void
    {
        $actor = $this->user('superadmin');
        $branch = Branch::create(['name' => 'Lama', 'code' => 'LMA', 'is_active' => true]);

        $this->actingAs($actor)->put(route('branches.update', $branch), ['name' => 'Baru']);

        $log = ActivityLog::query()->where('event', 'branch_renamed')->sole();
        $this->assertTrue($log->subject->is($branch));
        $this->assertTrue($log->causer->is($actor));
        $this->assertSame([
            'branch_id' => $branch->id,
            'old_name' => 'Lama',
            'new_name' => 'Baru',
            'actor_id' => $actor->id,
        ], $log->properties);
    }

    public function test_index_shows_edit_action_for_active_branch(): void
    {
        $actor = $this->user('superadmin');
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);

        $this->actingAs($actor)->get(route('branches.index'))
            ->assertOk()
            ->assertSee(route('branches.edit', $branch), false)
            ->assertSee('Edit Nama');
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'password_changed_at' => now(),
        ]);
    }
}
