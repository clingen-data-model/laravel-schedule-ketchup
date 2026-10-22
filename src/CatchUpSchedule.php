<?php

namespace ScheduleKetchup;

use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use LogicException;
use ReflectionClass;
use Throwable;

/**
 * A schedule on which opted-in events are due when their most recent scheduled
 * time is later than their last recorded start, rather than only during the
 * exact due minute.
 */
class CatchUpSchedule extends Schedule
{
    /**
     * Copies a schedule the application already built, so it keeps the events
     * and settings defined through the console kernel or withSchedule().
     */
    public static function from(Schedule $schedule): static
    {
        $catchUp = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

        foreach (get_object_vars($schedule) as $property => $value) {
            $catchUp->{$property} = $value;
        }

        return $catchUp;
    }

    public function dueEvents($app)
    {
        return (new Collection($this->events))->filter(fn (Event $event) => $this->isDue($event, $app));
    }

    protected function isDue(Event $event, $app): bool
    {
        if (! CatchUp::enabled($event)) {
            return $event->isDue($app);
        }

        $name = CatchUp::nameFor($event)
            ?? throw new LogicException('A scheduled callback using catchUp() needs a name or description.');

        try {
            $lastStarted = $app->make(ScheduleRuns::class)->lastStartedAt($name);
        } catch (Throwable $e) {
            report($e);

            return $event->isDue($app);
        }

        if ($lastStarted === null) {
            return $event->isDue($app);
        }

        // Mirrors Event::isDue() with the exact-minute check replaced; revisit on Laravel upgrades.
        if (! $event->runsInMaintenanceMode() && $app->isDownForMaintenance()) {
            return false;
        }

        return $this->lastDueAt($event) > $lastStarted
            && $event->runsInEnvironment($app->environment());
    }

    protected function lastDueAt(Event $event): \DateTime
    {
        $timezone = $event->timezone instanceof DateTimeZone ? $event->timezone->getName() : $event->timezone;

        return (new CronExpression($event->expression))->getPreviousRunDate(Date::now(), 0, true, $timezone);
    }
}
