<?php
namespace ldkafka\scheduler;

use Yii;
use yii\queue\JobInterface;
use yii\base\BaseObject;

/**
 * SafeJobWrapper
 *
 * A protective wrapper that executes another JobInterface implementation
 * inside a try/catch to prevent exceptions from propagating to the queue
 * worker and crashing the worker loop.
 *
 * Usage: The Scheduler enqueues this wrapper instead of the raw job class,
 * passing the original job class name and constructor config via properties.
 */
class SafeJobWrapper extends BaseObject implements JobInterface
{
    /** @var string Fully qualified class name of the inner job (must implement execute()) */
    public string $innerClass;

    /** @var array Constructor config for the inner job (passed to BaseObject) */
    public array $innerConfig = [];

    /**
     * Result of the inner execute() call when run synchronously.
     * For queued execution the queue worker ignores return values;
     * we store it here so synchronous callers can inspect it.
     */
    public ?bool $lastResult = null;

    /**
     * Execute inner job safely without letting exceptions bubble up.
     *
     * @param \yii\queue\Queue|null $queue
     * @return void
     */
    public function execute($queue)
    {
        $job = null;
        try {
            if (!class_exists($this->innerClass)) {
                Yii::error("SafeJobWrapper: inner class {$this->innerClass} not found", 'scheduler');
                $this->lastResult = false;
                return; // nothing to do
            }

            $job = new $this->innerClass($this->innerConfig);

            // Trigger EVENT_JOB_BEFORE_RUN for monitoring
            $scheduler = (\Yii::$app->has('scheduler') ? \Yii::$app->get('scheduler') : null);
            $startTime = microtime(true);
            if ($scheduler instanceof Scheduler) {
                $event = new SchedulerJobEvent();
                $event->job_class = $this->innerClass;
                $event->job_config = $this->innerConfig;
                $event->start_time = $startTime;
                $scheduler->trigger(Scheduler::EVENT_JOB_BEFORE_RUN, $event);
            }

            // Call the job's execute method; any return value is ignored by queue
            $result = $job->execute($queue);
            // Capture result for synchronous runners
            $this->lastResult = (bool)$result;
            
            // Trigger EVENT_JOB_AFTER_RUN for monitoring
            if ($scheduler instanceof Scheduler) {
                $event = new SchedulerJobEvent();
                $event->job_class = $this->innerClass;
                $event->job_config = $this->innerConfig;
                $event->result = $result;
                $event->start_time = $startTime;
                $event->end_time = microtime(true);
                $scheduler->trigger(Scheduler::EVENT_JOB_AFTER_RUN, $event);
            }
        } catch (\Throwable $e) {
            $jobClass = $this->innerClass ?? 'unknown';
            Yii::error("SafeJobWrapper caught exception in {$jobClass}: " . $e->getMessage(), 'scheduler');
            $this->lastResult = false;
            
            // Trigger EVENT_JOB_ERROR for monitoring
            $scheduler = (\Yii::$app->has('scheduler') ? \Yii::$app->get('scheduler') : null);
            if ($scheduler instanceof Scheduler) {
                $event = new SchedulerJobEvent();
                $event->job_class = $this->innerClass;
                $event->job_config = $this->innerConfig;
                $event->error = $e->getMessage();
                $event->exception = get_class($e);
                $event->trace = $e->getTraceAsString();
                $event->error_time = microtime(true);
                $scheduler->trigger(Scheduler::EVENT_JOB_ERROR, $event);
            }
            
            // Do not rethrow; prevents crashing the queue worker
        } finally {
            // Best-effort cleanup: notify Scheduler to remove runtime entry
            try {
                $cacheKey = $this->innerConfig['job_cache_key'] ?? null;
                $jobIndex = $this->innerConfig['job_index'] ?? null;
                if ($cacheKey) {
                    $scheduler = (\Yii::$app->has('scheduler') ? \Yii::$app->get('scheduler') : null);
                    if ($scheduler instanceof Scheduler) {
                        $scheduler->finalizeRuntimeJob($cacheKey, is_numeric($jobIndex) ? (int)$jobIndex : null);
                    }
                }
            } catch (\Throwable $e) {
                // Swallow any cleanup errors to avoid impacting worker stability
                Yii::warning('SafeJobWrapper finalize cleanup failed: ' . $e->getMessage(), 'scheduler');
            }
        }
    }
}
