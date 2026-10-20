<?php

namespace ScheduleKetchup;

use Illuminate\Console\Command;

/**
 * model:prune only discovers models under app/Models, so a package model has
 * to be named explicitly.
 */
class PruneScheduleRuns extends Command
{
    protected $signature = 'schedule-runs:prune
                            {--chunk=1000 : The number of runs to delete per query}
                            {--pretend : Display the number of runs that would be pruned}';

    protected $description = 'Delete schedule_runs rows older than schedule-ketchup.prune_after_days';

    public function handle(): int
    {
        return $this->call('model:prune', [
            '--model' => [ScheduleRun::class],
            '--chunk' => $this->option('chunk'),
            '--pretend' => $this->option('pretend'),
        ]);
    }
}
