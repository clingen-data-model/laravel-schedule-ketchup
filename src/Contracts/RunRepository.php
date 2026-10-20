<?php

namespace ScheduleKetchup\Contracts;

use DateTimeInterface;

interface RunRepository
{
    /**
     * When the task last started, or when tracking it began if it has never
     * started. Null means unknown: the event falls back to exact-minute matching.
     */
    public function lastStartedAt(string $name): ?DateTimeInterface;

    public function recordStart(string $name, DateTimeInterface $startedAt): void;
}
