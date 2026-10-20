# laravel-schedule-ketchup

Laravel's scheduler expects `schedule:run` every minute: an event is due only
during the exact minute its cron expression matches. If `schedule:run` is
invoked less often (for example, an hourly Kubernetes CronJob), a `dailyAt('01:15')`
task never runs.

With this package, events that opt in with `->catchUp()` are due whenever their
most recent scheduled time is later than their last recorded start. A task that
missed several slots runs once, not once per slot.

Every scheduled task run is recorded in a `schedule_runs` table with its wall
time, CPU and peak memory, which is also useful for sizing scheduler resources.

## Installation

```sh
composer require clingen-data-model/laravel-schedule-ketchup
php artisan migrate
```

The service provider is auto-discovered. It replaces the application's
`Schedule` with a `CatchUpSchedule` holding the same events, so schedules
defined in a console kernel, `routes/console.php` or `withSchedule()` all work.

## Usage

```php
Schedule::command('hgnc:update-data')->dailyAt('01:15')->catchUp();

Schedule::call(fn () => Report::send())->weekly()->name('weekly-report')->catchUp();

Schedule::job(new PruneUploads)->daily()->catchUp('prune-uploads');
```

Events without `->catchUp()` keep Laravel's exact-minute behaviour. Their runs
are still recorded.

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

### When a catch-up event is due

An event is due if its most recent scheduled time is later than the latest
`started_at` recorded under its name. Runs count from when they start, whether
or not they succeed, so a failing task is not retried at every `schedule:run`.

An event that has never run is measured from the earliest row in
`schedule_runs`, so a new event runs at the first `schedule:run` after one of
its scheduled times has passed. With no rows at all, it uses exact-minute
matching.

## Run records

A row is inserted when a run starts, so in-progress and crashed runs are
visible, and its usage columns are filled in when it finishes.

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

`schedule:run` passes each Artisan task its row ID through Laravel's
[context](https://laravel.com/docs/context), which the scheduler hands to the
command's process.

Recording errors are reported and don't stop tasks from running.

## Notes

- `CatchUpSchedule::isDue()` mirrors the maintenance-mode and environment
  checks of Laravel's `Event::isDue()`; check it when upgrading Laravel.
- Filters (`when()`, `skip()`), overlap prevention and `onOneServer()` still
  apply as usual.
- Sub-minute (`everyTenSeconds()` etc.) events shouldn't use `catchUp()`.
- A `job()` event's run measures dispatching the job, not running it, unless
  the job runs synchronously.

## Development

```sh
podman compose run --rm php install
podman compose run --rm php test
```
