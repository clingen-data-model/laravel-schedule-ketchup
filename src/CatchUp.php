<?php

namespace ScheduleKetchup;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use LogicException;
use WeakMap;

/**
 * Tracks which scheduled events opted in with ->catchUp(), and the name their
 * runs are recorded under.
 */
final class CatchUp
{
    /** @var WeakMap<Event, array{name: string|null}>|null */
    private static ?WeakMap $events = null;

    public static function enable(Event $event, ?string $name = null): void
    {
        self::events()[$event] = ['name' => $name];
    }

    /**
     * The run name for an opted-in event, or null if it did not opt in.
     *
     * Artisan commands use the command name (arguments ignored) so manual runs
     * count; other events use their description, then their shell command.
     */
    public static function nameFor(Event $event): ?string
    {
        if (! isset(self::events()[$event])) {
            return null;
        }

        return self::events()[$event]['name']
            ?? self::artisanCommandName($event)
            ?? (is_string($event->description) ? $event->description : null)
            ?? $event->command
            ?? throw new LogicException('A scheduled callback using catchUp() needs a name or description.');
    }

    /** @return list<string> */
    public static function names(Schedule $schedule): array
    {
        return array_values(array_filter(array_map(self::nameFor(...), $schedule->events())));
    }

    private static function artisanCommandName(Event $event): ?string
    {
        $prefix = Application::formatCommandString('');

        if (! is_string($event->command) || ! str_starts_with($event->command, $prefix)) {
            return null;
        }

        return Str::before(trim(Str::after($event->command, $prefix)), ' ');
    }

    private static function events(): WeakMap
    {
        return self::$events ??= new WeakMap;
    }
}
