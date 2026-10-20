<?php

namespace Tests\Unit;

use App\Services\GoogleSheetsApiService;
use RuntimeException;
use Tests\TestCase;

class GoogleSheetsApiServiceTest extends TestCase
{
    public function test_real_google_service_still_requires_credentials_when_constructed_directly(): void
    {
        $missingCredentialsPath = storage_path('app/google/ci-test-missing-service-account.json');
        config()->set('services.google_sheets.credentials_path', $missingCredentialsPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google Sheets credentials file tidak ditemukan: '.$missingCredentialsPath);

        new GoogleSheetsApiService;
    }
}
