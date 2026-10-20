<?php

namespace ScheduleKetchup;

use ScheduleKetchup\Contracts\RunRepository;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class CatchUpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(RunRepository::class, fn ($app) => new DatabaseRunRepository($app['db']->connection()));

        $this->app->extend(Schedule::class, fn (Schedule $schedule) => CatchUpSchedule::from($schedule));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Event::macro('catchUp', function (?string $name = null) {
            /** @var Event $this */
            CatchUp::enable($this, $name);

            return $this;
        });

        $this->app['events']->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            if (($name = CatchUp::nameFor($event->task)) !== null) {
                $this->app->make(RunRepository::class)->recordStart($name, Date::now());
            }
        });

        // Manual runs count as runs, so a task run by hand isn't repeated at the next schedule:run.
        $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event) {
            if ($event->command !== null && in_array($event->command, CatchUp::names($this->app->make(Schedule::class)), true)) {
                $this->app->make(RunRepository::class)->recordStart($event->command, Date::now());
            }
        });
    }
}
