<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTest extends TestCase
{
    use RefreshDatabase;

    private function superadmin(): User
    {
        $role = Role::firstOrCreate(['slug' => 'superadmin'], ['name' => 'Super Admin', 'is_superadmin' => true]);

        return User::factory()->create(['role_id' => $role->id, 'password_changed_at' => now()]);
    }

    public function test_manifest_file_is_valid_json_with_required_values(): void
    {
        $path = public_path('manifest.webmanifest');
        $this->assertFileExists($path);

        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('OASIS CRM', $manifest['name']);
        $this->assertSame('OASIS', $manifest['short_name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('id', $manifest['lang']);
        $this->assertSame('ltr', $manifest['dir']);
        $this->assertSame('#000000', $manifest['theme_color']);
        $this->assertSame('#000000', $manifest['background_color']);
        $this->assertSame('./', $manifest['start_url']);
        $this->assertSame('./', $manifest['scope']);
    }

    public function test_manifest_declares_and_icons_exist(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, 512, JSON_THROW_ON_ERROR);

        $icons = collect($manifest['icons']);
        $this->assertTrue($icons->contains(fn ($icon) => $icon['sizes'] === '192x192' && $icon['purpose'] === 'any'));
        $this->assertTrue($icons->contains(fn ($icon) => $icon['sizes'] === '512x512' && $icon['purpose'] === 'any'));
        $this->assertTrue($icons->contains(fn ($icon) => $icon['sizes'] === '192x192' && $icon['purpose'] === 'maskable'));
        $this->assertTrue($icons->contains(fn ($icon) => $icon['sizes'] === '512x512' && $icon['purpose'] === 'maskable'));

        foreach ($icons as $icon) {
            $this->assertFileExists(public_path($icon['src']), $icon['src']);
            $this->assertSame('image/png', $icon['type']);
            [$width, $height, $type] = getimagesize(public_path($icon['src']));
            $this->assertSame((int) explode('x', $icon['sizes'])[0], $width);
            $this->assertSame((int) explode('x', $icon['sizes'])[1], $height);
            $this->assertSame(IMAGETYPE_PNG, $type);
        }
    }

    public function test_crm_layout_links_manifest_and_pwa_ui(): void
    {
        $user = $this->superadmin();

        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('rel="manifest" href="http://localhost/manifest.webmanifest"', $html);
        $this->assertStringContainsString('name="theme-color" content="#000000"', $html);
        $this->assertStringContainsString('rel="apple-touch-icon" href="http://localhost/apple-touch-icon.png"', $html);
        $this->assertStringContainsString('name="mobile-web-app-capable" content="yes"', $html);
        $this->assertStringContainsString('x-data="oasisPwa()"', $html);
        $this->assertStringContainsString('Pasang OASIS', $html);
        $this->assertStringContainsString('Perbarui sekarang', $html);
    }

    public function test_guest_layout_links_manifest_and_pwa_ui(): void
    {
        $html = $this->get(route('login'))->getContent();

        $this->assertStringContainsString('rel="manifest" href="http://localhost/manifest.webmanifest"', $html);
        $this->assertStringContainsString('name="theme-color" content="#000000"', $html);
        $this->assertStringContainsString('x-data="oasisPwa()"', $html);
    }

    public function test_pwa_js_registered_from_bootstrap(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));
        $pwaJs = file_get_contents(resource_path('js/pwa.js'));

        $this->assertStringContainsString('registerPwa(Alpine)', $appJs);
        $this->assertStringContainsString("navigator.serviceWorker.register('/service-worker.js')", $pwaJs);
        $this->assertStringContainsString('SKIP_WAITING', $pwaJs);
        $this->assertStringNotContainsString('confirm(', $pwaJs);
        $this->assertStringNotContainsString('alert(', $pwaJs);
    }

    public function test_service_worker_caches_only_static_assets(): void
    {
        $sw = file_get_contents(public_path('service-worker.js'));

        $this->assertStringContainsString("if (request.method !== 'GET')", $sw);
        $this->assertStringContainsString("url.pathname.startsWith('/build/')", $sw);
        $this->assertStringContainsString('cacheFirst(request)', $sw);

        $this->assertStringNotContainsString('stale-while-revalidate', $sw);
        $this->assertStringNotContainsString('workbox', strtolower($sw));
    }

    public function test_service_worker_navigation_is_network_first_with_offline_fallback(): void
    {
        $sw = file_get_contents(public_path('service-worker.js'));

        $this->assertStringContainsString("request.mode === 'navigate'", $sw);
        $this->assertStringContainsString('networkFirstNavigate(request)', $sw);
        $this->assertStringContainsString('return await fetch(request);', $sw);
        $this->assertStringContainsString("caches.match('/offline.html')", $sw);

        $navigateBody = $this->functionBody($sw, 'networkFirstNavigate');
        $this->assertStringNotContainsString('cache.put', $navigateBody, 'navigation responses must never be cached');
    }

    public function test_service_worker_never_caches_error_responses(): void
    {
        $sw = file_get_contents(public_path('service-worker.js'));

        $this->assertStringContainsString('response.ok', $sw);
        $cacheFirstBody = $this->functionBody($sw, 'cacheFirst');
        $this->assertStringContainsString('if (response.ok)', $cacheFirstBody);
    }

    public function test_service_worker_cleans_obsolete_caches_and_supports_update(): void
    {
        $sw = file_get_contents(public_path('service-worker.js'));

        $this->assertStringContainsString('key.startsWith(CACHE_PREFIX)', $sw);
        $this->assertStringContainsString('caches.delete(key)', $sw);
        $this->assertStringContainsString('SKIP_WAITING', $sw);
        $this->assertStringContainsString('self.skipWaiting()', $sw);
        $this->assertStringContainsString('self.clients.claim()', $sw);
    }

    public function test_offline_fallback_exists_with_safe_copy(): void
    {
        $path = public_path('offline.html');
        $this->assertFileExists($path);

        $html = file_get_contents($path);
        $this->assertStringContainsString('OASIS sedang offline', $html);
        $this->assertStringContainsString('Koneksi internet tidak tersedia', $html);
        $this->assertStringContainsString('Coba lagi', $html);
        $this->assertStringNotContainsString('location.href', $html);
    }

    public function test_security_headers_serve_manifest_content_type(): void
    {
        $htaccess = file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString('application/manifest+json', $htaccess);
        $this->assertStringContainsString('.webmanifest', $htaccess);
    }

    private function functionBody(string $source, string $functionName): string
    {
        $pattern = '/async function '.preg_quote($functionName, '/').'\((.*?)\)\s*\{(.*)\n\}/s';
        preg_match($pattern, $source, $matches);

        return $matches[2] ?? '';
    }
}
