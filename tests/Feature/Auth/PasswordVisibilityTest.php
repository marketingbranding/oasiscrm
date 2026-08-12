<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PasswordVisibilityTest extends TestCase
{
    private const ACTIVE_FORMS = [
        'auth/login.blade.php',
        'auth/confirm-password.blade.php',
        'auth/reset-password.blade.php',
        'auth/force-password-change.blade.php',
        'auth/activate-invitation.blade.php',
        'profile/partials/update-password-form.blade.php',
        'crm/admin-users/create.blade.php',
    ];

    public function test_all_active_password_fields_use_password_input_component(): void
    {
        $source = collect(self::ACTIVE_FORMS)
            ->map(fn (string $file): string => file_get_contents(resource_path("views/{$file}")))
            ->implode("\n");

        $this->assertSame(13, substr_count($source, '<x-password-input'));
        $this->assertDoesNotMatchRegularExpression('/<input\b[^>]*\btype=["\']password["\']/i', $source);
        $this->assertDoesNotMatchRegularExpression('/<x-text-input\b[^>]*\btype=["\']password["\']/i', $source);
        $this->assertSame(3, substr_count($source, 'autocomplete="current-password"'));
        $this->assertSame(10, substr_count($source, 'autocomplete="new-password"'));
        $this->assertStringNotContainsString('<x-password-input', file_get_contents(resource_path('views/auth/register.blade.php')));
    }

    public function test_component_renders_forwarded_attributes_and_accessible_toggle(): void
    {
        $html = Blade::render('<x-password-input id="password" name="password" required autofocus autocomplete="new-password" class="custom" :disabled="true" show-label="Tampilkan password" hide-label="Sembunyikan password" />');

        $this->assertStringContainsString('x-data="{ visible: false }"', $html);
        $this->assertStringContainsString(':type="visible ? \'text\' : \'password\'"', $html);
        $this->assertStringContainsString('id="password"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('autocomplete="new-password"', $html);
        $this->assertStringContainsString('autofocus', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('class="pr-12 custom"', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString(':aria-label="visible ?', $html);
        $this->assertStringContainsString(':title="visible ?', $html);
        $this->assertStringContainsString(':aria-pressed="visible.toString()"', $html);
        $this->assertStringContainsString('Tampilkan password', $html);
        $this->assertStringContainsString('Sembunyikan password', $html);
        $this->assertSame(2, substr_count($html, 'aria-hidden="true"'));
        $this->assertStringContainsString('focus-visible:outline', $html);
        $this->assertStringNotContainsString('localStorage', $html);
        $this->assertStringNotContainsString('sessionStorage', $html);
        $this->assertStringNotContainsString('value=', $html);
    }

    public function test_each_active_form_component_compiles(): void
    {
        foreach (self::ACTIVE_FORMS as $file) {
            $source = file_get_contents(resource_path("views/{$file}"));

            $this->assertNotEmpty(Blade::compileString($source), $file);
        }
    }
}
