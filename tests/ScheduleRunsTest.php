<?php

namespace ScheduleKetchup\Tests;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ScheduleKetchup\ScheduleRuns;

class ScheduleRunsTest extends TestCase
{
    #[Test]
    public function long_names_match_their_history_without_sharing_a_common_prefix()
    {
        $runs = $this->app->make(ScheduleRuns::class);
        $this->recordRun('heartbeat', '2026-10-18 00:00:00');
        $prefix = str_repeat('x', 255);
        $first = $this->schedule()->call(fn () => null)->dailyAt('01:00')->catchUp($prefix.'first');
        $second = $this->schedule()->call(fn () => null)->dailyAt('01:00')->catchUp($prefix.'second');
        $shell = $this->schedule()->exec('echo '.str_repeat('y', 300))->dailyAt('01:00')->catchUp();

        $runs->start($prefix.'first', null, true);
        $runs->start($shell->command, $shell->command, true);
        $events = $this->schedule()->dueEvents($this->app);

        $this->assertFalse($events->contains($first));
        $this->assertTrue($events->contains($second));
        $this->assertFalse($events->contains($shell));
        $this->assertLessThanOrEqual(255, mb_strlen(DB::table('schedule_runs')->where('id', 2)->value('name')));
    }

    #[Test]
    public function short_names_keep_their_existing_history()
    {
        $this->recordRun('existing', '2026-10-19 01:00:05');
        $runs = $this->app->make(ScheduleRuns::class);

        $this->assertSame('2026-10-19 01:00:05', $runs->lastStartedAt('existing')->format('Y-m-d H:i:s'));
        $id = $runs->start('existing', null, true);
        $this->assertSame('existing', DB::table('schedule_runs')->where('id', $id)->value('name'));
    }
}
