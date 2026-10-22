<?php

namespace ScheduleKetchup;

use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

class ScheduleRuns
{
    /**
     * When the named task last started. A task that has never run is measured
     * from just before the minute of the earliest recorded run of anything;
     * null if nothing is recorded.
     */
    public function lastStartedAt(string $name): ?DateTimeInterface
    {
        $startedAt = $this->query()->where('name', $this->storedName($name))->max('started_at');

        if ($startedAt !== null) {
            return Date::parse($startedAt);
        }

        $earliest = $this->query()->min('started_at');

        // Include the first recorded minute: schedule:run writes its heartbeat
        // before checking due events, including on the very first invocation.
        return $earliest === null ? null : Date::parse($earliest)->startOfMinute()->subSecond();
    }

    /**
     * Inserted when the run starts, so in-progress and crashed runs are visible
     * and count as runs.
     */
    public function start(string $name, ?string $command, bool $scheduled): int
    {
        return $this->query()->insertGetId([
            'name' => $this->storedName($name),
            'command' => $command === null ? null : Str::limit($command, 1000, ''),
            'scheduled' => $scheduled,
            'host' => Str::limit((string) gethostname(), 255, ''),
            'started_at' => Date::now(),
        ]);
    }

    /** @param  array<string, int|float|null>  $usage */
    public function finish(int $id, array $usage): void
    {
        $this->query()->where('id', $id)->update($usage);
    }

    private function storedName(string $name): string
    {
        return Str::length($name) <= 255
            ? $name
            : Str::substr($name, 0, 190).'#'.hash('sha256', $name);
    }

    protected function query(): Builder
    {
        return ScheduleRun::query()->toBase();
    }
}
