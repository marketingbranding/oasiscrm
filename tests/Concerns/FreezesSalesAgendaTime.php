<?php

namespace Tests\Concerns;

use Carbon\Carbon;

trait FreezesSalesAgendaTime
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-18 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
