<?php
namespace ldkafka\scheduler;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\base\ErrorException;

/**
 * Console controller driving scheduling ticks.
 */
class SchedulerController extends Controller
{
    public const DEBUG_MODE = false; // enable verbose logging for development
    public const SINGLE_INSTANCE_DEFAULT = true;
    public const MAX_RUNNING_TIME_DEFAULT = 0; // 0 = unlimited

    public $defaultAction = 'show';
    /** @var Scheduler */
    public $scheduler;
    private $trigger_time; // getdate() array

    // Logging uses Yii native logger with category 'scheduler'

    /**
     * Initialize controller and wire scheduler, cache and queue.
     */
    public function init()
    {
        parent::init();
        ($this->scheduler && ($this->scheduler instanceof Scheduler)) or $this->initScheduler();
        $this->initCache();
        $this->initQueue();
    }

    private function initScheduler(): void
    {
        $this->scheduler = $this->scheduler ?? (Yii::$app->scheduler ?? new Scheduler());
    }

    /**
     * Enforce global Yii cache for distributed locks.
     * Resolve and validate cache component (uses Yii::$app->cache).
     */
    private function initCache(): void
    {
        $cache = Yii::$app->cache ?? null;
        if ((!is_object($cache)) || ($cache->set(self::class, 'init') === false)) {
            $msg = 'Scheduler cache component is not configured. See Yii caching docs.';
            Yii::error($msg, 'scheduler');
            echo $msg . PHP_EOL;
            return; // leave cache unset; run without locking
        }
        $this->scheduler->setCache($cache);
    }

    /**
     * Resolve queue component if configured (yiisoft/yii2-queue expected).
     */
    private function initQueue(): void
    {
        if (!empty($this->scheduler->config['queue'])) {
            $queueName = $this->scheduler->config['queue'];
            if (isset(Yii::$app->$queueName) && is_object(Yii::$app->$queueName) && method_exists(Yii::$app->$queueName, 'push')) {
                $this->scheduler->setQueue(Yii::$app->$queueName);
                return;
            }
            Yii::warning('Running without queue support. Configure yiisoft/yii2-queue for async execution.', 'scheduler');
        }
    }
    /**
     * Build the list of jobs that need to run for the current tick and acquire locks.
     */
    private function initJobs(): void
    {
        // Optional stale lock cleanup
        $this->cleanupStaleLocks();

        $this->trigger_time = getdate();
        foreach ($this->scheduler->jobs as $idx => $job_config) {
            if (!isset($job_config['class']) || !class_exists($job_config['class'])) {
                Yii::error("Job #{$idx} class not found: " . ($job_config['class'] ?? 'undefined'), 'scheduler');
                continue;
            }
            if (!is_subclass_of($job_config['class'], ScheduledJob::class)) {
                Yii::error("Job #{$idx} {$job_config['class']} must extend ScheduledJob.", 'scheduler');
                continue;
            }
            if (!isset($job_config['run'])) {
                Yii::error("Job #{$idx} run specification missing.", 'scheduler');
                continue;
            }
            if (!is_array($job_config['run'])) { // symbolic
                if (!array_key_exists($job_config['run'], $this->scheduler->running_time)) {
                    Yii::error("Job #{$idx} run symbolic '{$job_config['run']}' invalid.", 'scheduler');
                    continue;
                }
                $job_config['run'] = $this->scheduler->running_time[$job_config['run']];
            }
            $job_config['single_instance'] = $job_config['single_instance'] ?? self::SINGLE_INSTANCE_DEFAULT;
            $job_config['max_running_time'] = $job_config['max_running_time'] ?? self::MAX_RUNNING_TIME_DEFAULT;

            if ($this->needsToRun($job_config)) {
                self::DEBUG_MODE && Yii::info("Job {$job_config['class']} needs to run.", 'scheduler');
                if ($job_config['single_instance'] && $this->isRunning($job_config)) {
                    Yii::warning("Job {$job_config['class']} already running. Skipping.", 'scheduler');
                } else {
                    // Acquire atomic lock first; only then schedule the job for execution
                    if ($this->acquireLock($job_config)) {
                        $this->scheduler->addRuntimeJob($job_config);
                    } else {
                        Yii::warning("Job {$job_config['class']} lock acquisition failed. Another runner likely won the race.", 'scheduler');
                    }
                }
            } else {
                self::DEBUG_MODE && Yii::info("Job {$job_config['class']} does not need to run now.", 'scheduler');
            }
        }
    }

    /**
     * Remove obviously stale locks when configured.
     * A lock is considered stale if:
     *  - 'staleLockTtl' (seconds) is configured and
     *  - current time - timestamp > staleLockTtl and there is no queue job id.
     */
    private function cleanupStaleLocks(): void
    {
        $ttl = (int)($this->scheduler->config['staleLockTtl'] ?? 0);
        if ($ttl <= 0) { return; }
        $cache = Yii::$app->cache ?? null;
        if (!$cache) { return; }

        foreach ($this->scheduler->jobs as $job_config) {
            if (empty($job_config['class']) || empty($job_config['run'])) { continue; }
            $key = $this->scheduler->cacheRunKey([
                'class' => $job_config['class'],
                'run'   => is_array($job_config['run']) ? $job_config['run'] : ($this->scheduler->running_time[$job_config['run']] ?? $job_config['run']),
            ]);
            $data = $cache->get($key);
            if (!is_array($data) || empty($data['timestamp'])) { continue; }
            $age = time() - (int)$data['timestamp'];
            if ($age > $ttl && empty($data['jid'])) {
                $cache->delete($key);
                Yii::warning("Stale lock removed for {$job_config['class']} (age={$age}s)", 'scheduler');
            }
        }
    }

    /**
     * Atomically acquire a lock for a job using the global cache.
     * Returns true if acquired, false if it already exists.
     */
    private function acquireLock(array $job_config): bool
    {
        $cache = Yii::$app->cache ?? null;
        if (!$cache) { return true; } // if no cache, proceed without locking
        $key = $this->scheduler->cacheRunKey($job_config);
        $payload = [
            'timestamp' => time(),
            'pid' => getmypid(),
            'host' => php_uname('n'),
            'jid' => null,
        ];
        $ttl = (!empty($job_config['max_running_time']) && (int)$job_config['max_running_time'] > 0)
            ? ((int)$job_config['max_running_time'] + 30)
            : 3600;
        try {
            return (bool)$cache->add($key, $payload, $ttl);
        } catch (\Throwable $e) {
            Yii::warning('Lock acquire failed due to cache error: ' . $e->getMessage(), 'scheduler');
            return false;
        }
    }

    private function needsToRun(array $job_config): bool
    {
        foreach ($job_config['run'] as $key => $val) {
            $val = trim($val);
            if ($val === '*') { // wildcard
                continue;
            }
            if (!isset($this->trigger_time[$key])) {
                return false;
            }
            $current = $this->trigger_time[$key];
            if (is_numeric($val)) {
                if ((int)$val === (int)$current) { continue; }
                return false;
            }
            // step pattern */n
            if (str_starts_with($val, '*/')) {
                $step = (int)substr($val, 2);
                if ($step > 0 && ($current % $step) === 0) { continue; }
                return false;
            }
            // range a-b
            if (strpos($val, '-') !== false) {
                [$from, $to] = array_map('trim', explode('-', $val, 2));
                if (is_numeric($from) && is_numeric($to) && $current >= (int)$from && $current <= (int)$to) { continue; }
                return false;
            }
            // list a,b,c
            if (strpos($val, ',') !== false) {
                $list = array_map('trim', explode(',', $val));
                if (in_array((string)$current, $list, true)) { continue; }
                return false;
            }
            return false; // unsupported pattern
        }
        return true;
    }

    /**
     * Check if a lock already exists for the job and enforce max running time.
     */
    private function isRunning(array $job_config): bool
    {
        $cache = Yii::$app->cache ?? null;
        if (!$cache) { return false; }
        $data = $cache->get($this->scheduler->cacheRunKey($job_config));
        if (!$data) { return false; }
        if (is_array($data) && isset($job_config['max_running_time'], $data['timestamp']) && $job_config['max_running_time'] > 0) {
            if ((time() - $data['timestamp']) > $job_config['max_running_time']) {
                Yii::warning("Job {$job_config['class']} exceeded max running time. Attempting kill.", 'scheduler');
                return !$this->killRunningJob($job_config, $data);
            }
        }
        return true;
    }

    /**
     * Attach the queue job id to the lock payload (if lock exists).
     */
    private function updateJobId(array $job_config, int $jid): void
    {
        $cache = Yii::$app->cache ?? null;
        if (!$cache) { return; }
        $key = $this->scheduler->cacheRunKey($job_config);
        $job_data = $cache->get($key);
        if (!is_array($job_data)) {
            Yii::warning("Cannot set jid; lock missing for {$job_config['class']}.", 'scheduler');
            return;
        }
        $job_data['jid'] = $jid;
        $cache->set($key, $job_data);
    }

    

    /**
     * Release the job lock.
     */
    private function stopRunning(array $job_config): void
    {
        Yii::$app->cache?->delete($this->scheduler->cacheRunKey($job_config));
    }

    private function killRunningJob(array $job_config, array $job_data): bool
    {
        if (!empty($job_data['jid']) && $this->scheduler->getQueue()) {
            try {
                $this->scheduler->getQueue()->delete($job_data['jid']);
                $this->stopRunning($job_config);
                Yii::info("Killed queued job {$job_config['class']} (jid {$job_data['jid']}).", 'scheduler');
                return true;
            } catch (\Throwable $e) {
                Yii::error('Failed to delete queued job: ' . $e->getMessage(), 'scheduler');
            }
        }
        if (!empty($job_data['pid']) && $job_data['pid'] !== getmypid() && function_exists('posix_kill')) {
            if (@posix_kill($job_data['pid'], 9)) {
                $this->stopRunning($job_config);
                Yii::warning("SIGKILL sent to PID {$job_data['pid']} for stale job {$job_config['class']}.", 'scheduler');
                return true;
            }
        }
        return false;
    }

    /**
     * Show configured jobs.
     */
    public function actionShow(): int
    {
        if (!empty($this->scheduler->jobs)) {
            echo 'Jobs configured: ' . PHP_EOL;
            print_r($this->scheduler->jobs);
        } else {
            echo 'No jobs configured.' . PHP_EOL;
        }
        return ExitCode::OK;
    }

    /**
     * Execute a single scheduling tick: evaluate jobs and enqueue/run them.
     */
    public function actionRun(): int
    {
        $this->initJobs();
        $maxPerTick = (int)($this->scheduler->config['maxJobsPerTick'] ?? PHP_INT_MAX);
        $processed = 0;
        foreach ($this->scheduler->getRuntimeJobs() as $job_config) {
            if ($processed >= $maxPerTick) {
                Yii::warning("Per-tick limit reached (maxJobsPerTick={$maxPerTick}), remaining jobs deferred.", 'scheduler');
                break;
            }
            if ($this->scheduler->getQueue()) {
                try {
                    $jid = $this->scheduler->getQueue()->push(new $job_config['class']([
                        'scheduler' => $this->scheduler,
                        'job_config' => $job_config,
                    ]));
                    $this->updateJobId($job_config, $jid);
                    Yii::info("Queued job {$job_config['class']} (jid {$jid}).", 'scheduler');
                    $processed++;
                } catch (ErrorException | \Throwable $e) {
                    Yii::error("Exception queueing job {$job_config['class']}: " . $e->getMessage(), 'scheduler');
                    $this->stopRunning($job_config);
                    return ExitCode::UNSPECIFIED_ERROR;
                }
            } else { // synchronous
                try {
                    $job_object = new $job_config['class']([
                        'scheduler' => $this->scheduler,
                        'job_config' => $job_config,
                    ]);
                    $ret = $job_object->execute();
                    $this->stopRunning($job_config);
                    $exit = $ret ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
                    Yii::info("Executed job {$job_config['class']} exit={$exit}.", 'scheduler');
                    $processed++;
                } catch (ErrorException | \Throwable $e) {
                    $this->stopRunning($job_config);
                    Yii::error("Exception running job {$job_config['class']}: " . $e->getMessage(), 'scheduler');
                    return ExitCode::UNSPECIFIED_ERROR;
                }
            }
        }
        return ExitCode::OK;
    }

    /**
     * Daemon mode: run scheduling ticks continuously using high-resolution timing.
     * Options via ENV or params:
     *  SCHEDULER_TICK_INTERVAL (int seconds, default 1)
     *  SCHEDULER_MAX_MISSED   (int consecutive missed ticks before exit, default 5)
     *  SCHEDULER_HEARTBEAT_TTL (seconds for heartbeat expiry, default 10)
     * Stop conditions: SIGINT/SIGTERM (if pcntl available) or too many missed ticks.
     * Long-running daemon loop performing ticks at a fixed interval with drift correction.
     */
    public function actionDaemon(): int
    {
        $tickInterval = (int)(getenv('SCHEDULER_TICK_INTERVAL') ?: 1);
        if ($tickInterval < 1) { $tickInterval = 1; }
        $maxMissed      = (int)(getenv('SCHEDULER_MAX_MISSED') ?: 5);
        $heartbeatTtl   = (int)(getenv('SCHEDULER_HEARTBEAT_TTL') ?: 10);
        $cache = Yii::$app->cache ?? null;
        $heartbeatKey = 'scheduler_daemon_heartbeat';

        $missedConsecutive = 0;
        $ticks = 0;
        $running = true;

        // Signal handling if available
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $sig) {
                pcntl_signal($sig, function() use (&$running) {
                    Yii::info('Received termination signal. Shutting down daemon loop.', 'scheduler');
                    $running = false;
                });
            }
        }

        $startWall = microtime(true);
        $baseSecond = (int)$startWall; // anchor
    Yii::info("Scheduler daemon starting. Interval={$tickInterval}s maxMissed={$maxMissed}", 'scheduler');

        while ($running) {
            $loopStart = microtime(true);
            $ticks++;

            // Heartbeat
            try {
                $cache?->set($heartbeatKey, [
                    'ts' => time(),
                    'pid' => getmypid(),
                    'ticks' => $ticks,
                ], $heartbeatTtl);
            } catch (\Throwable $e) {
                Yii::warning('Heartbeat set failed: ' . $e->getMessage(), 'scheduler');
            }

            // Perform a scheduling tick
            try {
                $this->initJobs();
                $maxPerTick = (int)($this->scheduler->config['maxJobsPerTick'] ?? PHP_INT_MAX);
                $processed = 0;
                foreach ($this->scheduler->getRuntimeJobs() as $job_config) {
                    if ($processed >= $maxPerTick) {
                        Yii::warning("[tick={$ticks}] Per-tick limit reached (maxJobsPerTick={$maxPerTick}), remaining jobs deferred.", 'scheduler');
                        break;
                    }
                    if ($this->scheduler->getQueue()) {
                        try {
                            $jid = $this->scheduler->getQueue()->push(new $job_config['class']([
                                'scheduler' => $this->scheduler,
                                'job_config' => $job_config,
                            ]));
                            $this->updateJobId($job_config, $jid);
                            Yii::info("[tick={$ticks}] Queued job {$job_config['class']} jid={$jid}", 'scheduler');
                            $processed++;
                        } catch (\Throwable $e) {
                            Yii::error("[tick={$ticks}] Queue exception for {$job_config['class']}: " . $e->getMessage(), 'scheduler');
                            $this->stopRunning($job_config);
                        }
                    } else { // sync
                        $startJob = microtime(true);
                        try {
                            $job_object = new $job_config['class']([
                                'scheduler' => $this->scheduler,
                                'job_config' => $job_config,
                            ]);
                            $ret = $job_object->execute();
                            $this->stopRunning($job_config);
                            $dur = round((microtime(true) - $startJob) * 1000);
                            Yii::info("[tick={$ticks}] Executed job {$job_config['class']} ret=" . ($ret ? 'OK' : 'FAIL') . " durationMs={$dur}", 'scheduler');
                            $processed++;
                        } catch (\Throwable $e) {
                            $this->stopRunning($job_config);
                            Yii::error("[tick={$ticks}] Job exception {$job_config['class']}: " . $e->getMessage(), 'scheduler');
                        }
                    }
                }
            } catch (\Throwable $e) {
                Yii::error('Tick exception: ' . $e->getMessage(), 'scheduler');
            }

            // Timing / drift correction
            $elapsed = microtime(true) - $loopStart;
            $targetNext = $baseSecond + ($ticks * $tickInterval) + $tickInterval; // next second boundary factoring interval
            $now = microtime(true);
            $sleepSec = $targetNext - $now;
            if ($sleepSec > 0) {
                $missedConsecutive = 0; // good tick
                usleep((int)($sleepSec * 1e6));
            } else {
                // Missed target; we ran longer than interval
                $missedConsecutive++;
                Yii::warning("Tick overrun (elapsed=" . round($elapsed,3) . "s, missedConsecutive={$missedConsecutive})", 'scheduler');
                if ($missedConsecutive >= $maxMissed) {
                    Yii::error('Max consecutive missed ticks exceeded. Exiting daemon.', 'scheduler');
                    break;
                }
            }

            // Periodic re-anchor to avoid drift accumulation (every 300 ticks)
            if (($ticks % 300) === 0) {
                $baseSecond = (int)microtime(true);
                Yii::info('Re-anchored base second for drift correction.', 'scheduler');
            }
        }

    Yii::info('Scheduler daemon stopped. Total ticks=' . $ticks, 'scheduler');
        return ExitCode::OK;
    }

    /**
     * Show scheduler status: configured jobs, heartbeat and active locks.
     */
    public function actionStatus(): int
    {
        $cache = Yii::$app->cache ?? null;
        $heartbeat = $cache?->get('scheduler_daemon_heartbeat');
        echo "Scheduler status\n";
        echo str_repeat('=', 60) . "\n";
        echo "Heartbeat: " . ($heartbeat ? json_encode($heartbeat) : 'none') . "\n\n";

        echo "Jobs (" . count($this->scheduler->jobs) . "):\n";
        foreach ($this->scheduler->jobs as $job) {
            $class = $job['class'] ?? 'undefined';
            $run   = $job['run'] ?? [];
            $normRun = is_array($run) ? $run : ($this->scheduler->running_time[$run] ?? $run);
            $key = $this->scheduler->cacheRunKey(['class' => $class, 'run' => $normRun]);
            $lock = $cache?->get($key);
            echo "- {$class}\n";
            echo "  run: " . (is_array($normRun) ? json_encode($normRun) : (string)$normRun) . "\n";
            echo "  lockKey: {$key}\n";
            echo "  lock: " . ($lock ? json_encode($lock) : 'none') . "\n";
        }
        echo "\n";
        return ExitCode::OK;
    }
}
