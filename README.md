# laravel-schedule-ketchup

Laravel's scheduler expects `schedule:run` every minute: an event is due only
during the exact minute its cron expression matches. If `schedule:run` is
invoked less often (for example, an hourly Kubernetes CronJob), a `dailyAt('01:15')`
task never runs.

With this package, events that opt in with `->catchUp()` are due whenever their
most recent scheduled time is later than their last recorded start. A task that
missed several slots runs once, not once per slot.

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

Events without `->catchUp()` keep Laravel's exact-minute behaviour.

### Run names

Starts are recorded per name:

- an explicit name: `->catchUp('name')`;
- otherwise, for Artisan commands, the command name (arguments ignored);
- otherwise the event's description (`->name(...)` / `->description(...)`);
- otherwise its shell command (`exec()` events).

A callback or job with no name or description throws a `LogicException`.

Two events with the same name share one record. For example,
`command('report daily')` and `command('report weekly')` are both `report`;
give them explicit names if they should catch up independently.

### What counts as a run

- Every scheduled start of a catch-up event (`ScheduledTaskStarting`).
- Every start of an Artisan command whose name matches a catch-up event,
  including manual runs and nested `Artisan::call()`, so a task run by hand is
  not repeated at the next `schedule:run`.

Starts are recorded whether or not the run succeeds, so a failing task is not
retried every time `schedule:run` is invoked.

### New events

The first time a catch-up event is checked, the package starts tracking it and
uses exact-minute matching for that check. After that, it is due at its first
scheduled time after tracking began.

## Custom run storage

Runs are stored in the `schedule_catchup_runs` table. To use run records the
application already keeps, bind your own `ScheduleKetchup\Contracts\RunRepository`:

```php
$this->app->singleton(RunRepository::class, MyRunRepository::class);
```

`lastStartedAt()` returning `null` makes the event fall back to exact-minute
matching. `recordStart()` may be a no-op if the application records starts
itself.

## Notes

- `CatchUpSchedule::isDue()` mirrors the maintenance-mode and environment
  checks of Laravel's `Event::isDue()`; check it when upgrading Laravel.
- Filters (`when()`, `skip()`), overlap prevention and `onOneServer()` still
  apply as usual.
- Sub-minute (`everyTenSeconds()` etc.) events shouldn't use `catchUp()`.

## Development

```sh
podman compose run --rm php install
podman compose run --rm php test
```
