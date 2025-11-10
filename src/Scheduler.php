<?php
namespace ldkafka\scheduler;

use Yii;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\console\Application;
use yii\base\InvalidConfigException;
use yii\helpers\Inflector;
use yii\console\ExitCode;
use yii\base\ErrorException;

/**
 * Scheduler component.
 *
 * Responsibilities:
 *  - Holds static job configuration (symbolic schedules resolved by controller)
 *  - Bootstraps a console controller mapped to this component id
 *  - Provides runtime arrays (queue/cache objects, jobs selected for current tick)
 *  - Generates stable cache lock keys via cacheRunKey()
 *
 * Public contract summary:
 *  - jobs: array of job definitions, each containing:
 *      class (string Fully Qualified Class Name extending ScheduledJob)
 *      run   (array cron-like pattern: keys minutes,hours,day,mday,wday)
 *      single_instance (bool) optional
 *      max_running_time (int seconds) optional
 *  - running_time: symbolic shortcuts (e.g. EVERY_MINUTE) expanded before evaluation.
 */
class Scheduler extends Component implements BootstrapInterface
{
    public const EVERY_MINUTE = 1;
    public const EVERY_HOUR   = 60;
    public const EVERY_DAY    = 1440;
    public const EVERY_WEEK   = 10080;
    public const EVERY_MONTH  = 43800;
    public const SPECIFIC_TIME = 'cron';
    public const SINGLE_INSTANCE_DEFAULT = true;
    public const MAX_RUNNING_TIME_DEFAULT = 0; // 0 = unlimited

    public const SCHEDULER_LOCK_CACHE_KEY = 'scheduler_cache_lock';
    public const RUNNING_JOBS_CACHE_KEY = 'scheduler_running_jobs';

    // $config and $jobs are from the component configuration and are set by the constructor 
    public $config =[];
    public $jobs = [];

    private $_cache = null;
    private $_queue = null;

    /** @var array job definitions */
    private $_loaded_jobs = []; // parsed from component configuration

    /** @var array queued for this run - jobs are processed in Scheduler from, $_loaded_jobs and run if needed */
    private $_run_cache = []; // running jobs - these are persistent (or inprocess if no cache) records of running jobs - jobs that can potentially span across 1 or more runs (ticks) of the Scheduler.

    /** @var array symbolic schedule templates expanded inside controller */
    public $running_time = [
        'EVERY_MINUTE' => ['minutes' => '*'],
        'EVERY_HOUR'   => ['minutes' => 1, 'hours' => '*'],
        // Use 'mday' (day of month) as provided by getdate(); 'day' is not a valid getdate key
        'EVERY_DAY'    => ['minutes' => 10, 'hours' => 2, 'mday' => '*'],
        'EVERY_WEEK'   => ['minutes' => 20, 'hours' => 6, 'wday' => 1],
        'EVERY_MONTH'  => ['minutes' => 30, 'hours' => 1, 'mday' => 1],
        'SPECIFIC_TIME'=> [],
    ];

    private $_lock_pid = null; // cache lock for scheduler process
    private $_lock_meta = null; // full lock metadata array: ['pid'=>int,'host'=>string,'ts'=>int]
    private $_trigger_time; // getdate() array - stores when scheduler was run

    public function init() {
        parent::init();
        $this->initJobs(); // load job configuration
    }

    public function __destruct() {
        if($this->cache && $this->_lock_pid) {
            try {
                $this->flushRunCache();
                // Only delete lock if it still belongs to us (metadata array form only)
                $current_meta = $this->cache->get(self::SCHEDULER_LOCK_CACHE_KEY);
                $current_pid = is_array($current_meta) ? ($current_meta['pid'] ?? null) : null;
                if($current_pid !== null && $current_pid == $this->_lock_pid) {
                    $this->cache->delete(self::SCHEDULER_LOCK_CACHE_KEY);
                    Yii::info("Scheduler lock released by PID {$this->_lock_pid}", 'scheduler');
                } else {
                    Yii::warning("Lock was taken by another process (PID {$current_pid}). Not deleting.", 'scheduler');
                }
            } catch (\Throwable $e) {
                Yii::warning('Failed to flush data and release scheduler cache lock in destructor: ' . $e->getMessage(), 'scheduler');
            }
        }
    }

    /**
     * Persist the in-memory run cache to the shared cache backend.
     * Safe to call multiple times; writes entire RUNNING_JOBS_CACHE_KEY payload.
     *
     * @return void
     * @throws \Throwable When cache->set throws; callers should handle except in destructor where it's caught.
     */
    private function flushRunCache() {
        if(!$this->cache) {
            return; // No cache, nothing to flush
        }
        
        try {
            // Safe because only one scheduler process holds the lock at a time
            // Queue workers read this data but never write to it
            $this->cache->set(self::RUNNING_JOBS_CACHE_KEY, $this->_run_cache);
        } catch (\Throwable $e) {
            Yii::error("Failed to flush run cache: " . $e->getMessage(), 'scheduler');
            throw $e; // Re-throw to let caller handle it
        }
    }

    /**
     * Enforce global Yii cache for distributed locks.
     * Resolve and validate cache component (uses Yii::$app->cache).
     */
    private function initCache(): bool
    {
        $cache_name = $this->config['cache'] ?? 'cache';
        $cache = Yii::$app->$cache_name ?? null;

        if(is_object($cache)) {
            try {
                $pid = getmypid();
                $lock_ttl = 3600; // 1 hour - safer than 5 minutes for long-running jobs
                $meta = ['pid' => $pid, 'host' => gethostname(), 'ts' => time()];

                // Attempt atomic acquisition with metadata
                $acquired = $cache->add(self::SCHEDULER_LOCK_CACHE_KEY, $meta, $lock_ttl);

                if(!$acquired) {
                    // Lock exists; fetch current metadata (array expected)
                    $existing_meta = $cache->get(self::SCHEDULER_LOCK_CACHE_KEY);
                    $existing_pid = $existing_meta['pid'] ?? 'unknown';
                    Yii::info("Scheduler lock held by PID {$existing_pid}. Evaluating staleness...", 'scheduler');

                    $runningJobs = $cache->get(self::RUNNING_JOBS_CACHE_KEY) ?: [];
                    $stale = empty($runningJobs); // heuristic – no jobs tracked

                    if($stale) {
                        // Re-read before delete to avoid race
                        $current_meta = $cache->get(self::SCHEDULER_LOCK_CACHE_KEY);
                        if($current_meta === $existing_meta) {
                            $cache->delete(self::SCHEDULER_LOCK_CACHE_KEY);
                            Yii::warning("Removed stale scheduler lock for PID {$existing_pid} (no jobs in cache). Retrying acquisition...", 'scheduler');
                            $acquired = $cache->add(self::SCHEDULER_LOCK_CACHE_KEY, $meta, $lock_ttl);
                        } else {
                            Yii::info("Lock value changed during reclaim attempt; aborting stale cleanup.", 'scheduler');
                        }
                    } else {
                        throw new \RuntimeException("Another scheduler process PID {$existing_pid} is active (jobs present); lock not reclaimed.");
                    }
                }

                if($acquired) {
                    $this->_lock_pid = $pid;
                    $this->_lock_meta = $meta;
                    $this->setCache($cache);
                    Yii::info("Scheduler lock acquired by PID {$pid} (host={$meta['host']})", 'scheduler');
                    return true;
                }

            } catch (\Throwable $e) {
                Yii::error("Cache lock acquisition failed: " . $e->getMessage(), 'scheduler');
                throw $e;
            }
        }

        Yii::warning('No cache component configured for scheduler. Running with limited functionality.', 'scheduler');
        
        return false;
    }

    /**
     * Resolve queue component if configured (yiisoft/yii2-queue expected).
     */
    private function initQueue(): bool
    {
        if(is_array($this->config) && isset($this->config['queue'])) {
            $queueName = $this->config['queue'];
            if (isset(Yii::$app->$queueName) && is_object(Yii::$app->$queueName) && method_exists(Yii::$app->$queueName, 'push')) {
                $this->setQueue(Yii::$app->$queueName);
                return true;
            }
        }

    Yii::info('Running without queue support. Configure yiisoft/yii2-queue for async execution.', 'scheduler');

        return false;
    }

    /**
     * Load existing persistent jobs from cache.
     */
        private function initRunCache(): void
    {
        if($this->cache) {
            if($this->cache->exists(self::RUNNING_JOBS_CACHE_KEY)) {
                Yii::info('Loading existing running jobs from cache.', 'scheduler');
                $this->_run_cache  = $this->cache->get(self::RUNNING_JOBS_CACHE_KEY);
            } else {
                $this->_run_cache  = [];
            }

            foreach($this->_run_cache as $cache_id => $jobs) {

                foreach ($jobs as $job_index => $job) {
                    // clean up queued jobs that have finished
                    if(isset($job['qid']) && $job['qid'] && $this->queue) {
                        try {
                            if($this->queue->isDone($job['qid'])) {
                                Yii::info("Removing completed queue job {$job['qid']} from run cache.", 'scheduler');
                                unset($this->_run_cache[$cache_id][$job_index]);
                                continue;
                            }
                        } catch (\Throwable $e) {
                            Yii::warning("Failed to check queue status for job {$job['qid']}: " . $e->getMessage(), 'scheduler');
                            // Don't remove job if we can't verify status - safer to leave it
                        }
                    }

                    // clean up any stale jobs that have exceeded max_running_time
                    $job_config = $job['conf'] ?? null;
                    if ($job_config && isset($job_config['max_running_time'])) {
                        $max_time = (int)$job_config['max_running_time'];
                        $start_time = $job['start_time'] ?? 0;
                        $running_time = time() - $start_time;
                        
                        if ($max_time > 0 && $running_time > $max_time) {
                            $job_class = $job_config['class'] ?? 'unknown';
                            Yii::warning("Removing stale job {$job_class} from run cache (running {$running_time}s, max {$max_time}s).", 'scheduler');
                            
                            // Attempt to remove the job from queue if it's queued and driver supports removal
                            if(isset($job['qid']) && $job['qid'] && $this->queue) {
                                try {
                                    if(method_exists($this->queue, 'remove')) {
                                        $this->queue->remove($job['qid']);
                                        Yii::info("Removed stale queue job {$job['qid']} from queue.", 'scheduler');
                                    } else {
                                        Yii::info("Queue driver does not support remove() method. Job {$job['qid']} left in queue.", 'scheduler');
                                    }
                                } catch (\Throwable $e) {
                                    Yii::warning("Failed to remove stale queue job {$job['qid']}: " . $e->getMessage(), 'scheduler');
                                }
                            }
                            
                            unset($this->_run_cache[$cache_id][$job_index]);
                            continue;
                        }
                    }
                }

                if(empty($this->_run_cache[$cache_id])) {
                    unset($this->_run_cache[$cache_id]);
                }
            }
        } else {
            $this->_run_cache = [];
        }

        /* Example structure:
        $this->_run_cache = [
            'job_cache_key' => [
                0 => [
                    'start_time' => timestamp,
                    'pid' => process id, (main process id)
                    'host' => hostname,
                    'qid' => queue job id (if applicable),
                    ], ...
            ],
        ];
        */
    }

    public function initJobs() {
        if(!is_array($this->jobs) || empty($this->jobs)) {
            Yii::warning('Scheduler jobs configuration empty. No jobs will be scheduled.', 'scheduler');
            return;
        }

        foreach ($this->jobs as $idx => $job_config) {
            if (!isset($job_config['class']) || !class_exists($job_config['class'])) {
                Yii::error("Job #{$idx} class not found: " . ($job_config['class'] ?? 'undefined') . ' - skipping this job configuration', 'scheduler');
                unset($this->_loaded_jobs[$idx]);
                continue;
            }
            if (!is_subclass_of($job_config['class'], ScheduledJob::class)) {
                Yii::error("Job #{$idx} {$job_config['class']} must extend ScheduledJob - skipping this job configuration", 'scheduler');
                unset($this->_loaded_jobs[$idx]);
                continue;
            }
            if (!isset($job_config['run'])) {
                Yii::error("Job #{$idx} run specification missing - skipping this job configuration", 'scheduler');
                unset($this->_loaded_jobs[$idx]);
                continue;
            }
            if (!is_array($job_config['run'])) { // symbolic
                if (!array_key_exists($job_config['run'], $this->running_time)) {
                    Yii::error("Job #{$idx} run symbolic '{$job_config['run']}' invalid.", 'scheduler');
                    continue;
                }
                $job_config['run'] = $this->running_time[$job_config['run']];
            }

            $job_config['single_instance'] = $job_config['single_instance'] ?? self::SINGLE_INSTANCE_DEFAULT;
            $job_config['max_running_time'] = $job_config['max_running_time'] ?? self::MAX_RUNNING_TIME_DEFAULT;

            $this->_loaded_jobs[$idx] = $job_config;
        }

        // moved to runJobs: $this->_trigger_time = getdate();
    }

    /**
     * Locate the component id as registered in the app so we can map controller.
     * @return string
     * @throws InvalidConfigException
     */
    protected function getCommandId(): string
    {
        foreach (Yii::$app->getComponents(false) as $id => $component) {
            if ($component === $this) {
                return Inflector::camel2id($id);
            }
        }
        throw new InvalidConfigException('Scheduler must be an application component.');
    }

    /** @inheritdoc */
    public function bootstrap($app)
    {
        if ($app instanceof Application) {
            $app->controllerMap[$this->getCommandId()] = [
                'class' => SchedulerController::class,
                'scheduler' => $this,
            ];
        }
    }

    /**
     * Add a job config selected for execution during the current tick.
     * @param array $job validated job configuration
     * @return int numeric index used as identifier
     */
    /**
     * Register a job into the in-memory runtime cache for this tick.
     *
     * Contract:
     * - Must be called before queueing or running a job so we can track it.
     * - Returns the index under which the job is stored for later updates.
     *
     * @param string $job_cache_key Unique key produced by cacheRunKey()
     * @param int $job_id Numeric index in the configured jobs array
     * @param array $job_config The validated job configuration
     * @return int Index of the job in the runtime cache (used as job_index)
     */
    public function addRuntimeJob(string $job_cache_key, int $job_id, array $job_config): int
    {
        /* Example structure:
        $this->_run_cache = [
            'job_cache_key' => [
                0 => [
                    'start_time' => timestamp,
                    'pid' => process id, (main process id)
                    'host' => hostname,
                    'qid' => queue job id (if applicable),
                    'cid' => configuration index number
                    'conf' => job configuration
                ], ...
            ],
        ];
        */

        $job = [
            'start_time' => time(),
            'pid' => getmypid(),
            'host' => gethostname(),
            'cid' => $job_id,
            'conf' => $job_config,
        ];

        $this->_run_cache[$job_cache_key][] = $job;

        return array_key_last($this->_run_cache[$job_cache_key]);
    }

    /**
     * Finalize and remove a runtime job entry from persistent cache.
     * Can be invoked by queue workers (via SafeJobWrapper) to proactively
     * clean up the run cache when a queued job completes.
     *
     * Note: This method operates directly on the global cache and does not
     * rely on in-memory $_run_cache. It is safe to call without having
     * called initCache() first.
     */
    /**
     * Finalize and remove a runtime job entry from in-memory and/or persistent cache.
     *
     * Behavior:
     * - If this Scheduler instance has the target key in memory, mutate it and persist via flushRunCache().
     * - Otherwise, load RUNNING_JOBS_CACHE_KEY from the shared cache, mutate, and set it back.
     *
     * Typical callers:
     * - SafeJobWrapper::execute() finally block (both sync and async contexts)
     * - queueJob() failure path to clean up entries when push() fails
     *
     * @param string $job_cache_key Key returned by cacheRunKey() identifying the job family
     * @param int|null $job_index Optional concrete index to remove; when null removes all entries under the key
     * @return bool true when mutation attempted and cache state is consistent, false on hard failure
     */
    public function finalizeRuntimeJob(string $job_cache_key, ?int $job_index = null): bool
    {
        try {
            // Prefer in-memory mutation when this scheduler instance has the entry loaded
            if (isset($this->_run_cache[$job_cache_key])) {
                if ($job_index === null) {
                    unset($this->_run_cache[$job_cache_key]);
                } else {
                    if (isset($this->_run_cache[$job_cache_key][$job_index])) {
                        unset($this->_run_cache[$job_cache_key][$job_index]);
                        if (empty($this->_run_cache[$job_cache_key])) {
                            unset($this->_run_cache[$job_cache_key]);
                        }
                    }
                }

                // Persist updated in-memory cache if a cache instance is available
                if ($this->getCache()) {
                    $this->flushRunCache();
                } else {
                    Yii::warning('finalizeRuntimeJob: In-memory cache updated but no cache instance to persist.', 'scheduler');
                }

                Yii::info("finalizeRuntimeJob (in-memory): cleaned {$job_cache_key} index=" . ($job_index ?? 'all'), 'scheduler');
                return true;
            }

            // Fallback path: operate directly on persistent cache (typical for queue worker context)
            $cache = $this->getCache();
            if (!is_object($cache)) {
                $cache_name = $this->config['cache'] ?? 'cache';
                $cache = Yii::$app->$cache_name ?? null;
                if (!is_object($cache)) {
                    Yii::warning('finalizeRuntimeJob: No cache component available.', 'scheduler');
                    return false;
                }
            }

            $run_cache = $cache->get(self::RUNNING_JOBS_CACHE_KEY) ?: [];
            if ($job_index === null) {
                unset($run_cache[$job_cache_key]);
            } else {
                if (isset($run_cache[$job_cache_key][$job_index])) {
                    unset($run_cache[$job_cache_key][$job_index]);
                    if (empty($run_cache[$job_cache_key])) {
                        unset($run_cache[$job_cache_key]);
                    }
                }
            }

            $cache->set(self::RUNNING_JOBS_CACHE_KEY, $run_cache);

            Yii::info("finalizeRuntimeJob (persistent): cleaned {$job_cache_key} index=" . ($job_index ?? 'all'), 'scheduler');
            return true;
        } catch (\Throwable $e) {
            Yii::warning('finalizeRuntimeJob failed: ' . $e->getMessage(), 'scheduler');
            return false;
        }
    }

    
    /**
     * Get all job configs selected for this tick.
     * @return array
     */
    public function getRuntimeJobs(): array
    {
        return $this->_run_cache;
    }

        public function getLoadedJobs(): array
    {
        return $this->_loaded_jobs;
    }

    /**
     * Inject cache object (global Yii cache expected).
     * @param mixed $cache cache instance
     */
    public function setCache($cache): void
    {
        $this->_cache = $cache;
    }

    /**
     * Get cache instance used for locks.
     * @return mixed
     */
    public function getCache()
    {
        return $this->_cache;
    }

    /**
     * Inject queue object (yiisoft/yii2-queue implementation).
     * @param mixed $queue queue instance
     */
    public function setQueue($queue): void
    {
        $this->_queue = $queue;
    }

    /**
     * Get queue instance used for async job execution.
     * @return mixed
     */
    public function getQueue()
    {
        return $this->_queue;
    }

    /**
     * Build unique run key for lock tracking.
     * @param array $job_config
     * @return string cache key
     */
    public static function cacheRunKey(array $job_config): string
    {
        return 'scheduler_' . $job_config['class'] . '_' . md5(serialize($job_config['run']));
    }

    /**
     * Execute all jobs that need to run in the current tick.
     * Initializes cache, queue, and runtime cache before processing.
     * 
     * @return bool True if execution completed without fatal errors
     */
    /**
     * Execute all jobs eligible for the current tick.
     *
     * Orchestrates component initialization, loads persistent run state,
     * evaluates cron-like patterns, and runs or queues jobs accordingly.
     * Any individual job failure is logged but does not stop other jobs.
     *
     * @return bool True when all steps ran without fatal errors; false when any job failed or init crashed
     */
    public function runJobs(): bool {
        $had_errors = false;
        
        try {
            if($this->initCache()) {
                // cache must be setup for the queue features to work, so only init queue if cache is ok
                $this->initQueue();

                // load existing persistent jobs from cache
                $this->initRunCache(); // get persistent running jobs (if any)
            }
        } catch (\Throwable $e) {
            Yii::error("Critical error during scheduler initialization: " . $e->getMessage(), 'scheduler');
            return false; // Fatal error, cannot continue
        }
        
        $this->_trigger_time = getdate(); // save time of this run

        foreach($this->getLoadedJobs() as $job_id => $job_config) {
            try {
                if($this->needsToRun($job_id, $job_config)) {
                    Yii::info("Job {$job_config['class']} selected to run.", 'scheduler');
                    if($this->canRun($job_id, $job_config)) {
                        $job_cache_key = $this->cacheRunKey($job_config);  
                        $job_index = $this->addRuntimeJob($job_cache_key, $job_id, $job_config); // add to runtime jobs        
                            if($this->cache) {
                                if($this->queue) { // we can run async
                                    Yii::info("Job {$job_config['class']} queued for asynchronous execution.", 'scheduler');
                                    try {
                                        $this->queueJob($job_id, $job_config, $job_cache_key, $job_index);
                                        continue; // Successfully queued, move to next job
                                    } catch (\Throwable $e) {
                                        Yii::error("Failed to queue job {$job_config['class']}: " . $e->getMessage(), 'scheduler');
                                        $had_errors = true;
                                        continue; // Skip to next job
                                    }
                                } else {
                                    // run sync
                                    Yii::info("Job {$job_config['class']} executing synchronously (with cache tracking).", 'scheduler');
                                    $result = $this->runJob($job_id, $job_config, $job_cache_key, $job_index);
                                    if(!$result) {
                                        $had_errors = true;
                                    }
                                }

                            } else { // we don't have a cache to track running jobs, so just run the job without extra checks not ideal!
                                Yii::info("Job {$job_config['class']} executing synchronously (no cache available).", 'scheduler');
                                $result = $this->runJob($job_id, $job_config, $job_cache_key, $job_index);
                                if(!$result) {
                                    $had_errors = true;
                                }
                            }
                        // Cleanup now handled inside SafeJobWrapper::execute() via finalizeRuntimeJob()

                    } else {
                        Yii::info("Job {$job_config['class']} skipped (concurrency or lock constraint).", 'scheduler');
                    }
                }
            } catch (\Throwable $e) {
                Yii::error("Unexpected error processing job {$job_config['class']}: " . $e->getMessage(), 'scheduler');
                $had_errors = true;
                // Continue with next job rather than crashing entire scheduler
            }
        }
        
        return !$had_errors; // Return false if any job had errors
    }

    /**
     * Execute a job synchronously using SafeJobWrapper for crash protection.
     *
     * @param int $job_id
     * @param array $job_config
     * @param string $job_cache_key
     * @param int $job_index
     * @return bool True on success reported by the job, false otherwise
     */
    private function runJob(int $job_id, array $job_config, string $job_cache_key, int $job_index): bool { // sync running
        // Use the same SafeJobWrapper for consistency with queued execution.
        try {
            $wrapper = new SafeJobWrapper([
                'innerClass' => $job_config['class'],
                'innerConfig' => [
                    'job_id' => $job_id,
                    'job_config' => $job_config,
                    'job_cache_key' => $job_cache_key,
                    'job_index' => $job_index,
                ],
            ]);

            // For synchronous execution we pass null queue; SafeJobWrapper captures result.
            $wrapper->execute(null);
            $ret = $wrapper->lastResult ?? false;

            $exit = $ret ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
            Yii::info("Executed job {$job_config['class']} exit={$exit} (sync, wrapped).", 'scheduler');

            return $ret;
        } catch (\Throwable $e) {
            Yii::error("Wrapper unexpected exception for job {$job_config['class']}: " . $e->getMessage(), 'scheduler');
            return false;
        }
    }

    /**
     * Enqueue a job for asynchronous execution wrapped by SafeJobWrapper.
     * On success, persists the produced queue id (qid) into the run cache.
     * On failure, attempts to finalize the runtime job entry.
     *
     * @param int $job_id
     * @param array $job_config
     * @param string $job_cache_key
     * @param int $job_index
     * @return int Queue job id returned by the queue driver
     * @throws \RuntimeException when queue component is missing
     * @throws \Throwable when queue push fails
     */
    private function queueJob(int $job_id, array $job_config, string $job_cache_key, int $job_index) : int { // async running
        if(!$this->queue) {
            throw new \RuntimeException('Cannot queue job: queue component not configured.');
        }

        // class existance checked in initJobs
        try {
            // Wrap the actual job into a SafeJobWrapper to protect queue worker from crashes
            $queue_job_id = $this->queue->push(new SafeJobWrapper([
                'innerClass' => $job_config['class'],
                'innerConfig' => [
                    'job_id' => $job_id,
                    'job_config' => $job_config,
                    'job_cache_key' => $job_cache_key,
                    'job_index' => $job_index,
                ],
            ]));

            // Update cache with queue ID atomically
            if(isset($this->_run_cache[$job_cache_key][$job_index])) {
                $this->_run_cache[$job_cache_key][$job_index]['qid'] = $queue_job_id;
                $this->flushRunCache(); // persist immediately
                Yii::info("Queue job {$queue_job_id} registered for {$job_config['class']}", 'scheduler');
            } else {
                Yii::warning("Job index {$job_index} not found in run cache after queueing. Race condition?", 'scheduler');
            }

            return $queue_job_id;
        } catch (\Throwable $e) {
            Yii::error("Failed to queue job {$job_config['class']}: " . $e->getMessage(), 'scheduler');
            // Clean up the runtime job entry since queueing failed
            // finalize in-memory and persistent cache best-effort
            $this->finalizeRuntimeJob($job_cache_key, $job_index);
            throw $e;
        }

        // Enqueue the job
    }  

    /**
     * Decide whether a job needs to run at the trigger time based on its 'run' spec.
     * Supported patterns: wildcard (*), exact number, step (every n units), range (a-b), list (a,b,c).
     *
     * @param int $job_id Configuration index
     * @param array $job_config Validated job configuration
     * @return bool True if all pattern parts match current trigger time
     */
    private function needsToRun(int $job_id, array $job_config): bool {
        // Determine if the job needs to run based on its configuration
        foreach ($job_config['run'] as $key => $rawPattern) {
            $normalizedKey = $this->normalizeScheduleField((string)$key);
            if (!array_key_exists($normalizedKey, $this->_trigger_time)) {
                Yii::warning("Unknown or unsupported schedule key '{$key}' (normalized: '{$normalizedKey}') for job #{$job_id}.", 'scheduler');
                return false;
            }

            $current = (int)$this->_trigger_time[$normalizedKey];
            $pattern = is_string($rawPattern) ? trim($rawPattern) : (string)$rawPattern;

            // Fast path wildcard
            if ($pattern === '*') { continue; }

            if (!$this->patternMatches($current, $pattern)) {
                return false; // any field mismatch blocks the job this tick
            }
        }
        return true;
    }

    /**
     * Normalize schedule field names to getdate() keys.
     * Currently maps 'day' -> 'mday'. All other keys returned unchanged.
     */
    private function normalizeScheduleField(string $key): string
    {
        if ($key === 'day') { return 'mday'; }
        return $key;
    }

    /**
     * Match a single getdate() component against a cron-like pattern.
     * Supports:
     * - Wildcard (*),
     * - Lists (a,b,c),
     * - Ranges (a-b) with optional step (a-b/s),
    * - Global step (every s units) using prefix asterisk-slash (string starting with star followed by slash).
     */
    private function patternMatches(int $current, string $pattern): bool
    {
        // Split by comma for OR semantics across tokens
        $tokens = array_map('trim', explode(',', $pattern));
        foreach ($tokens as $token) {
            if ($token === '') { continue; }
            if ($token === '*') { return true; }

            // Global step: */s
            if (strlen($token) > 2 && $token[0] === '*' && $token[1] === '/') {
                $step = (int)substr($token, 2);
                if ($step > 0 && ($current % $step) === 0) { return true; }
                continue;
            }

            // Range with optional step: a-b or a-b/s
            if (preg_match('/^(\d+)-(\d+)(?:\/(\d+))?$/', $token, $m)) {
                $from = (int)$m[1];
                $to   = (int)$m[2];
                $step = isset($m[3]) ? (int)$m[3] : 1;
                if ($from <= $to && $step > 0) {
                    if ($current >= $from && $current <= $to && ((($current - $from) % $step) === 0)) {
                        return true;
                    }
                }
                continue;
            }

            // Exact numeric
            if (ctype_digit($token)) {
                if ((int)$token === $current) { return true; }
                continue;
            }
            // Unsupported token → no match; try next token
        }
        return false;
    }

    
    /**
     * Check concurrency/single-instance constraints for a job against current run cache.
     * Prevents launching another instance if single_instance = true and an entry is present.
     *
     * @param int $job_id Configuration index
     * @param array $job_config Validated job configuration
     * @return bool True when job is allowed to start
     */
    private function canRun(int $job_id, array $job_config): bool {
        // Check locks, concurrency, old run status, etc

        $job_cache_key = $this->cacheRunKey($job_config);
        
        if(isset($job_config['single_instance']) && $job_config['single_instance'] === true && isset($this->_run_cache[$job_cache_key])) {
            $running_jobs = $this->_run_cache[$job_cache_key];
            
            if(empty($running_jobs)) {
                // Empty array, job can run
                return true;
            }
            
            $first_job = reset($running_jobs);
            
            if($first_job === false) {
                // Shouldn't happen but safeguard for empty array
                return true;
            }
            
            $running_time = time() - ($first_job['start_time'] ?? 0);

            Yii::warning("Job {$job_config['class']} is already running for {$running_time} seconds.", 'scheduler');
            return false;
        }
        return true;
    }

    public function getLoadedConfig() : array {
        return array_merge($this->config, $this->_loaded_jobs);
    }



    // private function addJobToGlobalCache() {
    //     //            'timestamp' => time(),
    //         'pid' => getmypid(),
    //         'host' => php_uname('n'),
    //         'qid' => null,
    // }
}
