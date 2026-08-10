<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminUserDirectActivationViewTest extends TestCase
{
    public function test_direct_activation_controls_are_restricted_to_primary_superadmin(): void
    {
        $view = file_get_contents(resource_path('views/crm/admin-users/create.blade.php'));

        $this->assertSame(3, substr_count($view, 'Auth::user()->isSuperadmin()'));
        $this->assertStringContainsString("old('provisioning_mode') === 'direct' && Auth::user()->isSuperadmin()", $view);
        $this->assertStringContainsString('@if(Auth::user()->isSuperadmin())', $view);
        $this->assertStringContainsString('Aktifkan Langsung', $view);
        $this->assertStringContainsString('Password Sementara', $view);
        $this->assertStringContainsString('name="submit_action" value="activate"', $view);
        $this->assertStringNotContainsString('send_immediately', $view);
    }

    public function test_invitation_mode_remains_default_without_direct_controls(): void
    {
        $view = file_get_contents(resource_path('views/crm/admin-users/create.blade.php'));

        $this->assertStringContainsString("? 'direct' : 'invitation'", $view);
        $this->assertStringContainsString('name="provisioning_mode" value="invitation"', $view);
        $this->assertStringContainsString('name="provisioning_mode" value="direct"', $view);
        $this->assertStringContainsString('name="temporary_password"', $view);
        $this->assertStringContainsString('name="temporary_password_confirmation"', $view);
        $this->assertStringContainsString('name="submit_action" value="draft"', $view);
        $this->assertStringContainsString('name="submit_action" value="send"', $view);
        $this->assertStringContainsString("x-show=\"provisioningMode === 'invitation'\"", $view);
        $this->assertStringContainsString("x-show=\"provisioningMode === 'direct'\"", $view);
        $this->assertStringNotContainsString('must_change_password', $view);
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
