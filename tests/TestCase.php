<?php

namespace Tests;

use App\Services\GoogleSheetsApiService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery;
use Mockery\MockInterface;

abstract class TestCase extends BaseTestCase
{
    protected function fakeGoogleSheets(array $sheetTitles = []): MockInterface
    {
        $googleSheets = Mockery::mock(GoogleSheetsApiService::class);
        $googleSheets->shouldReceive('sheetTitles')->byDefault()->andReturn($sheetTitles);
        $this->app->instance(GoogleSheetsApiService::class, $googleSheets);

        return $googleSheets;
    }
}
