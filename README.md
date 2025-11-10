# yii2-scheduler

High-resolution cron-like job scheduler for Yii2 supporting:
- External cron mode (invoke the scheduler from system cron, typically every minute)
- Daemon mode (single long-running loop with microsecond timing and drift correction)
- Queue integration (yii2-queue) or synchronous execution
- Single-instance locks with max running time and stale lock reclamation
- Atomic distributed locks (cache add) with metadata: `{pid, host, ts}`
- Robust cron pattern parsing: wildcards, ranges, steps, lists

## Installation

1. Require the package (after you publish it to a VCS):

```
composer require ldkafka/yii2-scheduler
```

2. Configure in your Yii2 console app (e.g. `console/config/main.php`):

```php
return [
    'bootstrap' => [
        // ensure the component can bootstrap its controller
        'scheduler',
    ],
    'components' => [
        'cache' => [ /* your cache config */ ],
        'queue_scheduler' => [ /* your yii2-queue config */ ],

        'scheduler' => [
            'class' => ldkafka\scheduler\Scheduler::class,
            'config' => [
                'cache' => 'cache',            // optional; default 'cache' (uses Yii::$app->cache)
                'queue' => 'queue_scheduler',  // optional; omit to run inline synchronously
            ],
            'jobs' => require __DIR__ . '/scheduler.php',
        ],
    ],
];
```

3. Create `console/config/scheduler.php` with your jobs:

```php
<?php

use ldkafka\scheduler\ScheduledJob;

return [
    [
        'class' => \common\jobs\ExampleJob::class, // must extend ScheduledJob
        'run' => 'EVERY_MINUTE',
        'single_instance' => true,        // default true
        'max_running_time' => 300,        // seconds; 0 = unlimited
    ],
    [
        'class' => \common\jobs\NightlyJob::class,
        'run' => [
            'minutes' => 0,
            'hours' => 2,
            'wday' => '1-5',              // Mon-Fri
        ],
    ],
];
```

## Usage

- External cron mode:

```
php yii scheduler/run
```

- Daemon mode (long-running with drift correction):

```
php yii scheduler/daemon
```

## Upgrade notes

### Upgrading from 1.0.3 or earlier

- **Lock format changed**: Locks now store `{pid, host, ts}` instead of bare PID. No migration needed; old locks will expire naturally (1-hour TTL).
- **Config cleanup**: Remove obsolete `staleLockTtl` and `maxJobsPerTick` from your scheduler config (not implemented).
- **Cron parsing improved**: The `day` key is now normalized to `mday`. Update job configs using `day` to use `mday` instead for clarity.
- **Log levels adjusted**: Normal flow messages moved from warning→info. Review log filters if you relied on warning-level job execution logs.

## Release checklist

- Symbolic: `EVERY_MINUTE`, `EVERY_HOUR`, `EVERY_DAY`, `EVERY_WEEK`, `EVERY_MONTH`
- Cron-like array using `getdate()` keys: `minutes`, `hours`, `mday` (day of month), `wday` (weekday), `mon`, `year`
  - Patterns per key:
    - `*` - wildcard (matches any value)
    - `5` - exact match
    - `*/5` - step/interval (every 5th unit: 0, 5, 10...)
    - `1-5` - range (1 through 5 inclusive)
    - `10-20/2` - range with step (10, 12, 14, 16, 18, 20)
    - `1,3,5` - list (matches 1 or 3 or 5)
  - Multiple patterns can be combined with commas for OR semantics

### Writing a job

```php
namespace common\jobs;

use ldkafka\scheduler\ScheduledJob;
use Yii;

class ExampleJob extends ScheduledJob
{
    public function execute($queue = null)
    {
        Yii::info('ExampleJob executed', 'scheduler');
        // do work
        return true; // success
    }
}
```

Notes:
- When using queue mode, jobs are pushed to the configured queue component and executed by a queue worker.
- Locks use metadata format `{pid: int, host: string, ts: int}` and are acquired atomically with `cache->add()`.
- Stale lock reclamation: if the scheduler finds an existing lock with no running jobs in cache, it safely re-reads and deletes the lock before retrying (prevents race conditions).
- Stale jobs exceeding `max_running_time` are auto-removed from the run cache; if the queue driver supports `remove()`, the queued job is also removed.
- `max_running_time` is enforced during scheduler ticks; consider queueing long-running jobs for better concurrency.

## Logging

All logs use the `scheduler` category with production-appropriate levels:
- **info**: Normal operations (job selected, queued, executed, lock acquired/released)
- **warning**: Anomalies worth attention (stale lock removed, job exceeded max time, single-instance conflict, queue check failure)
- **error**: Failures requiring intervention (cache lock acquisition failed, job class not found, queue push failed, critical init errors)

Configure a log target in your `console/config/main.php` if needed:

```php
'log' => [
    'targets' => [
        [
            'class' => 'yii\log\FileTarget',
            'levels' => ['error', 'warning', 'info'],
            'categories' => ['scheduler'],
            'logFile' => '@runtime/logs/scheduler.log',
            'maxFileSize' => 10240, // 10 MB
        ],
    ],
],
```

## Cleanup and safety

- All jobs are executed via a SafeJobWrapper that catches exceptions and prevents worker crashes.
- On completion (sync or async), the wrapper will call `Scheduler::finalizeRuntimeJob()` to remove the job entry from the persistent run cache.

## Compatibility

- Requires PHP >= 8.0, Yii2 ~2.0.14
- Optional `pcntl` for graceful signal handling (SIGINT/SIGTERM). On platforms without `pcntl`, the daemon stops when too many ticks are missed (configurable).

## Release checklist

1. Bump version in `composer.json` (e.g. 1.0.0 -> 1.0.1).
2. Update `CHANGELOG.md` with added/changed/fixed sections.
3. Tag release: `git tag v1.0.0 && git push --tags`.
4. Publish to Packagist (ensure GitHub repo is public / accessible).
5. Verify installation: `composer require ldkafka/yii2-scheduler` in a clean project.
6. (Optional) Set up a queue worker: `php yii queue/listen` and run daemon.

## License

BSD-3-Clause
