<?php

namespace Tests\Feature;

use Tests\TestCase;

class DesignSystemFoundationTest extends TestCase
{
    public function test_required_tokens_are_defined_once_in_shared_css(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $tokens = [
            'page-bg', 'surface', 'surface-muted', 'surface-raised', 'surface-selected', 'surface-disabled',
            'text', 'text-muted', 'text-disabled', 'border-subtle', 'border', 'border-strong', 'yellow',
            'focus', 'success', 'warning', 'danger', 'info', 'neutral',
            'space-1', 'space-2', 'space-3', 'space-4', 'space-5', 'space-6', 'space-8', 'space-10', 'space-12', 'space-16',
            'radius-none', 'radius-sm', 'radius-md', 'shadow-none', 'shadow-subtle', 'shadow-elevated',
            'duration-fast', 'duration-standard', 'duration-slow', 'control-height',
        ];

        foreach ($tokens as $token) {
            $this->assertSame(1, preg_match_all('/^\s*--oasis-'.preg_quote($token, '/').':/m', $css), $token);
        }
    }

    public function test_canonical_components_do_not_embed_routes_or_authorization(): void
    {
        foreach (['button', 'icon-button', 'card', 'section', 'status-badge', 'alert', 'empty-state', 'loading-state', 'toolbar', 'filter-chip', 'field', 'input-error', 'modal'] as $component) {
            $source = file_get_contents(resource_path("views/components/crm/{$component}.blade.php"));
            $this->assertStringNotContainsString('route(', $source, $component);
            $this->assertStringNotContainsString('hasPermission', $source, $component);
            $this->assertStringNotContainsString('@can', $source, $component);
        }
    }

    public function test_responsive_and_accessibility_source_contracts_are_present(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $modal = file_get_contents(resource_path('views/components/crm/modal.blade.php'));
        $modalJs = file_get_contents(resource_path('js/crm-modal.js'));
        $bodyLock = file_get_contents(resource_path('js/body-scroll-lock.js'));

        $this->assertStringContainsString('--oasis-control-height: 2.75rem', $css);
        $this->assertStringContainsString('max-height: calc(100dvh', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $this->assertStringContainsString('.crm-toolbar', $css);
        $this->assertStringContainsString('role="dialog"', $modal);
        $this->assertStringContainsString('aria-modal="true"', $modal);
        $this->assertStringContainsString("event.key === 'Escape'", $modalJs);
        $this->assertStringContainsString("event.key !== 'Tab'", $modalJs);
        $this->assertStringContainsString('lockBodyScroll(this.lockOwner)', $modalJs);
        $this->assertStringContainsString('const locks = new Set()', $bodyLock);
        $this->assertStringContainsString('locks.size > 0', $bodyLock);
        $this->assertStringContainsString("document.body.style.overflow = 'hidden'", $bodyLock);
        $this->assertStringContainsString('this.trigger?.focus()', $modalJs);
    }

    public function test_canonical_table_foundation_remains_intact(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.crm-table-scroll', $css);
        $this->assertStringContainsString('overflow: auto', $css);
        $this->assertStringContainsString('.crm-data-table', $css);
        $this->assertStringContainsString('border-collapse: separate', $css);
        $this->assertStringContainsString('border-spacing: 0', $css);
        $this->assertStringContainsString('position: sticky', $css);
    }
}
