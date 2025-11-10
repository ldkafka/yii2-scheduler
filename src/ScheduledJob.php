<?php
namespace ldkafka\scheduler;

use Yii;
use yii\queue\JobInterface;
use yii\base\BaseObject;
use ldkafka\scheduler\Scheduler;

/**
 * Base class for scheduled jobs.
 *
 * Contract:
 *  - Subclasses MUST implement execute() to perform job work and return true on success.
 *  - The scheduler injects $scheduler and $job_config upon instantiation.
 *  - When used with yiisoft/yii2-queue, execute($queue) will be called by a worker.
 *
 * Lock lifecycle:
 *  - The Scheduler acquires a cache-based lock before enqueuing/running.
 *  - On normal completion, the controller releases the lock; the destructor here provides a safety net
 *    to clear the lock if the process ends unexpectedly.
 */
abstract class ScheduledJob extends BaseObject implements JobInterface
{
    /** @var array|null original job configuration */
    public $job_id;
    public $job_config;
    public $job_cache_key; // cache key for the job lock
    public $job_index; // index in the runnig jobs cache array

    /**
     * Execute job logic. Return true on success, false on failure.
     *
     * Implementation notes:
     *  - Keep the method idempotent where practical.
     *  - Use Yii::info/warning/error with category 'scheduler' for consistent logs.
     *
     * @param \yii\queue\Queue|null $queue Optional queue instance when executed by a worker
     * @return bool truthy success indicator
     * @throws \Throwable for unrecoverable errors (controller will log and release lock)
     */
    public function execute($queue = null)
    {
        throw new \yii\base\NotSupportedException('You must implement the execute() method in your job class.');
    }

    /**
     * Best-effort job cleanup on object destruction.
     * This is a backup for certain corner cases in addition to the cleanup in Scheduler
     */
    public function __destruct()
    {
        Scheduler::jobCleanup($this);
    }
}
