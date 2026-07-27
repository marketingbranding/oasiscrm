<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollaborationUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_conflict_dialog_and_notification_center_are_rendered(): void
    {
        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $branch = Branch::create(['name' => 'Solo', 'code' => 'SLO', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);

        $this->actingAs($user)->withSession(['conflict_data' => [
            'code' => 'record_modified', 'message' => 'Data berubah', 'reload_url' => route('dashboard'),
        ]])->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Muat Ulang Data')
            ->assertSee('Salin Perubahan Saya')
            ->assertSee('Muat ulang data akan mengganti nilai pada form saat ini')
            ->assertSee('crmNotifications', false)
            ->assertSee('Tandai semua dibaca');
    }

    public function test_ajax_edit_forms_use_shared_conflict_handler_and_status_conflict_is_not_generic(): void
    {
        $database = file_get_contents(resource_path('views/crm/database/index.blade.php'));
        $dana = file_get_contents(resource_path('views/crm/dana-talangan/index.blade.php'));
        $planner = file_get_contents(resource_path('views/crm/content-calendar/index.blade.php'));
        $sales = file_get_contents(resource_path('views/crm/sales-pocketbook/index.blade.php'));
        $conflict = file_get_contents(resource_path('js/conflict.js'));

        $this->assertStringContainsString('oasis-submit-conflict', $database);
        $this->assertStringContainsString('oasis-submit-conflict', $dana);
        $this->assertStringContainsString('response.status === 409', $planner);
        $this->assertStringContainsString("new CustomEvent('oasis-conflict'", $planner);
        $this->assertStringContainsString('response.status === 409', $sales);
        $this->assertStringContainsString("new CustomEvent('oasis-conflict'", $sales);
        $this->assertStringContainsString('window.oasisToast', $sales);
        $this->assertStringContainsString('preserveUnsavedValues', $conflict);
        $this->assertStringContainsString('copyUnsavedValues', $conflict);
        $this->assertStringContainsString('focusDialog', $conflict);
        $this->assertStringContainsString('trapFocus', $conflict);
        $this->assertStringContainsString('oasisConflictOpen', $conflict);
        $this->assertStringContainsString('Browser tidak mengizinkan penyalinan otomatis', $conflict);
        $this->assertStringNotContainsString("alert('Status gagal diperbarui", $planner);
    }

    public function test_global_toast_supports_flash_types_and_runtime_events(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/crm.blade.php'));
        $toast = file_get_contents(resource_path('js/toast.js'));
        $app = file_get_contents(resource_path('js/app.js'));

        foreach (['success', 'error', 'warning'] as $type) {
            $this->assertStringContainsString("session('{$type}')", $layout);
            $this->assertStringContainsString("'{$type}'", $toast);
        }
        $this->assertStringContainsString('@oasis:toast.window', $layout);
        $this->assertStringContainsString("new CustomEvent('oasis:toast'", $toast);
        $this->assertStringContainsString('window.oasisToast', $toast);
        $this->assertStringContainsString('this.toasts.some', $toast);
        $this->assertStringContainsString('registerToasts(Alpine)', $app);

        $role = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin', 'is_superadmin' => false]);
        $branch = Branch::create(['name' => 'Pati', 'code' => 'PTI', 'is_active' => true]);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $branch->id, 'password_changed_at' => now()]);
        $this->actingAs($user)->withSession([
            'success' => 'Operasi berhasil.',
            'error' => 'Operasi gagal.',
            'warning' => 'Operasi perlu diperiksa.',
        ])->get(route('dashboard'))->assertOk()
            ->assertSee('Operasi berhasil.')
            ->assertSee('Operasi gagal.')
            ->assertSee('Operasi perlu diperiksa.');
    }
}
