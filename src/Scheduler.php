<?php
namespace ldkafka\scheduler;

use Yii;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\console\Application;
use yii\base\InvalidConfigException;
use yii\helpers\Inflector;

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

    /** @var array base component config (names of queue/cache or objects) */
    public $config = [
        'queue' => null,
        'cache' => null,
    ];

    /** @var array job definitions (same shape as legacy aura_v5 config) */
    public $jobs = [];

    /** @var array symbolic schedule templates expanded inside controller */
    public $running_time = [
        'EVERY_MINUTE' => ['minutes' => '*'],
        'EVERY_HOUR'   => ['minutes' => 1, 'hours' => '*'],
        'EVERY_DAY'    => ['minutes' => 10, 'hours' => 2, 'day' => '*'],
        'EVERY_WEEK'   => ['minutes' => 20, 'hours' => 6, 'wday' => 1],
        'EVERY_MONTH'  => ['minutes' => 30, 'hours' => 1, 'mday' => 1],
        'SPECIFIC_TIME'=> [],
    ];

    /** @var array runtime validated objects */
    private $runtime_config = [
        'queue' => null,
        'cache' => null,
    ];

    /** @var array queued for this tick */
    private $runtime_jobs = [];

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
    public function addRuntimeJob(array $job): int
    {
        $this->runtime_jobs[] = $job;
        return array_key_last($this->runtime_jobs);
    }

    /**
     * Get all job configs selected for this tick.
     * @return array
     */
    public function getRuntimeJobs(): array
    {
        return $this->runtime_jobs;
    }

    /**
     * Remove a runtime job by its index.
     * @param int $job_id
     */
    public function deleteRuntimeJob(int $job_id): void
    {
        if (array_key_exists($job_id, $this->runtime_jobs)) {
            unset($this->runtime_jobs[$job_id]);
        }
    }

    /**
     * Inject cache object (global Yii cache expected).
     * @param mixed $cache cache instance
     */
    public function setCache($cache): void
    {
        $this->runtime_config['cache'] = $cache;
    }

    /**
     * Get cache instance used for locks.
     * @return mixed
     */
    public function getCache()
    {
        return $this->runtime_config['cache'];
    }

    /**
     * Inject queue object (yiisoft/yii2-queue implementation).
     * @param mixed $queue queue instance
     */
    public function setQueue($queue): void
    {
        $this->runtime_config['queue'] = $queue;
    }

    /**
     * Get queue instance used for async job execution.
     * @return mixed
     */
    public function getQueue()
    {
        return $this->runtime_config['queue'];
    }

    /**
     * Build unique run key for lock tracking.
     * @param array $job_config
     * @return string cache key
     */
    public function cacheRunKey(array $job_config): string
    {
        return 'scheduler_' . $job_config['class'] . '_' . md5(serialize($job_config['run']));
    }
}
