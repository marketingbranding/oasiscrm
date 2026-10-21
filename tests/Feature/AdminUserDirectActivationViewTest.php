<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminUserDirectActivationViewTest extends TestCase
{
    public function test_direct_activation_controls_are_restricted_to_primary_superadmin(): void
    {
        $view = file_get_contents(__DIR__.'/../../resources/views/crm/admin-users/create.blade.php');

        $this->assertGreaterThanOrEqual(2, substr_count($view, 'Auth::user()->isSuperadmin()'));
        $this->assertStringContainsString("provisioningMode: @js(old('provisioning_mode', 'invitation'))", $view);
        $this->assertStringContainsString('@if(Auth::user()->isSuperadmin())', $view);
        $this->assertStringContainsString('Identitas &amp; Peran', $view);
        $this->assertStringContainsString('Penempatan Organisasi', $view);
        $this->assertStringContainsString('Review &amp; Aktivasi', $view);
        $this->assertStringContainsString('Preview Struktur', $view);
        $this->assertStringContainsString("name: @js(old('name', ''))", $view);
        $this->assertStringContainsString("email: @js(old('email', ''))", $view);
        $this->assertStringContainsString("phone: @js(old('phone', ''))", $view);
        $this->assertStringContainsString('x-model="name"', $view);
        $this->assertStringContainsString('x-model="email"', $view);
        $this->assertStringContainsString('x-model="phone"', $view);
        $this->assertStringNotContainsString('$refs.name', $view);
        $this->assertStringNotContainsString('$refs.email', $view);
        $this->assertStringContainsString('x-text="name || \'—\'"', $view);
        $this->assertStringContainsString('x-text="email || \'—\'"', $view);
        $this->assertStringContainsString('Aktifkan Langsung', $view);
        $this->assertStringContainsString('Password Sementara', $view);
        $this->assertStringContainsString('name="submit_action" value="activate"', $view);
        $this->assertStringContainsString("x-show=\"step === 3 && provisioningMode === 'invitation'\"", $view);
        $this->assertStringContainsString("x-show=\"step === 3 && provisioningMode === 'direct'\"", $view);
        $this->assertStringNotContainsString('send_immediately', $view);
    }

    public function test_invitation_mode_remains_default_without_direct_controls(): void
    {
        $view = file_get_contents(__DIR__.'/../../resources/views/crm/admin-users/create.blade.php');

        $this->assertStringContainsString("old('provisioning_mode', 'invitation')", $view);
        $this->assertStringContainsString('name="provisioning_mode" value="invitation"', $view);
        $this->assertStringContainsString('name="provisioning_mode" value="direct"', $view);
        $this->assertStringContainsString('name="temporary_password"', $view);
        $this->assertStringContainsString('name="temporary_password_confirmation"', $view);
        $this->assertStringContainsString('name="submit_action" value="draft"', $view);
        $this->assertStringContainsString('name="submit_action" value="send"', $view);
        $this->assertStringContainsString("provisioningMode === 'invitation'", $view);
        $this->assertStringContainsString("provisioningMode === 'direct'", $view);
        $this->assertStringContainsString('name="coordinator_user_id"', $view);
        $this->assertStringContainsString('sales_coordinator_sales', $view);
        $this->assertStringNotContainsString('must_change_password', $view);
    }

    public function test_identity_review_uses_reactive_wizard_state_and_step_one_validates_identity(): void
    {
        $script = file_get_contents(__DIR__.'/../../resources/js/admin-user-creation.js');

        $this->assertStringContainsString("name: config.name ?? ''", $script);
        $this->assertStringContainsString("email: config.email ?? ''", $script);
        $this->assertStringContainsString("phone: config.phone ?? ''", $script);
        $this->assertStringContainsString("this.errorSummary = 'Nama belum diisi.'", $script);
        $this->assertStringContainsString("this.errorSummary = 'Email belum diisi.'", $script);
        $this->assertStringContainsString('this.name.trim()', $script);
        $this->assertStringContainsString('this.email.trim()', $script);
    }

    public function test_active_direct_user_status_is_shown_without_active_invitation_actions(): void
    {
        $view = file_get_contents(resource_path('views/crm/admin-users/show.blade.php'));

        $this->assertStringContainsString("\\App\\Enums\\AccountStatus::Active => 'Aktif'", $view);
        $this->assertStringContainsString('$user->account_status === \\App\\Enums\\AccountStatus::Active && $user->must_change_password', $view);
        $this->assertStringContainsString('Menunggu ganti password pertama', $view);
        $this->assertStringContainsString('in_array($user->account_status,[\\App\\Enums\\AccountStatus::PendingInvitation,\\App\\Enums\\AccountStatus::Invited])', $view);
        $this->assertStringNotContainsString('{{ $user->password }}', $view);
    }
}
