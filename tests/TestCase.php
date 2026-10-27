<?php

namespace Tests;

use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery;
use Mockery\MockInterface;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGoogleSheets();

        config([
            'oasis.database_operational_enabled' => true,
            'oasis.consumer_progress_operational_enabled' => true,
            'oasis.spreadsheet_sync_enabled' => true,
        ]);
    }

    protected function fakeGoogleSheets(array $sheetTitles = []): MockInterface
    {
        $googleSheets = Mockery::mock(GoogleSheetsApiService::class);
        $googleSheets->shouldReceive('sheetTitles')->byDefault()->andReturn($sheetTitles);
        $this->app->instance(GoogleSheetsApiService::class, $googleSheets);

        return $googleSheets;
    }
}
