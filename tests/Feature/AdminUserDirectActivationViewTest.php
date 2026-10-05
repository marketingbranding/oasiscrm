<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminUserDirectActivationViewTest extends TestCase
{
    public function test_create_view_requires_initial_password_without_activation_choices(): void
    {
        $view = file_get_contents(resource_path('views/crm/admin-users/create.blade.php'));

        $this->assertStringContainsString('Password Awal', $view);
        $this->assertStringContainsString('name="temporary_password"', $view);
        $this->assertStringContainsString('name="temporary_password_confirmation"', $view);
        $this->assertStringContainsString('SIMPAN &amp; AKTIFKAN', $view);
        $this->assertStringNotContainsString('Aktivasi Akun', $view);
        $this->assertStringNotContainsString('Kirim Undangan', $view);
        $this->assertStringNotContainsString('Aktifkan Langsung', $view);
        $this->assertStringNotContainsString('send_immediately', $view);
    }

    public function test_create_view_has_no_invitation_mode_controls(): void
    {
        $view = file_get_contents(resource_path('views/crm/admin-users/create.blade.php'));

        $this->assertStringNotContainsString('provisioning_mode', $view);
        $this->assertStringNotContainsString('submit_action', $view);
        $this->assertStringNotContainsString('UserInvitationNotification', $view);
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
