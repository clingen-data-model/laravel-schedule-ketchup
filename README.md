# laravel-schedule-ketchup

Run Laravel scheduled tasks after their scheduled time has passed, even when
the scheduler is invoked infrequently or has been offline.

Laravel's scheduler normally expects `php artisan schedule:run` every minute.
A task is due only during the minute its cron expression matches. An hourly
invocation at the top of the hour never sees `dailyAt('01:15')` as due.

In a container deployment, you may prefer an occasional Kubernetes CronJob
over a continuously running scheduler, or want to avoid starting a scheduler
pod every minute. If your tasks tolerate delayed execution, this package lets
you keep their Laravel schedules while choosing a less frequent invocation
interval. It can also catch up after an interruption to a normally frequent
scheduler.

Add `->catchUp()` to each task that should run when a scheduled time has passed
since its last recorded start. Several missed slots produce one run. Every
scheduled task run is also recorded in a `schedule_runs` table, with available
wall time, CPU and peak memory measurements to help size scheduler resources.

## Example

Suppose the scheduler has been recording history since midnight and you define:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('hgnc:update-data')->dailyAt('01:15')->catchUp();
```

With `schedule:run` invoked hourly:

| Scheduler invocation | What happens |
| --- | --- |
| 01:00 | The 01:15 slot has not arrived; the task waits. |
| 02:00 | The task runs once for the missed 01:15 slot. |
| 03:00 | The task has already started since 01:15; it waits. |
| Next day, 02:00 | The task runs once for the next day's 01:15 slot. |

If the scheduler is offline for several days, the next invocation runs the task
once for the latest missed slot. The package does not invoke it separately for
each missed day or pass a historical date to the command.

This suits tasks such as refreshing a local dataset or cleaning up expired
records, where one run can bring the application up to date. If every missed
period needs its own report or other result, your application must enumerate
and process those periods. Runs count when they start, including failed runs;
provide task or queue retry logic if failures need to be retried.

## Installation

Requirements:

- Laravel 12 with PHP 8.2+, or Laravel 13 with PHP 8.3+.
- An application database connection that the scheduler and scheduled Artisan
  commands can access, with the package's `schedule_runs` migration applied.

```sh
composer require clingen-data-model/laravel-schedule-ketchup
php artisan migrate
```

The service provider and migration are loaded automatically. Existing schedule
definitions in a console kernel, `routes/console.php` or `withSchedule()` work.

## Usage

Define tasks in your application's usual scheduling location. For example, in
`routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('hgnc:update-data')->dailyAt('01:15')->catchUp();

Schedule::call(fn () => Report::send())->weekly()->name('weekly-report')->catchUp();

Schedule::job(new PruneUploads)->daily()->catchUp('prune-uploads');
```

Events without `->catchUp()` keep Laravel's exact-minute behaviour. Their runs
are still recorded.

### Invoke the scheduler

You must still arrange for your deployment to run:

```sh
php artisan schedule:run
```

For example, this system cron entry invokes it at the top of every hour
(replace the application path and use the PHP executable for your deployment):

```cron
0 * * * * cd /path/to/application && php artisan schedule:run
```

A Kubernetes CronJob can use the same hourly schedule, `0 * * * *`, and run
`php artisan schedule:run` in the application container with its database and
other runtime configuration available.

Choose an interval that meets your timing needs. Hourly invocation can delay a
task by almost an hour, plus any time spent waiting for earlier foreground
tasks. Catch-up does not wake the scheduler at the task's original due time.
Tasks without `catchUp()` still require an invocation during their due minute.

### Multiple scheduler instances

Instances that should share catch-up history must use the same database.
History alone does not prevent concurrent execution: two instances can both
check whether a task is due before either records its start. Use Laravel's
`onOneServer()` or `withoutOverlapping()` as appropriate, with the shared cache
and naming those controls require.

## Scheduling behavior

### When a catch-up event is due

An event is due if its most recent scheduled time is later than the latest
`started_at` recorded under its name. An in-progress, crashed or failed run
counts as a start, so a failure is not retried at every `schedule:run`.

An event that has never run is eligible for scheduled times in or after the
minute of the earliest row in `schedule_runs`. This includes a task due during
the first invocation, even though `schedule:run` records its heartbeat before
checking tasks. Earlier slots are not recovered: installing the package at
02:00 does not immediately run a daily 01:15 task. With no rows at all, it uses
exact-minute matching.

Filters (`when()`, `skip()`), maintenance-mode and environment restrictions,
overlap prevention and `onOneServer()` still apply. Event timezones are used
when finding the most recent scheduled time. Sub-minute events such as
`everyTenSeconds()` should not use `catchUp()`.

Recording errors are reported and don't stop tasks from running. If run
history cannot be read, catch-up events fall back to Laravel's exact-minute
matching until history is available again. Environment and maintenance-mode
checks still apply during that fallback.

### Run names

Runs are recorded, and catch-up looks them up, by name:

- an explicit name: `->catchUp('name')`;
- otherwise, for Artisan commands, the command name (arguments ignored);
- otherwise the event's description (`->name(...)` / `->description(...)`);
- otherwise its shell command (`exec()` events).

A catch-up callback with no name or description throws a `LogicException`.

Two events with the same name share their runs. For example,
`command('report daily')` and `command('report weekly')` are both `report`;
give them explicit names if they should catch up independently.

Names longer than 255 characters are stored as a shortened prefix plus a hash
of the full name. Catch-up uses the same representation, so long names with a
shared prefix keep separate histories.

Manual runs of scheduled Artisan commands are recorded under the Artisan
command name. They therefore count toward catch-up for events using that
default name; they do not update history under a different explicit
`catchUp('name')` name.

## Run records

A row is inserted when a run starts, so in-progress and crashed runs are
visible, and its usage columns are filled in when it finishes.

Use the `ScheduleKetchup\ScheduleRun` Eloquent model to inspect runs. For example,
to find the latest completed runs with a nonzero exit code:

```php
use ScheduleKetchup\ScheduleRun;

$failures = ScheduleRun::whereNotNull('exit_code')
    ->where('exit_code', '!=', 0)
    ->orderByDesc('started_at')
    ->limit(20)
    ->get(['name', 'started_at', 'exit_code', 'wall_seconds']);
```

To inspect recent resource measurements for a task:

```php
$recent = ScheduleRun::where('name', 'hgnc:update-data')
    ->orderByDesc('started_at')
    ->limit(20)
    ->get([
        'started_at', 'wall_seconds', 'cpu_user_seconds',
        'cpu_system_seconds', 'max_rss_kb', 'php_peak_bytes',
    ]);
```

Available measurements depend on how the task runs:

| Run | Recorded by | Usage |
| --- | --- | --- |
| Scheduled Artisan command | Its own process, on the row `schedule:run` created for it | Everything |
| Manual run of a scheduled Artisan command (`scheduled` = false) | Its own process | Everything |
| `schedule:run` itself (`scheduled` = false) | Its own process | Everything |
| `call()` / `job()` | `schedule:run` | Wall, CPU and PHP peak memory while the task ran, including any subprocesses' CPU |
| `exec()` in the foreground | `schedule:run` | Wall and the subprocess's CPU |
| `exec()` in the background | `schedule:run` | Start only |

Columns:

- `name`, `command`, `scheduled`, `host`, `started_at`, `exit_code`
- `wall_seconds`
- `cpu_user_seconds`, `cpu_system_seconds`: the run's process
- `max_rss_kb`: the run's process
- `php_peak_bytes`: `memory_get_peak_usage(true)`
- `children_cpu_seconds`, `children_max_rss_kb`: subprocesses the run waited for

Nested `Artisan::call()` commands are counted as part of the outer command.
A queued `job()` event measures dispatching the job, not its eventual execution;
it measures job execution only when the job runs synchronously. A null exit code
or usage field can indicate an unfinished run or an unavailable measurement;
it is not by itself proof of a failure.

### Pruning

`schedule-runs:prune` deletes runs older than `schedule-ketchup.prune_after_days`
(default 90, or `SCHEDULE_RUNS_PRUNE_AFTER_DAYS`). Schedule pruning yourself:

```php
Schedule::command('schedule-runs:prune')->daily()->catchUp();
```

Retention affects catch-up as well as storage. Once all runs for a task have
been pruned, the task is treated as never run and uses the minute of the
earliest remaining row as its baseline. Missed slots before that minute are no
longer eligible. Choose retention to cover the history you need to recover,
especially for infrequent tasks or long scheduler outages.

For example, if an annual task missed January 1 but its last run and all history
from January have been pruned, a scheduler invocation in October will not
recover that January slot.

Publish the config if you want to customize it in your application:

```sh
php artisan vendor:publish --tag=schedule-ketchup-config
```

## Development

```sh
podman compose run --rm php install
podman compose run --rm php test
```

The service provider replaces the application's `Schedule` with a
`CatchUpSchedule` holding the same events and settings.
`CatchUpSchedule::isDue()` mirrors the maintenance-mode and environment checks
of Laravel's `Event::isDue()`; check it when upgrading Laravel.

For scheduled Artisan commands, `schedule:run` passes the run's row ID through
Laravel's context to the command's process, which records its own usage.

`schedule-runs:prune` invokes `model:prune` with the `ScheduleRun` model named
explicitly, because automatic model discovery does not include package models.
