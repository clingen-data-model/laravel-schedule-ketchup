<?php

namespace ScheduleKetchup;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class CatchUpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ScheduleRuns::class, fn ($app) => new ScheduleRuns($app['db']->connection()));
        $this->app->singleton(RecordScheduleRuns::class);

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

        $this->app['events']->subscribe(RecordScheduleRuns::class);
    }
}
