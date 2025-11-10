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

            // Call the job's execute method; any return value is ignored by queue
            $result = $job->execute($queue);
            // Capture result for synchronous runners
            $this->lastResult = (bool)$result;
        } catch (\Throwable $e) {
            $jobClass = $this->innerClass ?? 'unknown';
            Yii::error("SafeJobWrapper caught exception in {$jobClass}: " . $e->getMessage(), 'scheduler');
            $this->lastResult = false;
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
