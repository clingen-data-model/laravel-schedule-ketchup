<?php

namespace ScheduleKetchup\Tests;

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
}
