<?php

namespace ScheduleKetchup;

use ScheduleKetchup\Contracts\RunRepository;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;

class DatabaseRunRepository implements RunRepository
{
    public function __construct(
        protected ConnectionInterface $connection,
        protected string $table = 'schedule_catchup_runs',
    ) {}

    public function lastStartedAt(string $name): ?DateTimeInterface
    {
        $run = $this->query()->where('name', $name)->first();

        if ($run === null) {
            // Start tracking now; until a run is recorded, this task's slots are
            // measured from here rather than treated as missed since forever.
            $this->query()->insertOrIgnore(['name' => $name, 'tracked_since' => Date::now()]);

            return null;
        }

        return Date::parse($run->last_started_at ?? $run->tracked_since);
    }

    public function recordStart(string $name, DateTimeInterface $startedAt): void
    {
        $this->query()->upsert(
            ['name' => $name, 'tracked_since' => $startedAt, 'last_started_at' => $startedAt],
            ['name'],
            ['last_started_at'],
        );
    }

    protected function query(): Builder
    {
        return $this->connection->table($this->table);
    }
}
