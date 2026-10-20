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
     * from the earliest recorded run of anything; null if nothing is recorded.
     */
    public function lastStartedAt(string $name): ?DateTimeInterface
    {
        $startedAt = $this->query()->where('name', $name)->max('started_at') ?? $this->query()->min('started_at');

        return $startedAt === null ? null : Date::parse($startedAt);
    }

    /**
     * Inserted when the run starts, so in-progress and crashed runs are visible
     * and count as runs.
     */
    public function start(string $name, ?string $command, bool $scheduled): int
    {
        return $this->query()->insertGetId([
            'name' => Str::limit($name, 255, ''),
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

    protected function query(): Builder
    {
        return ScheduleRun::query()->toBase();
    }
}
