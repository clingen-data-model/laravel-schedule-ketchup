<?php

namespace ScheduleKetchup\Tests;

use ScheduleKetchup\CatchUp;
use ScheduleKetchup\CatchUpSchedule;
use ScheduleKetchup\CatchUpServiceProvider;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use LogicException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class CatchUpScheduleTest extends TestCase
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

    private function schedule(): Schedule
    {
        return $this->app->make(Schedule::class);
    }

    private function isDue(Event $event): bool
    {
        return $this->schedule()->dueEvents($this->app)->contains($event);
    }

    private function recordRun(string $name, string $startedAt): void
    {
        DB::table('schedule_catchup_runs')->insert(['name' => $name, 'tracked_since' => '2026-01-01', 'last_started_at' => $startedAt]);
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
    public function first_check_starts_tracking_and_uses_exact_minute_matching()
    {
        $event = $this->schedule()->command('inspire')->dailyAt('01:00')->catchUp();

        $this->assertFalse($this->isDue($event));
        $this->assertSame('2026-10-19 03:30:00', DB::table('schedule_catchup_runs')->value('tracked_since'));

        Date::setTestNow('2026-10-20 03:30:00');
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
        $unnamed = $this->schedule()->call(fn () => null)->daily()->catchUp();

        $this->assertSame('nightly-sync', CatchUp::nameFor($named));
        $this->assertSame('cleanup', CatchUp::nameFor($described));
        $this->expectException(LogicException::class);
        CatchUp::nameFor($unnamed);
    }

    #[Test]
    public function records_scheduled_task_starts()
    {
        $event = $this->schedule()->call(fn () => null)->daily()->catchUp('nightly-sync');
        $other = $this->schedule()->call(fn () => null)->daily()->name('other');

        event(new ScheduledTaskStarting($event));
        event(new ScheduledTaskStarting($other));

        $this->assertSame(['nightly-sync' => '2026-10-19 03:30:00'], DB::table('schedule_catchup_runs')->pluck('last_started_at', 'name')->all());
    }

    #[Test]
    public function records_manual_runs_of_catch_up_commands_only()
    {
        $this->schedule()->command('inspire')->daily()->catchUp();
        $this->schedule()->command('about')->daily();

        foreach (['inspire', 'about'] as $command) {
            event(new CommandStarting($command, new ArrayInput([]), new NullOutput));
        }

        $this->assertSame(['inspire'], DB::table('schedule_catchup_runs')->pluck('name')->all());
    }
}
