<?php

namespace ScheduleKetchup;

use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Context;
use Throwable;
use WeakMap;

/**
 * Records each scheduled task run, and manual runs of scheduled Artisan
 * commands, with wall time, CPU and peak memory.
 *
 * Scheduled Artisan commands run in their own process. schedule:run inserts
 * the row and passes its id through Laravel's context, which the scheduler
 * hands to the child, and the child records its own usage. Callbacks and jobs
 * run inside schedule:run and are measured there.
 *
 * Must be a container singleton: it tracks command nesting across events.
 */
class RecordScheduleRuns
{
    private const CONTEXT_KEY = 'schedule-ketchup.run';

    private const RUSAGE_CHILDREN = 1;

    private int $depth = 0;

    private ?int $commandRun = null;

    private float $commandStartedAt = 0;

    private int $peakBeforeReset = 0;

    /** @var WeakMap<Event, array{id: int, started: float, self: array, children: array}> */
    private WeakMap $taskRuns;

    public function __construct(
        private Container $app,
        private ScheduleRuns $runs,
    ) {
        $this->taskRuns = new WeakMap;
    }

    public function subscribe(): array
    {
        return [
            CommandStarting::class => 'commandStarting',
            CommandFinished::class => 'commandFinished',
            ScheduledTaskStarting::class => 'taskStarting',
            ScheduledTaskFinished::class => 'taskFinished',
            ScheduledTaskFailed::class => 'taskFinished',
        ];
    }

    public function commandStarting(CommandStarting $event): void
    {
        // Commands invoked via Artisan::call() share the process, so only the
        // outermost one has meaningful process-wide usage.
        if ($this->depth++ > 0) {
            return;
        }

        $this->commandStartedAt = microtime(true);
        $this->commandRun = $this->adoptScheduledRun($event->command)
            ?? $this->guard(fn () => $this->startManualRun($event));
    }

    public function commandFinished(CommandFinished $event): void
    {
        if ($this->depth === 0 || --$this->depth > 0 || $this->commandRun === null) {
            return;
        }

        $self = getrusage();
        $children = getrusage(self::RUSAGE_CHILDREN);

        $this->guard(fn () => $this->runs->finish($this->commandRun, [
            'exit_code' => $event->exitCode,
            'wall_seconds' => round(microtime(true) - $this->commandStartedAt, 3),
            'cpu_user_seconds' => $this->seconds($self, 'utime'),
            'cpu_system_seconds' => $this->seconds($self, 'stime'),
            'max_rss_kb' => $self['ru_maxrss'],
            'php_peak_bytes' => max($this->peakBeforeReset, memory_get_peak_usage(true)),
            'children_cpu_seconds' => $this->seconds($children, 'utime') + $this->seconds($children, 'stime'),
            'children_max_rss_kb' => $children['ru_maxrss'],
        ]));
    }

    public function taskStarting(ScheduledTaskStarting $event): void
    {
        $task = $event->task;
        $artisanInput = CatchUp::artisanInput($task);

        $id = $this->guard(fn () => $this->runs->start(
            CatchUp::nameFor($task) ?? $task->getSummaryForDisplay(),
            $artisanInput ?? $task->command ?? $task->getSummaryForDisplay(),
            scheduled: true,
        ));

        if ($id === null) {
            return;
        }

        if ($artisanInput !== null) {
            Context::addHidden(self::CONTEXT_KEY, ['id' => $id, 'command' => CatchUp::artisanCommand($task)]);
        }

        // Lets each in-process task's peak be measured on its own.
        $this->peakBeforeReset = max($this->peakBeforeReset, memory_get_peak_usage(true));
        memory_reset_peak_usage();

        $this->taskRuns[$task] = [
            'id' => $id,
            'started' => microtime(true),
            'self' => getrusage(),
            'children' => getrusage(self::RUSAGE_CHILDREN),
        ];
    }

    public function taskFinished(ScheduledTaskFinished|ScheduledTaskFailed $event): void
    {
        Context::forgetHidden(self::CONTEXT_KEY);

        $task = $event->task;
        $run = $this->taskRuns[$task] ?? null;
        unset($this->taskRuns[$task]);

        // Artisan commands record their own usage; background tasks have only been launched.
        if ($run === null || $task->runInBackground || CatchUp::artisanCommand($task) !== null) {
            return;
        }

        $children = getrusage(self::RUSAGE_CHILDREN);
        $usage = [
            'exit_code' => $task->exitCode ?? ($event instanceof ScheduledTaskFailed ? 1 : 0),
            'wall_seconds' => round(microtime(true) - $run['started'], 3),
            'children_cpu_seconds' => $this->cpuSince($run['children'], $children),
        ];

        // Shell commands run as a child process, so only their CPU is known.
        if ($task instanceof CallbackEvent) {
            $self = getrusage();
            $usage += [
                'cpu_user_seconds' => round($this->seconds($self, 'utime') - $this->seconds($run['self'], 'utime'), 3),
                'cpu_system_seconds' => round($this->seconds($self, 'stime') - $this->seconds($run['self'], 'stime'), 3),
                'php_peak_bytes' => memory_get_peak_usage(true),
            ];
        }

        $this->guard(fn () => $this->runs->finish($run['id'], $usage));
    }

    private function adoptScheduledRun(?string $command): ?int
    {
        $run = Context::getHidden(self::CONTEXT_KEY);

        // Cleared so this command's own subprocesses don't claim the run. The
        // command check skips schedule:finish, which background tasks also pass it to.
        Context::forgetHidden(self::CONTEXT_KEY);

        return is_array($run) && $run['command'] === $command ? $run['id'] : null;
    }

    private function startManualRun(CommandStarting $event): ?int
    {
        // schedule:run is recorded as a heartbeat, and so catch-up has a starting point.
        $recorded = $event->command === 'schedule:run'
            || in_array($event->command, CatchUp::artisanCommands($this->app->make(Schedule::class)), true);

        return $recorded ? $this->runs->start($event->command, (string) $event->input, scheduled: false) : null;
    }

    private function cpuSince(array $before, array $after): float
    {
        return round(
            $this->seconds($after, 'utime') + $this->seconds($after, 'stime')
            - $this->seconds($before, 'utime') - $this->seconds($before, 'stime'),
            3,
        );
    }

    private function seconds(array $usage, string $field): float
    {
        return round($usage["ru_{$field}.tv_sec"] + $usage["ru_{$field}.tv_usec"] / 1e6, 3);
    }

    /** Recording must never stop a task from running. */
    private function guard(Closure $record): mixed
    {
        try {
            return $record();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
