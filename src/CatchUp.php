<?php

namespace ScheduleKetchup;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use WeakMap;

/**
 * Tracks which scheduled events opted in with ->catchUp(), and names events
 * for their recorded runs.
 */
final class CatchUp
{
    /** @var WeakMap<Event, array{name: string|null}>|null */
    private static ?WeakMap $events = null;

    public static function enable(Event $event, ?string $name = null): void
    {
        self::events()[$event] = ['name' => $name];
    }

    public static function enabled(Event $event): bool
    {
        return isset(self::events()[$event]);
    }

    /**
     * The name an event's runs are recorded under: its catchUp() name, else the
     * Artisan command name (arguments ignored, so manual runs match), else its
     * description, else its shell command. Null for an unnamed callback.
     */
    public static function nameFor(Event $event): ?string
    {
        return self::events()[$event]['name']
            ?? self::artisanCommand($event)
            ?? (is_string($event->description) ? $event->description : null)
            ?? $event->command;
    }

    /** Name of the Artisan command an event runs, or null if it isn't one. */
    public static function artisanCommand(Event $event): ?string
    {
        $input = self::artisanInput($event);

        return $input === null ? null : Str::before($input, ' ');
    }

    /** The command line an Artisan event runs, without the php and artisan binaries. */
    public static function artisanInput(Event $event): ?string
    {
        $prefix = Application::formatCommandString('');

        if (! is_string($event->command) || ! str_starts_with($event->command, $prefix)) {
            return null;
        }

        return trim(Str::after($event->command, $prefix));
    }

    /** @return list<string> */
    public static function artisanCommands(Schedule $schedule): array
    {
        return array_values(array_filter(array_map(self::artisanCommand(...), $schedule->events())));
    }

    private static function events(): WeakMap
    {
        return self::$events ??= new WeakMap;
    }
}
