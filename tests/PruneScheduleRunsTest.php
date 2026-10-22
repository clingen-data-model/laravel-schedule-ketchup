<?php

namespace ScheduleKetchup\Tests;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class PruneScheduleRunsTest extends TestCase
{
    #[Test]
    public function prunes_runs_older_than_the_configured_age()
    {
        config(['schedule-ketchup.prune_after_days' => 30]);
        $this->recordRun('old', '2026-09-18 03:29:59');
        $this->recordRun('recent', '2026-09-19 03:30:01');

        $this->artisan('schedule-runs:prune', ['--pretend' => true])->assertSuccessful();
        $this->assertSame(2, DB::table('schedule_runs')->count());

        $this->artisan('schedule-runs:prune')->assertSuccessful();
        $this->assertSame(['recent'], DB::table('schedule_runs')->pluck('name')->all());
    }

    #[Test]
    public function a_pruned_task_does_not_rerun_its_last_slot()
    {
        config(['schedule-ketchup.prune_after_days' => 90]);
        $yearly = $this->schedule()->call(fn () => null)->yearly()->name('yearly')->catchUp();
        $this->recordRun('schedule:run', '2026-01-01 00:00:00');
        $this->recordRun('yearly', '2026-01-01 00:00:01');
        $this->recordRun('other', '2026-01-01 00:00:02');

        // Prune when the cutoff falls inside the slot's minute, after the task's own run.
        Date::setTestNow('2026-04-01 00:00:02');
        $this->artisan('schedule-runs:prune')->assertSuccessful();
        Date::setTestNow('2026-10-19 03:30:00');

        $this->assertFalse($this->schedule()->dueEvents($this->app)->contains($yearly));
    }
}
