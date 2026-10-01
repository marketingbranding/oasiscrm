<?php

namespace Tests\Feature;

use App\Services\ExternalWriteGuard;
use App\Services\GoogleScriptService;
use App\Services\GoogleSheetsApiService;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class ExternalWriteGuardTest extends TestCase
{
    public function test_external_writes_are_blocked_by_default(): void
    {
        config(['services.external_writes.enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(ExternalWriteGuard::MESSAGE);

        app(ExternalWriteGuard::class)->assertAllowed();
    }

    public function test_google_script_post_is_blocked_before_network_request(): void
    {
        config([
            'services.external_writes.enabled' => false,
            'services.google_script.webhook_url' => 'https://example.test/webhook',
        ]);
        Http::fake();

        try {
            app(GoogleScriptService::class)->sendData(['action' => 'write']);
            $this->fail('External writes should be blocked.');
        } catch (RuntimeException $exception) {
            $this->assertSame(ExternalWriteGuard::MESSAGE, $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_google_script_post_requires_explicit_opt_in(): void
    {
        config([
            'services.external_writes.enabled' => true,
            'services.google_script.webhook_url' => 'https://example.test/webhook',
        ]);
        Http::fake([
            'https://example.test/webhook' => Http::response(['ok' => true]),
        ]);

        $result = app(GoogleScriptService::class)->sendData(['action' => 'write']);

        $this->assertTrue($result['success']);
        Http::assertSentCount(1);
    }

    public function test_google_sheets_mutation_is_blocked_before_the_api_client_is_touched(): void
    {
        config(['services.external_writes.enabled' => false]);
        $service = (new ReflectionClass(GoogleSheetsApiService::class))->newInstanceWithoutConstructor();
        $guard = (new ReflectionClass(GoogleSheetsApiService::class))->getProperty('externalWrites');
        $guard->setValue($service, app(ExternalWriteGuard::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(ExternalWriteGuard::MESSAGE);

        $service->updateRange('spreadsheet-id', 'A1', [['blocked']]);
    }
}
