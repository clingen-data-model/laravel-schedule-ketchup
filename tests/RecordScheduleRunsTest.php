<?php

namespace ScheduleKetchup\Tests;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class RecordScheduleRunsTest extends TestCase
{
    private const CONTEXT_KEY = 'schedule-ketchup.run';

    private function startCommand(string $command): void
    {
        event(new CommandStarting($command, new ArrayInput([]), new NullOutput));
    }

    private function finishCommand(string $command, int $exitCode = 0): void
    {
        event(new CommandFinished($command, new ArrayInput([]), new NullOutput, $exitCode));
    }

    private function runs(): array
    {
        return DB::table('schedule_runs')->orderBy('id')->get()->map(fn ($run) => (array) $run)->all();
    }

    #[Test]
    public function records_in_process_task_usage()
    {
        $task = $this->schedule()->call(fn () => null)->daily()->name('cleanup');

        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskFinished($task, 0.1));

        [$run] = $this->runs();
        $this->assertSame(['cleanup', 'cleanup', 1, '2026-10-19 03:30:00', 0], [$run['name'], $run['command'], $run['scheduled'], $run['started_at'], $run['exit_code']]);
        $this->assertNotNull($run['wall_seconds']);
        $this->assertNotNull($run['cpu_user_seconds']);
        $this->assertNotNull($run['php_peak_bytes']);
        $this->assertNull($run['max_rss_kb']);
    }

    #[Test]
    public function records_failed_in_process_tasks()
    {
        $task = $this->schedule()->call(fn () => null)->daily()->name('cleanup');

        event(new ScheduledTaskStarting($task));
        event(new ScheduledTaskFailed($task, new RuntimeException));

        $this->assertSame(1, $this->runs()[0]['exit_code']);
    }

    #[Test]
    public function passes_artisan_task_runs_to_the_child_process()
    {
        $task = $this->schedule()->command('inspire --flag')->daily()->catchUp('daily-inspiration');

        event(new ScheduledTaskStarting($task));

        [$run] = $this->runs();
        $this->assertSame(['daily-inspiration', 'inspire --flag'], [$run['name'], $run['command']]);
        $this->assertSame(['id' => $run['id'], 'command' => 'inspire'], Context::getHidden(self::CONTEXT_KEY));

        event(new ScheduledTaskFinished($task, 0.1));

        $this->assertNull(Context::getHidden(self::CONTEXT_KEY));
        $this->assertNull($this->runs()[0]['wall_seconds']);
    }

    #[Test]
    public function child_process_records_usage_on_the_scheduled_run()
    {
        $id = $this->recordRun('daily-inspiration', '2026-10-19 03:30:00');
        Context::addHidden(self::CONTEXT_KEY, ['id' => $id, 'command' => 'inspire']);

        $this->startCommand('inspire');
        $this->assertNull(Context::getHidden(self::CONTEXT_KEY));
        $this->finishCommand('inspire', 3);

        [$run] = $this->runs();
        $this->assertCount(1, $this->runs());
        $this->assertSame(3, $run['exit_code']);
        $this->assertNotNull($run['wall_seconds']);
        $this->assertNotNull($run['max_rss_kb']);
    }

    #[Test]
    public function schedule_finish_does_not_claim_the_scheduled_run()
    {
        $id = $this->recordRun('inspire', '2026-10-19 03:30:00');
        Context::addHidden(self::CONTEXT_KEY, ['id' => $id, 'command' => 'inspire']);

        $this->startCommand('schedule:finish');
        $this->finishCommand('schedule:finish');

        $this->assertCount(1, $this->runs());
        $this->assertNull($this->runs()[0]['exit_code']);
    }

    #[Test]
    public function records_manual_runs_of_scheduled_commands_and_schedule_run()
    {
        $this->schedule()->command('inspire')->daily();

        foreach (['inspire', 'about', 'schedule:run'] as $command) {
            $this->startCommand($command);
            $this->finishCommand($command);
        }

        $this->assertSame([['inspire', 0], ['schedule:run', 0]], array_map(fn ($run) => [$run['name'], $run['scheduled']], $this->runs()));
    }

    #[Test]
    public function first_heartbeat_allows_a_currently_due_task_to_run_once()
    {
        Date::setTestNow('2026-10-19 01:00:20');
        $calls = 0;
        $this->schedule()->call(function () use (&$calls) { $calls++; })
            ->dailyAt('01:00')->name('first')->catchUp();

        // Testbench disables the real console command events, so emit the
        // heartbeat event that precedes schedule:run in a normal invocation.
        $this->startCommand('schedule:run');
        $this->artisan('schedule:run')->assertSuccessful();
        $this->finishCommand('schedule:run');
        $this->assertSame(1, $calls);

        $this->startCommand('schedule:run');
        $this->artisan('schedule:run')->assertSuccessful();
        $this->finishCommand('schedule:run');
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function first_heartbeat_does_not_replay_earlier_minutes()
    {
        $calls = 0;
        $this->schedule()->call(function () use (&$calls) { $calls++; })
            ->dailyAt('01:00')->name('first')->catchUp();

        $this->startCommand('schedule:run');
        $this->artisan('schedule:run')->assertSuccessful();
        $this->finishCommand('schedule:run');

        $this->assertSame(0, $calls);
    }

    #[Test]
    public function nested_commands_are_part_of_the_outer_run()
    {
        $this->schedule()->command('inspire')->daily();

        $this->startCommand('schedule:run');
        $this->startCommand('inspire');
        $this->finishCommand('inspire');
        $this->finishCommand('schedule:run');

        $this->assertSame(['schedule:run'], array_column($this->runs(), 'name'));
        $this->assertNotNull($this->runs()[0]['wall_seconds']);
    }
}
