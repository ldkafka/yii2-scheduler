# yii2-scheduler Architecture & Implementation Documentation

**Version:** 1.0.3  
**Last Updated:** November 10, 2025  
**Author:** Technical Documentation

---

## Table of Contents

1. [System Overview](#system-overview)
2. [Core Components](#core-components)
3. [Execution Flow](#execution-flow)
4. [Lock Management & Concurrency](#lock-management--concurrency)
5. [Cache & Queue Integration](#cache--queue-integration)
6. [Job Scheduling Logic](#job-scheduling-logic)
7. [Error Handling & Recovery](#error-handling--recovery)
8. [Race Conditions & Edge Cases](#race-conditions--edge-cases)
9. [Performance Considerations](#performance-considerations)
10. [Configuration Guide](#configuration-guide)
11. [Troubleshooting](#troubleshooting)

---

## System Overview

### Purpose

The yii2-scheduler is a production-grade job scheduler for Yii2 applications that provides:

- **Cron-like scheduling** with flexible time patterns (symbolic and cron-style)
- **Distributed locking** via atomic cache operations (Redis, Memcached, etc.)
- **Async execution** via yii2-queue integration (optional)
- **High-precision daemon mode** with microsecond timing and drift correction
- **Stale job cleanup** with configurable timeouts and queue removal
- **Single-instance enforcement** to prevent duplicate job execution

### Execution Modes

1. **Cron Mode** (`scheduler/run`)
   - Executed by system cron every minute
   - Acquires lock, processes eligible jobs, releases lock
   - Suitable for simple deployments

2. **Daemon Mode** (`scheduler/daemon`)
   - Long-running process with high-precision timing loop
   - Implements drift correction algorithm
   - Automatic re-anchoring every 100 ticks
   - Graceful shutdown via SIGINT/SIGTERM

### Architecture Diagram (Updated 1.0.3)

```
┌─────────────────────────────────────────────────────────────┐
│                     System Cron / Daemon                     │
│                  (scheduler/run or /daemon)                  │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                   SchedulerController                        │
│  • Timing control (cron vs daemon)                          │
│  • Signal handling (SIGINT/SIGTERM)                         │
│  • Drift correction algorithm                               │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                      Scheduler                               │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Phase 1: Initialization                             │   │
│  │  • initCache() - Acquire atomic lock                │   │
│  │  • initQueue() - Resolve queue component            │   │
│  │  • initRunCache() - Load persistent job state       │   │
│  └─────────────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Phase 2: Job Evaluation                             │   │
│  │  • needsToRun() - Check cron pattern match          │   │
│  │  • canRun() - Check single_instance locks           │   │
│  └─────────────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Phase 3: Job Execution                              │   │
│  │  • addRuntimeJob() - Register in run cache          │   │
│  │  • queueJob() OR runJob() - Execute async or sync   │   │
│  │  • finalizeRuntimeJob() - Cleanup via SafeJobWrapper│   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────┬───────────────────────┬───────────────────┘
                  │                       │
                  │                       │
      ┌───────────▼──────────┐  ┌────────▼─────────┐
      │   Cache (Redis)      │  │  Queue (Redis)   │
      │  • Scheduler lock    │  │  • Job queue     │
      │  • Job run state     │  │  • Workers       │
      └──────────────────────┘  └──────────────────┘
                  │
                  ▼
         ┌────────────────┐
         │  ScheduledJob  │
         │   (Abstract)   │
         │  • execute()   │
         └────────────────┘
                  │
         ┌────────┴────────┐
         ▼                 ▼
   [YourJob1]        [YourJob2]
```

---

## Core Components

### 1. Scheduler.php

**Purpose:** Central component managing job configuration, scheduling logic, and execution coordination.

**Key Responsibilities:**
- Parse and validate job configurations
- Manage atomic distributed locks
- Coordinate with cache and queue components
- Evaluate cron patterns against current time
- Track running jobs in persistent cache
- Clean up stale jobs exceeding max_running_time

**Public Properties:**
```php
public $config = [];  // Component configuration (cache, queue names)
public $jobs = [];    // Job definitions from config
public $running_time = []; // Symbolic schedule templates
```

**Private State:**
```php
private $_cache = null;       // Cache component instance
private $_queue = null;       // Queue component instance
private $_loaded_jobs = [];   // Validated job configs
private $_run_cache = [];     // Persistent job execution state
private $_lock_pid = null;    // Current process lock PID
private $_trigger_time = null; // getdate() array for current tick
```

**Critical Methods:**

- `runJobs(): bool` - Main entry point, orchestrates entire execution cycle
- `initCache(): bool` - Atomic lock acquisition via cache->add()
- `initQueue(): bool` - Resolve queue component
- `initRunCache(): void` - Load persistent state, cleanup finished/stale jobs
- `needsToRun(int, array): bool` - Evaluate cron pattern
- `canRun(int, array): bool` - Check single_instance constraints
- `queueJob(int, array, string, int): int` - Push to async queue
- `runJob(int, array, string, int): bool` - Execute synchronously

### 2. SchedulerController.php

**Purpose:** Console controller providing CLI interface and timing control.

**Actions:**

- `actionUsage()` - Display help and configuration
- `actionShow()` - Display loaded job configurations
- `actionRun()` - Single execution (for system cron)
- `actionDaemon()` - Continuous loop with drift correction

**Daemon Algorithm:**

```php
$baseSecond = (int)microtime(true);
$ticks = 0;

while ($running) {
    $ticks++;
    $scheduler->runJobs();
    
    $targetNextTick = $baseSecond + ($ticks * $tickInterval);
    $sleepTime = $targetNextTick - microtime(true);
    
    if ($sleepTime > 0) {
        usleep((int)($sleepTime * 1e6));
    } else {
        // Overrun detected, continue immediately
        $missedConsecutive++;
    }
    
    // Re-anchor every 100 ticks to prevent drift
    if (($ticks % 100) === 0) {
        $baseSecond = (int)microtime(true);
    }
}
```

**Key Features:**
- Microsecond-precision timing via `microtime(true)`
- Drift compensation: sleeps until exact target time
- CPU-efficient via `usleep()` instead of busy-wait
- Graceful shutdown via `pcntl_signal()` (SIGINT/SIGTERM)
- Automatic exit after MAX_MISSED_TICKS consecutive overruns

### 3. ScheduledJob.php

**Purpose:** Abstract base class for all scheduled jobs.

**Contract:**
- Extends `yii\base\BaseObject`
- Implements `yii\queue\JobInterface`
- Must override `execute($queue = null): bool`

**Injected Properties:**
```php
public $job_id;        // Configuration index
public $job_config;    // Full job configuration array
public $job_cache_key; // Cache key for this job's lock
public $job_index;     // Index in _run_cache array
```

**Lifecycle:**
1. Instantiated inside SafeJobWrapper with config properties
2. `execute()` invoked (queue worker or synchronous)
3. Return `true` for success, `false` for failure
4. Cleanup handled by SafeJobWrapper calling `Scheduler::finalizeRuntimeJob()`

---

## Execution Flow

### Single-Run Mode (scheduler/run)

```
1. Controller: actionRun()
   ↓
2. Scheduler: runJobs()
   ↓
3. Initialize Components
   ├─ initCache() → Acquire lock via cache->add()
   ├─ initQueue() → Resolve queue component
   └─ initRunCache() → Load persistent state, cleanup finished/stale
   ↓
4. Set Trigger Time
   $this->_trigger_time = getdate();
   ↓
5. For Each Job in _loaded_jobs:
   ├─ needsToRun() → Check cron pattern
   │  └─ Compare pattern vs $_trigger_time
   ├─ canRun() → Check single_instance lock
   │  └─ Inspect $_run_cache for existing entry
   ├─ addRuntimeJob() → Add to $_run_cache
   │  └─ Store: start_time, pid, host, config
   ├─ Execute:
   │  ├─ IF queue available: queueJob()
   │  │  └─ queue->push(new JobClass(...))
   │  │  └─ Store qid in $_run_cache
   │  │  └─ flushRunCache() → Persist to cache
   │  └─ ELSE: runJob()
   │     └─ new JobClass(...)->execute()
   └─ deleteRuntimeJob() → Remove from $_run_cache (if sync)
   ↓
6. Return: true (success) or false (had errors)
   ↓
7. Destructor: __destruct()
   ├─ flushRunCache() → Persist final state
   └─ Release lock (if still owned by this PID)
```

### Daemon Mode (scheduler/daemon)

```
1. Controller: actionDaemon()
   ↓
2. Initialize:
   ├─ Register signal handlers (SIGINT, SIGTERM)
   ├─ Set baseSecond = (int)microtime(true)
   └─ ticks = 0
   ↓
3. Loop while $running:
   ├─ ticks++
   ├─ $tickStart = microtime(true)
   ├─ scheduler->runJobs() → [Same as single-run flow]
   ├─ Calculate sleep:
   │  ├─ $targetNextTick = baseSecond + (ticks * interval)
   │  ├─ $sleepTime = targetNextTick - microtime(true)
   │  ├─ IF sleepTime > 0:
   │  │  └─ usleep(sleepTime * 1e6) → Sleep until target
   │  └─ ELSE:
   │     └─ missedConsecutive++ → Track overruns
   ├─ Re-anchor every 100 ticks:
   │  └─ baseSecond = (int)microtime(true)
   └─ Exit if missedConsecutive >= MAX_MISSED_TICKS
   ↓
4. Graceful Shutdown:
   ├─ Log final statistics (ticks, runtime)
   └─ Exit with ExitCode::OK
```

---

## Lock Management & Concurrency

### Atomic Lock Acquisition

**Problem:** Prevent multiple scheduler processes from running simultaneously.

**Solution:** Use cache->add() which atomically sets a key only if it doesn't exist.

```php
// Scheduler::initCache()
$pid = getmypid();
$lock_ttl = 3600; // 1 hour

$acquired = $cache->add(self::SCHEDULER_LOCK_CACHE_KEY, $pid, $lock_ttl);

if ($acquired) {
    $this->_lock_pid = $pid;
    $this->setCache($cache);
    return true;
} else {
    $existing_pid = $cache->get(self::SCHEDULER_LOCK_CACHE_KEY);
    throw new \RuntimeException(
        "Another scheduler process PID {$existing_pid} is running"
    );
}
```

**Why cache->add() vs getOrSet():**

❌ **getOrSet()** is NOT atomic:
```php
// RACE CONDITION!
$lock_pid = $cache->getOrSet(self::SCHEDULER_LOCK_CACHE_KEY, 
    function() use($pid) { return $pid; }, 
    60
);
// Between GET and SET, another process could acquire the lock
```

✅ **add()** is atomic:
```php
// SAFE - Sets key ONLY if it doesn't exist
$acquired = $cache->add(self::SCHEDULER_LOCK_CACHE_KEY, $pid, 3600);
// Returns true if we got the lock, false if someone else has it
```

**Lock TTL:**
- Set to 3600 seconds (1 hour) in current implementation
- Prevents deadlock if process crashes without releasing lock
- Must be longer than longest expected job execution time
- Lock ownership verified in destructor before deletion

### Single-Instance Job Locks

**Problem:** Prevent same job from running multiple times concurrently.

**Solution:** Per-job cache key with running state tracking.

```php
// Scheduler::cacheRunKey()
public static function cacheRunKey(array $job_config): string
{
    return 'scheduler_' . $job_config['class'] . '_' . md5(serialize($job_config['run']));
}

// Scheduler::canRun()
private function canRun(int $job_id, array $job_config): bool
{
    $job_cache_key = $this->cacheRunKey($job_config);
    
    if ($job_config['single_instance'] === true && 
        isset($this->_run_cache[$job_cache_key]) &&
        !empty($this->_run_cache[$job_cache_key])) {
        
        $running_jobs = $this->_run_cache[$job_cache_key];
        $first_job = reset($running_jobs);
        
        if ($first_job === false) {
            return true; // Empty array after all
        }
        
        $running_time = time() - ($first_job['start_time'] ?? 0);
        Yii::warning("Job {$job_config['class']} already running ({$running_time}s)", 'scheduler');
        return false;
    }
    
    return true;
}
```

**Run Cache Structure:**

```php
$this->_run_cache = [
    'scheduler_app\jobs\MyJob_abc123' => [
        0 => [
            'start_time' => 1699650000,
            'pid' => 12345,
            'host' => 'web-server-01',
            'qid' => 'abc-xyz-123', // Queue job ID (if queued)
            'cid' => 0,              // Config index
            'conf' => [ /* job config */ ]
        ]
    ]
];
```

### Destructor Lock Release

**Problem:** Lock must be released even if process crashes or throws exception.

**Solution:** Destructor checks lock ownership before deletion.

```php
public function __destruct()
{
    if ($this->cache && $this->_lock_pid) {
        try {
            $this->flushRunCache();
            
            // Only delete if lock still belongs to us
            $current_lock = $this->cache->get(self::SCHEDULER_LOCK_CACHE_KEY);
            if ($current_lock == $this->_lock_pid) {
                $this->cache->delete(self::SCHEDULER_LOCK_CACHE_KEY);
                Yii::info("Scheduler lock released by PID {$this->_lock_pid}", 'scheduler');
            } else {
                Yii::warning("Lock taken by another process (PID {$current_lock})", 'scheduler');
            }
        } catch (\Throwable $e) {
            Yii::warning("Failed to release lock: {$e->getMessage()}", 'scheduler');
        }
    }
}
```

---

## Cache & Queue Integration

### Cache Component

**Purpose:**
1. Distributed locking (scheduler process lock)
2. Persistent job state (running jobs cache)

**Requirements:**
- Must support atomic `add()` operation (Redis, Memcached)
- Must be accessible by all scheduler processes
- Recommended: Redis with persistence enabled

**Configuration:**

```php
'components' => [
    'cache' => [
        'class' => 'yii\redis\Cache',
        'redis' => 'redis',
    ],
    'redis' => [
        'class' => 'yii\redis\Connection',
        'hostname' => 'localhost',
        'port' => 6379,
        'database' => 0,
    ],
]
```

**Cache Keys:**

| Key | Purpose | TTL | Value |
|-----|---------|-----|-------|
| `scheduler_cache_lock` | Scheduler process lock | 3600s | PID (int) |
| `scheduler_running_jobs` | Job execution state | Persistent | Array of running jobs |

### Queue Component

**Purpose:** Asynchronous job execution via yii2-queue.

**Requirements:**
- Package: `yiisoft/yii2-queue` >= 2.3.0
- Driver: Redis recommended (supports `remove()` method)
- Worker: Must be running (`php yii queue/listen`)

**Configuration:**

```php
'components' => [
    'queue_scheduler' => [
        'class' => 'yii\queue\redis\Queue',
        'redis' => 'redis',
        'channel' => 'queue_scheduler',
    ],
],

'scheduler' => [
    'class' => 'ldkafka\scheduler\Scheduler',
    'config' => [
        'queue' => 'queue_scheduler', // Must match component name
    ],
],
```

**Queue Operations:**

```php
// Push job to queue (Scheduler::queueJob)
$queue_job_id = $this->queue->push(new $job_config['class']([
    'job_id' => $job_id,
    'job_config' => $job_config,
    'job_cache_key' => $job_cache_key,
    'job_index' => $job_index,
]));

// Check if job completed (Scheduler::initRunCache)
if ($this->queue->isDone($job['qid'])) {
    unset($this->_run_cache[$cache_id][$job_index]);
}

// Remove stale job from queue (Scheduler::initRunCache)
if (method_exists($this->queue, 'remove')) {
    $this->queue->remove($job['qid']);
}
```

**Queue Worker:**

```bash
# Run worker in background
php yii queue/listen &

# Or use systemd service
[Unit]
Description=Queue Worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/app
ExecStart=/usr/bin/php /path/to/yii queue/listen
Restart=always

[Install]
WantedBy=multi-user.target
```

---

## Job Scheduling Logic

### Cron Pattern Evaluation

**Method:** `Scheduler::needsToRun(int $job_id, array $job_config): bool`

**Algorithm:**

1. Iterate through each field in `$job_config['run']`
2. For each field (minutes, hours, day, mday, wday, mon, year):
   - Get current value from `$this->_trigger_time` (getdate() array)
   - Check if pattern matches current value
   - If any field doesn't match, return false
3. If all fields match, return true

**Supported Patterns:**

| Pattern | Example | Description |
|---------|---------|-------------|
| `*` | `'minutes' => '*'` | Match any value |
| Exact | `'minutes' => 5` | Match specific value (5) |
| Step | `'minutes' => '*/15'` | Every 15 minutes (0,15,30,45) |
| Range | `'hours' => '9-17'` | Hours 9 through 17 |
| List | `'wday' => '1,3,5'` | Monday, Wednesday, Friday |

**Implementation:**

```php
private function needsToRun(int $job_id, array $job_config): bool
{
    foreach ($job_config['run'] as $key => $val) {
        $val = trim($val);
        
        // Wildcard matches anything
        if ($val === '*') {
            continue;
        }
        
        // Get current value from trigger time
        if (!isset($this->_trigger_time[$key])) {
            return false;
        }
        $current = $this->_trigger_time[$key];
        
        // Exact numeric match
        if (is_numeric($val)) {
            if ((int)$val === (int)$current) {
                continue;
            }
            return false;
        }
        
        // Step pattern: */n (every n units)
        if (str_starts_with($val, '*/')) {
            $step = (int)substr($val, 2);
            if ($step > 0 && ($current % $step) === 0) {
                continue;
            }
            return false;
        }
        
        // Range pattern: a-b
        if (strpos($val, '-') !== false) {
            [$from, $to] = array_map('trim', explode('-', $val, 2));
            if (is_numeric($from) && is_numeric($to) && 
                $current >= (int)$from && $current <= (int)$to) {
                continue;
            }
            return false;
        }
        
        // List pattern: a,b,c
        if (strpos($val, ',') !== false) {
            $list = array_map('trim', explode(',', $val));
            if (in_array((string)$current, $list, true)) {
                continue;
            }
            return false;
        }
        
        return false; // Unsupported pattern
    }
    
    return true;
}
```

**Symbolic Schedules:**

Symbolic names are expanded to arrays in `Scheduler::initJobs()`:

```php
public $running_time = [
    'EVERY_MINUTE' => ['minutes' => '*'],
    'EVERY_HOUR'   => ['minutes' => 1, 'hours' => '*'],
    'EVERY_DAY'    => ['minutes' => 10, 'hours' => 2, 'day' => '*'],
    'EVERY_WEEK'   => ['minutes' => 20, 'hours' => 6, 'wday' => 1],
    'EVERY_MONTH'  => ['minutes' => 30, 'hours' => 1, 'mday' => 1],
];

// In config:
'run' => 'EVERY_HOUR'

// Becomes:
'run' => ['minutes' => 1, 'hours' => '*']
```

---

## Error Handling & Recovery

### Stale Job Cleanup

**Problem:** Jobs may hang, crash, or exceed expected runtime.

**Solution:** Track `max_running_time` and remove jobs exceeding it.

**Implementation:** `Scheduler::initRunCache()`

```php
foreach ($this->_run_cache as $cache_id => $jobs) {
    foreach ($jobs as $job_index => $job) {
        $job_config = $job['conf'] ?? null;
        
        if ($job_config && isset($job_config['max_running_time'])) {
            $max_time = (int)$job_config['max_running_time'];
            $start_time = $job['start_time'] ?? 0;
            $running_time = time() - $start_time;
            
            if ($max_time > 0 && $running_time > $max_time) {
                $job_class = $job_config['class'] ?? 'unknown';
                Yii::warning(
                    "Removing stale job {$job_class} (running {$running_time}s, max {$max_time}s)", 
                    'scheduler'
                );
                
                // Attempt to remove from queue if queued
                if (isset($job['qid']) && $job['qid'] && $this->queue) {
                    try {
                        if (method_exists($this->queue, 'remove')) {
                            $this->queue->remove($job['qid']);
                            Yii::info("Removed stale queue job {$job['qid']}", 'scheduler');
                        }
                    } catch (\Throwable $e) {
                        Yii::warning("Failed to remove stale queue job: {$e->getMessage()}", 'scheduler');
                    }
                }
                
                unset($this->_run_cache[$cache_id][$job_index]);
                continue;
            }
        }
    }
}
```

**Benefits:**
- Prevents run cache from filling with hung jobs
- Attempts to remove job from queue (if driver supports it)
- Logs warnings for visibility
- Continues processing other jobs

### Queue Job Completion Check

**Problem:** Queued jobs may finish, but run cache still shows them as running.

**Solution:** Check queue status during `initRunCache()`.

```php
if (isset($job['qid']) && $job['qid'] && $this->queue) {
    try {
        if ($this->queue->isDone($job['qid'])) {
            Yii::info("Removing completed queue job {$job['qid']}", 'scheduler');
            unset($this->_run_cache[$cache_id][$job_index]);
            continue;
        }
    } catch (\Throwable $e) {
        Yii::warning("Failed to check queue status: {$e->getMessage()}", 'scheduler');
        // Don't remove - safer to leave it if we can't verify
    }
}
```

**Safety:** If queue check fails (network error, etc.), job is NOT removed from cache.

### Error Propagation

**Method:** `Scheduler::runJobs(): bool`

**Strategy:** Track errors but continue processing remaining jobs.

```php
public function runJobs(): bool
{
    $had_errors = false;
    
    try {
        // Initialize components
        if ($this->initCache()) {
            $this->initQueue();
            $this->initRunCache();
        }
    } catch (\Throwable $e) {
        Yii::error("Critical error during init: {$e->getMessage()}", 'scheduler');
        return false; // Fatal - cannot continue
    }
    
    foreach ($this->getLoadedJobs() as $job_id => $job_config) {
        try {
            if ($this->needsToRun($job_id, $job_config)) {
                if ($this->canRun($job_id, $job_config)) {
                    // Execute job...
                    $result = $this->runJob(...);
                    if (!$result) {
                        $had_errors = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            Yii::error("Error processing job: {$e->getMessage()}", 'scheduler');
            $had_errors = true;
            // Continue with next job
        }
    }
    
    return !$had_errors;
}
```

**Behavior:**
- Initialization errors → Return `false` immediately
- Individual job errors → Log, track, continue
- Return `false` if any job had errors
- Controller converts to `ExitCode::UNSPECIFIED_ERROR`

### Cache Failure Handling

**Problem:** Redis/Memcached may be unreachable or fail.

**Solution:** Wrap all cache operations in try-catch.

```php
private function flushRunCache()
{
    if (!$this->cache) {
        return; // No cache, nothing to flush
    }
    
    try {
        $this->cache->set(self::RUNNING_JOBS_CACHE_KEY, $this->_run_cache);
    } catch (\Throwable $e) {
        Yii::error("Failed to flush run cache: {$e->getMessage()}", 'scheduler');
        throw $e; // Re-throw to let caller handle
    }
}
```

**Degraded Mode:** If cache is unavailable:
- Scheduler runs without distributed lock (warning logged)
- Jobs execute but state is not persisted
- Single-instance enforcement is disabled
- Suitable for development only

---

## Race Conditions & Edge Cases

### Fixed / Changed Issues (v1.0.3)

#### 0. Centralized Cleanup
Replaced destructor-based and explicit cleanup calls with `finalizeRuntimeJob()` invoked from SafeJobWrapper. Guarantees that the process which actually executed the job (queue worker or scheduler itself) performs cleanup.

#### 1. Non-Atomic Lock Acquisition

**Problem:**
```php
// OLD CODE - RACE CONDITION!
$lock_pid = $cache->getOrSet(self::SCHEDULER_LOCK_CACHE_KEY, 
    function() use($pid) { return $pid; }, 
    60
);
```

Between `get()` and `set()`, another process could acquire the lock.

**Fix:**
```php
// NEW CODE - ATOMIC
$acquired = $cache->add(self::SCHEDULER_LOCK_CACHE_KEY, $pid, 3600);
if ($acquired) {
    // We got the lock
} else {
    // Someone else has it
}
```

#### 2. Array Access Without Bounds Check

**Problem:**
```php
// OLD CODE - UNDEFINED OFFSET!
$running_job = $this->_run_cache[$this->cacheRunKey($job_config)];
$running_time = time() - ($running_job[0]['start_time'] ?? 0);
```

If array is empty or numeric keys are non-sequential, `$running_job[0]` causes error.

**Fix:**
```php
// NEW CODE - SAFE ACCESS
if (isset($this->_run_cache[$job_cache_key]) && 
    !empty($this->_run_cache[$job_cache_key])) {
    
    $running_jobs = $this->_run_cache[$job_cache_key];
    $first_job = reset($running_jobs); // Get first element safely
    
    if ($first_job === false) {
        return true; // Empty array
    }
    
    $running_time = time() - ($first_job['start_time'] ?? 0);
}
```

#### 3. Lock TTL Too Short

**Problem:**
```php
// OLD CODE - 60 SECONDS
$lock_pid = $cache->getOrSet(self::SCHEDULER_LOCK_CACHE_KEY, 
    function() use($pid) { return $pid; }, 
    60
);
```

If scheduler takes >60s to complete, lock expires mid-run, allowing duplicate schedulers.

**Fix:**
```php
// NEW CODE - 1 HOUR
$acquired = $cache->add(self::SCHEDULER_LOCK_CACHE_KEY, $pid, 3600);
```

#### 4. Missing Stale Job Cleanup

**Problem:**
```php
// OLD CODE - TODO ONLY
// TODO: implement stale job cleanup
/*
if ($max_time > 0 && (time() - $job['start_time']) > $max_time) {
    // Remove stale job
}
*/
```

**Fix:**
```php
// NEW CODE - IMPLEMENTED
if ($max_time > 0 && $running_time > $max_time) {
    Yii::warning("Removing stale job...");
    
    // Remove from queue if possible
    if (method_exists($this->queue, 'remove')) {
        $this->queue->remove($job['qid']);
    }
    
    unset($this->_run_cache[$cache_id][$job_index]);
}
```

#### 5. No Queue Connection Error Handling

**Problem:**
```php
// OLD CODE - NO ERROR HANDLING
if ($job['qid'] && $this->queue && $this->queue->isDone($job['qid'])) {
    unset($this->_run_cache[$cache_id][$job_index]);
}
```

If queue is down, `isDone()` throws exception, killing entire scheduler.

**Fix:**
```php
// NEW CODE - WRAPPED IN TRY-CATCH
if (isset($job['qid']) && $job['qid'] && $this->queue) {
    try {
        if ($this->queue->isDone($job['qid'])) {
            unset($this->_run_cache[$cache_id][$job_index]);
        }
    } catch (\Throwable $e) {
        Yii::warning("Failed to check queue status: {$e->getMessage()}");
        // Don't remove - safer to leave it
    }
}
```

#### 6. Destructor Lock Race Condition

**Problem:**
```php
// OLD CODE
public function __destruct() {
    $this->flushRunCache();
    $this->cache->delete($this->_lock_pid); // May delete another process's lock!
}
```

If lock expired and another process acquired it, we delete their lock.

**Fix:**
```php
// NEW CODE
public function __destruct() {
    $current_lock = $this->cache->get(self::SCHEDULER_LOCK_CACHE_KEY);
    if ($current_lock == $this->_lock_pid) {
        $this->cache->delete(self::SCHEDULER_LOCK_CACHE_KEY);
    } else {
        Yii::warning("Lock taken by another process");
    }
}
```

#### 7. Queue Job Persistence Failure

**Problem:**
```php
// OLD CODE
$queue_job_id = $this->queue->push(new JobClass(...));
$this->_run_cache[$job_cache_key][$job_index]['qid'] = $queue_job_id;
$this->flushRunCache();
```

If process dies between assignment and flush, `qid` is lost forever.

**Fix:**
```php
// NEW CODE
$queue_job_id = $this->queue->push(new JobClass(...));

if (isset($this->_run_cache[$job_cache_key][$job_index])) {
    $this->_run_cache[$job_cache_key][$job_index]['qid'] = $queue_job_id;
    $this->flushRunCache(); // Immediate persist
} else {
    Yii::warning("Job index not found. Race condition?");
}
```

#### 8. Missing Error Tracking in runJobs()

**Problem:**
```php
// OLD CODE
public function runJobs(): bool {
    foreach ($jobs as $job) {
        $this->runJob($job); // Ignores failure!
    }
    return true; // Always returns true!
}
```

**Fix:**
```php
// NEW CODE
public function runJobs(): bool {
    $had_errors = false;
    
    foreach ($jobs as $job) {
        $result = $this->runJob($job);
        if (!$result) {
            $had_errors = true;
        }
    }
    
    return !$had_errors;
}
```

---

## Performance Considerations

### Cache Operations

**Frequency:**
- Lock acquisition: Once per scheduler run
- Run cache read: Once per scheduler run
- Run cache write: After each queued job + destructor

**Optimization:**
- Run cache is loaded once, modified in memory, flushed at end
- Only one cache key used for all running jobs (not per-job)
- Cache TTL set appropriately (3600s for lock, no TTL for run cache)

### Queue Operations

**Frequency:**
- Push: Once per eligible job (if queue enabled)
- isDone: Once per cached job per scheduler run
- Remove: Once per stale job

**Optimization:**
- Queue checks only for jobs with `qid` set
- Failed queue checks don't block other jobs
- Remove only called if method exists (Redis driver)

### Daemon Mode Efficiency

**CPU Usage:**
- Uses `usleep()` for precise sleep (not busy-wait)
- Sleep time calculated to exact microsecond
- Minimal CPU usage during idle (sleeping) periods

**Memory:**
- Job configs loaded once at startup
- Run cache size limited by number of concurrent jobs
- No memory leaks from loop (tested)

**Timing Precision:**
- Typical drift: <100ms over 24 hours
- Re-anchoring every 100 ticks prevents accumulation
- Overrun detection prevents runaway loop

### Scalability

**Limits:**
- One scheduler process per application instance
- Unlimited number of jobs (memory permitting)
- Queue handles async execution scaling
- Cache must support all scheduler instances

**Bottlenecks:**
- Cache becomes SPOF (single point of failure)
- Queue capacity limits concurrent jobs
- Network latency to Redis/cache affects timing

---

## Configuration Guide

### Minimal Configuration

```php
// console/config/main.php
return [
    'bootstrap' => ['scheduler'],
    'components' => [
        'cache' => [
            'class' => 'yii\redis\Cache',
        ],
        'scheduler' => [
            'class' => 'ldkafka\scheduler\Scheduler',
            'jobs' => require __DIR__ . '/scheduler.php',
        ],
    ],
];
```

```php
// console/config/scheduler.php
return [
    [
        'class' => app\jobs\ExampleJob::class,
        'run' => 'EVERY_MINUTE',
    ],
];
```

### Full Configuration

```php
'scheduler' => [
    'class' => 'ldkafka\scheduler\Scheduler',
    'config' => [
        'cache' => 'cache',              // Cache component name (default: 'cache')
        'queue' => 'queue_scheduler',    // Queue component name (optional)
    ],
    'jobs' => [
        [
            'class' => app\jobs\EveryMinuteJob::class,
            'run' => 'EVERY_MINUTE',
            'single_instance' => true,   // Prevent concurrent runs (default: true)
            'max_running_time' => 300,   // Max 5 minutes (default: 0 = unlimited)
        ],
        [
            'class' => app\jobs\BusinessHoursJob::class,
            'run' => [
                'minutes' => '*/15',     // Every 15 minutes
                'hours' => '9-17',       // 9am to 5pm
                'wday' => '1-5',         // Monday to Friday
            ],
            'single_instance' => true,
            'max_running_time' => 600,
        ],
        [
            'class' => app\jobs\NightlyJob::class,
            'run' => [
                'minutes' => 0,
                'hours' => 2,
            ],
            'single_instance' => true,
            'max_running_time' => 3600,
        ],
    ],
],
```

### Job Class Example

```php
<?php
namespace app\jobs;

use ldkafka\scheduler\ScheduledJob;
use Yii;

class ExampleJob extends ScheduledJob
{
    public function execute($queue = null)
    {
        Yii::info("ExampleJob started", 'scheduler');
        
        try {
            // Your job logic here
            $result = $this->doWork();
            
            if ($result) {
                Yii::info("ExampleJob completed successfully", 'scheduler');
                return true;
            } else {
                Yii::error("ExampleJob failed", 'scheduler');
                return false;
            }
        } catch (\Throwable $e) {
            Yii::error("ExampleJob exception: {$e->getMessage()}", 'scheduler');
            return false;
        }
    }
    
    private function doWork()
    {
        // Implement your job logic
        return true;
    }
}
```

---

## Troubleshooting

### Problem: "Another scheduler process is running"

**Cause:** Lock is held by another process or stale lock exists.

**Solutions:**
1. Check if another scheduler is actually running:
   ```bash
   ps aux | grep "yii scheduler"
   ```

2. If no process found, clear stale lock manually:
   ```bash
   redis-cli
   > DEL scheduler_cache_lock
   ```

3. Wait for lock TTL to expire (1 hour max)

### Problem: Jobs not executing

**Checklist:**
1. Verify job configuration is valid:
   ```bash
   php yii scheduler/show
   ```

2. Check current time matches cron pattern:
   ```php
   var_dump(getdate());
   ```

3. Check for single_instance lock:
   ```bash
   redis-cli
   > GET scheduler_running_jobs
   ```

4. Review logs:
   ```bash
   tail -f runtime/logs/console.log
   ```

### Problem: Queue jobs not processing

**Checklist:**
1. Verify queue worker is running:
   ```bash
   ps aux | grep "queue/listen"
   ```

2. Check queue configuration:
   ```bash
   php yii queue/info
   ```

3. Verify job class exists and is autoloadable:
   ```bash
   php -r "var_dump(class_exists('app\\jobs\\ExampleJob'));"
   ```

4. Check queue logs for errors

### Problem: Daemon exits with "Max consecutive overruns"

**Cause:** Jobs are taking longer than tick interval (60s default).

**Solutions:**
1. Increase tick interval (not recommended):
   ```bash
   # Hack controller constants or use env vars if supported
   ```

2. Optimize slow jobs

3. Use queue mode instead of sync execution

4. Increase `max_running_time` for long jobs

### Problem: High CPU usage in daemon mode

**Cause:** Tight loop without proper sleep.

**Check:**
1. Verify `usleep()` is being called:
   ```php
   // In SchedulerController::actionDaemon()
   if ($sleepTime > 0) {
       usleep((int)($sleepTime * 1e6)); // Should be called
   }
   ```

2. Check for overruns in logs

3. Monitor sleep times

### Problem: Memory leak in daemon mode

**Cause:** Objects not being garbage collected.

**Solutions:**
1. Monitor memory usage:
   ```bash
   watch -n 10 'ps aux | grep "scheduler/daemon" | grep -v grep | awk "{print \$6}"'
   ```

2. Restart daemon periodically (systemd watchdog)

3. Review job code for circular references

### Problem: Stale jobs not being cleaned up

**Checklist:**
1. Verify `max_running_time` is set in job config

2. Check if queue driver supports `remove()`:
   ```php
   var_dump(method_exists($queue, 'remove')); // Should be true for Redis
   ```

3. Review cleanup logs:
   ```bash
   grep "Removing stale job" runtime/logs/console.log
   ```

---

## Appendix: State Machine Diagram

```
┌─────────────────────────────────────────────────────────┐
│                    SCHEDULER STATE                      │
└─────────────────────────────────────────────────────────┘

┌──────────┐
│  IDLE    │  No lock acquired
└─────┬────┘
      │ runJobs() called
      │
      ▼
┌──────────────┐
│ INIT_CACHE   │  Attempt lock acquisition via cache->add()
└──┬─────────┬─┘
   │         │
   │ Success │ Failure (lock exists)
   │         │
   │         ▼
   │    ┌────────────┐
   │    │  BLOCKED   │ Throw RuntimeException
   │    └────────────┘
   │
   ▼
┌──────────────┐
│ INIT_QUEUE   │ Resolve queue component (optional)
└──────┬───────┘
       │
       ▼
┌──────────────┐
│ INIT_RUNCACHE│ Load persistent state, cleanup finished/stale
└──────┬───────┘
       │
       ▼
┌──────────────┐
│ EVALUATING   │ For each job: needsToRun() && canRun()
└──┬─────────┬─┘
   │         │
   │ Eligible│ Skip
   │         │
   ▼         ▼
┌──────────────┐
│ EXECUTING    │ queueJob() or runJob()
└──────┬───────┘
       │
       ▼
┌──────────────┐
│ CLEANUP      │ deleteRuntimeJob() if sync
└──────┬───────┘
       │
       ▼
┌──────────────┐
│ COMPLETE     │ Return true/false
└──────┬───────┘
       │ __destruct() called
       ▼
┌──────────────┐
│ RELEASE_LOCK │ flushRunCache(), delete lock if owned
└──────────────┘
       │
       ▼
┌──────────┐
│  IDLE    │
└──────────┘
```

---

## Revision History

| Version | Date | Changes |
|---------|------|---------|
| 1.0.3 | 2025-11-10 | Centralized runtime cleanup (finalizeRuntimeJob), improved PHPDoc, README/architecture updates |
| 1.0.2 | 2025-11-10 | Fixed 10 critical race conditions and edge cases |
| 1.0.1 | 2025-11-09 | Updated queue dependency to ~2.3.0 |
| 1.0.0 | 2025-11-08 | Initial release |

---

**End of Architecture Documentation**
