# yii2-scheduler

High-resolution cron-like job scheduler for Yii2 supporting:
- External cron mode (run the scheduler once per second via system cron)
- Daemon mode (single long-running loop with microsecond timing and drift correction)
- Queue integration (yii2-queue) or synchronous execution
- Single-instance locks with max running time and stale lock handling
 - Atomic distributed locks (cache add) with host/pid metadata and status introspection

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
                'queue' => 'queue_scheduler', // optional; omit to run inline
                // cache now always resolved from Yii::$app->cache internally
                'staleLockTtl' => 600,         // optional; seconds before orphan lock auto-clean
                'maxJobsPerTick' => 5,         // optional; limit jobs processed per tick
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

- External cron mode (invoke once per second):

```
php yii scheduler/run
```

- Daemon mode (long-running with drift correction and heartbeat):
# Status / introspection

Show active locks and heartbeat:

```
php yii scheduler/status
```

Output includes: lock payload (timestamp, pid, host, optional jid when queued) and run pattern.

### Configuration knobs

| Key | Purpose |
|-----|---------|
| `staleLockTtl` | Remove lock if older than TTL and no queue job id (helps recover from crashes). |
| `maxJobsPerTick` | Limits number of jobs enqueued/executed each tick to prevent queue flooding. |
| ENV `SCHEDULER_TICK_INTERVAL` | Daemon tick interval seconds (default 1). |
| ENV `SCHEDULER_MAX_MISSED` | Consecutive missed ticks before daemon exits (default 5). |
| ENV `SCHEDULER_HEARTBEAT_TTL` | Cache TTL for heartbeat key (default 10). |

Heartbeat cache key: `scheduler_daemon_heartbeat` with fields `{ ts, pid, ticks }`.


```
# Optional env
# SCHEDULER_TICK_INTERVAL=1 SCHEDULER_MAX_MISSED=5 SCHEDULER_HEARTBEAT_TTL=10 \
php yii scheduler/daemon
```

### Supported `run` specs

- Symbolic: `EVERY_MINUTE`, `EVERY_HOUR`, `EVERY_DAY`, `EVERY_WEEK`, `EVERY_MONTH`
- Cron-like array using `getdate()` keys: `minutes`, `hours`, `mday`, `wday`, `mon`, `year`
  - Patterns per key: `*`, exact number (e.g. `5`), step `*/n`, range `a-b`, list `a,b,c`

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
- When using queue mode, jobs are pushed to `queue_scheduler` and executed by a queue worker.
- Locks are acquired atomically using cache->add; payload: `{timestamp, pid, host, jid}`.
- Stale locks are auto-removed based on `staleLockTtl` and absence of `jid`.
- `max_running_time` (per job) triggers warning and attempted lock kill (queue deletion or SIGKILL if pid differs and posix extension available).

## Logging

All logs use the `scheduler` category. Configure a target in your `console/config/main.php` if needed.

## Heartbeat & supervision

In daemon mode the `scheduler_daemon_heartbeat` key is written every tick. Supervisors should validate its `ts` freshness (< heartbeat TTL) and optionally compare `pid` stability. A rising `ticks` counter confirms progress.

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
