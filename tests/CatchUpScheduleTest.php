<?php

namespace ScheduleKetchup\Tests;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use ScheduleKetchup\CatchUp;
use ScheduleKetchup\CatchUpSchedule;

class CatchUpScheduleTest extends TestCase
{
    private function isDue(Event $event): bool
    {
        return $this->schedule()->dueEvents($this->app)->contains($event);
    }

    #[Test]
    public function app_schedule_is_a_catch_up_schedule_keeping_existing_events()
    {
        $this->app->forgetInstance(Schedule::class);
        $this->app->afterResolving(Schedule::class, fn (Schedule $s) => $s->command('inspire')->hourly());

        $this->assertInstanceOf(CatchUpSchedule::class, $this->schedule());
        $this->assertCount(1, $this->schedule()->events());
    }

    #[Test]
    public function due_when_not_started_since_last_due_time()
    {
        $event = $this->schedule()->command('inspire --flag')->dailyAt('01:00')->catchUp();
        $this->recordRun('inspire', '2026-10-18 01:00:05');

        $this->assertTrue($this->isDue($event));
    }

    #[Test]
    public function not_due_when_started_since_last_due_time()
    {
        $event = $this->schedule()->command('inspire')->dailyAt('01:00')->catchUp();
        $this->recordRun('inspire', '2026-10-18 01:00:05');
        $this->recordRun('inspire', '2026-10-19 01:00:05');

        $this->assertFalse($this->isDue($event));
    }

    #[Test]
    public function not_due_during_exact_minute_once_started()
    {
        Date::setTestNow('2026-10-19 01:00:40');
        $event = $this->schedule()->command('inspire')->dailyAt('01:00')->catchUp();
        $this->recordRun('inspire', '2026-10-19 01:00:05');

        $this->assertFalse($this->isDue($event));
    }

    #[Test]
    public function events_without_catch_up_use_exact_minute_matching()
    {
        $event = $this->schedule()->command('inspire')->dailyAt('01:00');
        $this->recordRun('inspire', '2026-10-18 01:00:05');

        $this->assertFalse($this->isDue($event));
    }

    #[Test]
    public function never_run_tasks_are_measured_from_the_earliest_recorded_run()
    {
        $event = $this->schedule()->command('inspire')->dailyAt('01:00')->catchUp();
        $this->recordRun('schedule:run', '2026-10-19 02:00:00');

        $this->assertFalse($this->isDue($event));

        Date::setTestNow('2026-10-20 03:30:00');
        $this->assertTrue($this->isDue($event));
    }

    #[Test]
    public function uses_exact_minute_matching_when_nothing_is_recorded()
    {
        $event = $this->schedule()->command('inspire')->dailyAt('01:00')->catchUp();

        $this->assertFalse($this->isDue($event));

        Date::setTestNow('2026-10-20 01:00:00');
        $this->assertTrue($this->isDue($event));
    }

    #[Test]
    public function respects_environments_and_maintenance_mode()
    {
        $event = $this->schedule()->command('inspire')->dailyAt('01:00')->catchUp()->environments('production');
        $this->recordRun('inspire', '2026-10-18 01:00:05');

        $this->assertFalse($this->isDue($event));

        $event->environments([]);
        $this->app->maintenanceMode()->activate([]);
        $this->beforeApplicationDestroyed(fn () => $this->app->maintenanceMode()->deactivate());
        $this->assertFalse($this->isDue($event));

        $event->evenInMaintenanceMode();
        $this->assertTrue($this->isDue($event));
    }

    #[Test]
    public function uses_event_timezone()
    {
        $event = $this->schedule()->command('inspire')->dailyAt('01:00')->timezone(new \DateTimeZone('America/New_York'))->catchUp();
        // 01:00 New York is 05:00 UTC, so the last due time is the previous day's.
        $this->recordRun('inspire', '2026-10-18 05:00:05');

        $this->assertFalse($this->isDue($event));
    }

    #[Test]
    public function names_callbacks_by_explicit_name_or_description()
    {
        $named = $this->schedule()->call(fn () => null)->daily()->catchUp('nightly-sync');
        $described = $this->schedule()->call(fn () => null)->daily()->catchUp()->name('cleanup');

        $this->assertSame('nightly-sync', CatchUp::nameFor($named));
        $this->assertSame('cleanup', CatchUp::nameFor($described));
    }

    #[Test]
    public function unnamed_catch_up_callbacks_are_rejected()
    {
        $this->schedule()->call(fn () => null)->daily()->catchUp();

        $this->expectException(LogicException::class);
        $this->schedule()->dueEvents($this->app);
    }
}
