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
        'EVERY_DAY'    => ['minutes' => 10, 'hours' => 2, 'day' => '*'],
        'EVERY_WEEK'   => ['minutes' => 20, 'hours' => 6, 'wday' => 1],
        'EVERY_MONTH'  => ['minutes' => 30, 'hours' => 1, 'mday' => 1],
        'SPECIFIC_TIME'=> [],
    ];

    private $_lock_pid = null; // cache lock for scheduler process
    private $_trigger_time; // getdate() array - stores when scheduler was run

    public function init() {
        parent::init();
        $this->initJobs(); // load job configuration
    }

    public function __destruct() {
        if($this->cache && $this->_lock_pid) {
            try {
                $this->flushRunCache();
                $this->cache->delete($this->_lock_pid);
            } catch (\Throwable $e) {
                Yii::warning('Failed to flush data and release scheduler cache lock in destructor: ' . $e->getMessage(), 'scheduler');
            }
        }
    }

    private function flushRunCache() {
        $this->cache->set(self::RUNNING_JOBS_CACHE_KEY, $this->_run_cache); // flushing to disk
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
            $pid = getmypid();
            $lock_pid = $cache->getOrSet(self::SCHEDULER_LOCK_CACHE_KEY, function() use($pid) {return $pid; }, 60); // 60 seconds lock timeout - Scheduler runs every minute

            if($lock_pid == $pid) // tests if we can set a lock, or other Scheduler process is running
            {
                $this->_lock_pid = $lock_pid;
                //we acquired lock
                $this->setCache($cache);
                return true;
            } else {
                //Yii::error('Another scheduler process PID ' . $lock_pid . ' is running. Unable to acquire cache lock', 'scheduler');
                throw new \RuntimeException('Another scheduler process PID ' . $lock_pid . ' is running. Unable to acquire cache lock');
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

        Yii::warning('Running without queue support. Configure yiisoft/yii2-queue for async execution.', 'scheduler');

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
                    if($job['qid'] && $this->queue && $this->queue->isDone($job['qid'])) {
                        unset($this->_run_cache[$cache_id][$job_index]);
                        continue;
                    }

                    // clean up any stale jobs that have exceeded max_running_time
                    // TODO: implement stale job cleanup
                    /*
                    $job_config = $job['conf'] ?? null;
                    if ($job_config) {
                        $max_time = $job_config['max_running_time'] ?? self::MAX_RUNNING_TIME_DEFAULT;
                        if ($max_time > 0 && (time() - $job['start_time']) > $max_time) {
                            Yii::warning("Removing stale job {$job_config['class']} from run cache (exceeded max_running_time).", 'scheduler');
                            continue; // skip stale job
                        }
                    }
                    */
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
     * Remove a runtime job by its index.
     * @param int $job_id
     */
    public function deleteRuntimeJob(string $job_cache_key, int $job_index = null): void
    {
        if($job_index === null ) {
            unset($this->_run_cache[$job_cache_key]);
        } else {
            if (isset($this->_run_cache[$job_cache_key][$job_index])) {
                unset($this->_run_cache[$job_cache_key][$job_index]);
            }
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
    public function runJobs(): bool {
        if($this->initCache()) {
            // cache must be setup for the queue features to work, so only init queue if cache is ok
            $this->initQueue();

            // load existing persistent jobs from cache
            $this->initRunCache(); // get persistent running jobs (if any)
        }
        $this->_trigger_time = getdate(); // save time of this run

        foreach($this->getLoadedJobs() as $job_id => $job_config) {
            if($this->needsToRun($job_id, $job_config)) {
                Yii::warning("Job {$job_config['class']} needs to run.", 'scheduler');
                if($this->canRun($job_id, $job_config)) {
                    $job_cache_key = $this->cacheRunKey($job_config);  
                    $job_index = $this->addRuntimeJob($job_cache_key, $job_id, $job_config); // add to runtime jobs        
                        if($this->cache) {
                            if($this->queue) { // we can runc async
                                Yii::warning("Job {$job_config['class']} is queued to run asynchronously.", 'scheduler');
                                $this->queueJob($job_id, $job_config, $job_cache_key, $job_index);
                                continue;
                            } else {
                                // run sync
                                Yii::warning("Job {$job_config['class']} is being run synchronously with cache checks.", 'scheduler');
                                $this->runJob($job_id, $job_config, $job_cache_key, $job_index);
                            }

                        } else { // we don't have a cache to track running jobs, so just run the job without extra checks not ideal!
                            Yii::warning("Job {$job_config['class']} is being run synchronously without cache checks.", 'scheduler');
                            $this->runJob($job_id, $job_config, $job_cache_key, $job_index);
                        }
                    $this->deleteRuntimeJob($job_cache_key, $job_index); // clean up after run

                } else {
                    Yii::warning("Job {$job_config['class']} cannot run due to locks or concurrency settings.", 'scheduler');
                }
            }
        }
        
        return true; // completed successfully
    }

    private function runJob(int $job_id, array $job_config, string $job_cache_key, int $job_index) { // sync running
        try {
            $job_object = new $job_config['class']([
                'job_id' => $job_id,
                'job_config' => $job_config,
                'job_cache_key' => $job_cache_key,
                'job_index' => $job_index,
            ]);

            $ret = $job_object->execute();

            $exit = $ret ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
            Yii::warning("Executed job {$job_config['class']} exit={$exit}.", 'scheduler');

            return $ret;
        } catch (ErrorException | \Throwable $e) {
            Yii::error("Exception running job {$job_config['class']}: " . $e->getMessage(), 'scheduler');
            //return ExitCode::UNSPECIFIED_ERROR;
        }
        
        return false;
        // Execute the job
    }

    private function queueJob(int $job_id, array $job_config, string $job_cache_key, int $job_index) : int { // async running
        if(!$this->queue) {
            throw new \RuntimeException('Cannot queue job: queue component not configured.');
        }

        // class existance checked in initJobs

        $queue_job_id = $this->queue->push(new $job_config['class']([
            'job_id' => $job_id,
            'job_config' => $job_config,
            'job_cache_key' => $job_cache_key,
            'job_index' => $job_index,
        ]));

        $this->_run_cache[$job_cache_key][$job_index]['qid'] = $queue_job_id;
        $this->flushRunCache(); // update cache with queue job id

        return $queue_job_id;

        // Enqueue the job
    }  

    private function needsToRun(int $job_id, array $job_config): bool {
        // Determine if the job needs to run based on its configuration
        foreach ($job_config['run'] as $key => $val) {
            $val = trim($val);
            if ($val === '*') { // wildcard
                continue;
            }
            if (!isset($this->_trigger_time[$key])) {
                return false;
            }
            $current = $this->_trigger_time[$key];
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
     * Check if a job can run based on locks and concurrency settings.
     * 
     * @param int $job_id Job configuration index
     * @param array $job_config Job configuration array
     * @return bool True if job can run, false if blocked by lock or settings
     */
    private function canRun(int $job_id, array $job_config): bool {
        // Check locks, concurrency, old run status, etc

        $job_cache_key = $this->cacheRunKey($job_config);
        
        if(isset($job_config['single_instance']) && $job_config['single_instance'] === true && isset($this->_run_cache[$job_cache_key])) {
            $running_jobs = $this->_run_cache[$job_cache_key];
            $first_job = reset($running_jobs);
            $running_time = time() - ($first_job['start_time'] ?? 0);

            Yii::warning("Job {$job_config['class']} is already running for {$running_time} seconds.", 'scheduler');
            return false;
        }
        return true;
    }

    public static function jobCleanup(ScheduledJob $job): void // called as a safeguard for certain corner cases from queue job destructor
    {
        /*
        if($job->job_cache_key && $job->job_index) {
            try {
                $this->deleteRuntimeJob($job->job_cache_key, $job->job_index);
            } catch (\Throwable $e) {
                Yii::warning('Failed to clean up job from run cache in destructor: ' . $e->getMessage(), 'scheduler');
            }
        }
            */
    }

    public function showConfig() : string {
        return print_r(array_merge($this->config, $this->_loaded_jobs), true);
    }



    // private function addJobToGlobalCache() {
    //     //            'timestamp' => time(),
    //         'pid' => getmypid(),
    //         'host' => php_uname('n'),
    //         'qid' => null,
    // }
}
