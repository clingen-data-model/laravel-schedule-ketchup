<?php

namespace ScheduleKetchup;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class CatchUpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/schedule-ketchup.php', 'schedule-ketchup');

        $this->app->singleton(ScheduleRuns::class);
        $this->app->singleton(RecordScheduleRuns::class);

        $this->app->extend(Schedule::class, fn (Schedule $schedule) => CatchUpSchedule::from($schedule));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([__DIR__.'/../config/schedule-ketchup.php' => config_path('schedule-ketchup.php')], 'schedule-ketchup-config');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneScheduleRuns::class]);
        }

        Event::macro('catchUp', function (?string $name = null) {
            /** @var Event $this */
            CatchUp::enable($this, $name);

            return $this;
        });

        $this->app['events']->subscribe(RecordScheduleRuns::class);
    }
}
