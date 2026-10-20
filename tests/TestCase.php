<?php

namespace ScheduleKetchup\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Orchestra\Testbench\TestCase as Orchestra;
use ScheduleKetchup\CatchUpServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app)
    {
        return [CatchUpServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-10-19 03:30:00');
    }

    protected function tearDown(): void
    {
        Date::setTestNow();
        parent::tearDown();
    }

    protected function schedule(): Schedule
    {
        return $this->app->make(Schedule::class);
    }

    protected function recordRun(string $name, string $startedAt): int
    {
        return DB::table('schedule_runs')->insertGetId(['name' => $name, 'scheduled' => true, 'started_at' => $startedAt]);
    }
}
