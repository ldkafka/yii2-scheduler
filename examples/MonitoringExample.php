<?php
/**
 * Monitoring Events Example
 * 
 * This example demonstrates how to integrate the scheduler with a monitoring system
 * by listening to scheduler events and logging/tracking job execution metrics.
 * 
 * Usage in your console/config/main.php:
 * 
 * return [
 *     'components' => [
 *         'scheduler' => [
 *             'class' => ldkafka\scheduler\Scheduler::class,
 *             'on jobBeforeRun' => [\common\helpers\SchedulerMonitor::class, 'onJobBeforeRun'],
 *             'on jobAfterRun' => [\common\helpers\SchedulerMonitor::class, 'onJobAfterRun'],
 *             'on jobError' => [\common\helpers\SchedulerMonitor::class, 'onJobError'],
 *             'on jobBlocked' => [\common\helpers\SchedulerMonitor::class, 'onJobBlocked'],
 *             'config' => [ ... ],
 *             'jobs' => [ ... ],
 *         ],
 *     ],
 * ];
 */

namespace common\helpers;

use Yii;
use ldkafka\scheduler\SchedulerJobEvent;
use yii\base\Event;

class SchedulerMonitor
{
    /**
     * Called before a job starts executing
     * Use this to initialize monitoring context, start timers, etc.
     * 
     * @param SchedulerJobEvent $event
     */
    public static function onJobBeforeRun($event)
    {
        $jobClass = $event->job_class;
        $startTime = $event->start_time;
        
        Yii::info("Job starting: {$jobClass} at " . date('Y-m-d H:i:s', (int)$startTime), 'scheduler.monitor');
        
        // Example: Store in monitoring system
        // Yii::$app->monitoring->recordJobStart([
        //     'job_class' => $jobClass,
        //     'start_time' => $startTime,
        //     'status' => 'running',
        // ]);
    }
    
    /**
     * Called after a job completes successfully
     * Use this to record metrics, durations, success counts, etc.
     * 
     * @param SchedulerJobEvent $event
     */
    public static function onJobAfterRun($event)
    {
        $jobClass = $event->job_class;
        $duration = $event->end_time - $event->start_time;
        $result = $event->result;
        
        Yii::info("Job completed: {$jobClass} in " . round($duration, 3) . "s (result: " . ($result ? 'success' : 'false') . ")", 'scheduler.monitor');
        
        // Example: Record metrics
        // Yii::$app->monitoring->recordJobCompletion([
        //     'job_class' => $jobClass,
        //     'duration' => $duration,
        //     'result' => $result,
        //     'status' => 'completed',
        //     'timestamp' => $event->end_time,
        // ]);
        
        // Example: Alert on slow jobs
        // if ($duration > 60) {
        //     Yii::warning("Slow job detected: {$jobClass} took {$duration}s", 'scheduler.monitor');
        // }
    }
    
    /**
     * Called when a job throws an exception
     * Use this to record errors, send alerts, track failure rates, etc.
     * 
     * @param SchedulerJobEvent $event
     */
    public static function onJobError($event)
    {
        $jobClass = $event->job_class;
        $error = $event->error;
        $exception = $event->exception;
        $trace = $event->trace;
        
        Yii::error("Job failed: {$jobClass} - {$exception}: {$error}", 'scheduler.monitor');
        
        // Example: Record error event
        // Yii::$app->monitoring->recordJobError([
        //     'job_class' => $jobClass,
        //     'error_message' => $error,
        //     'exception_class' => $exception,
        //     'stack_trace' => $trace,
        //     'status' => 'error',
        //     'timestamp' => $event->error_time,
        // ]);
        
        // Example: Send alert for critical jobs
        // if (in_array($jobClass, self::getCriticalJobs())) {
        //     Yii::$app->mailer->compose()
        //         ->setTo('admin@example.com')
        //         ->setSubject("Critical Scheduler Job Failed: {$jobClass}")
        //         ->setTextBody("Error: {$error}\n\nTrace:\n{$trace}")
        //         ->send();
        // }
    }
    
    /**
     * Called when a job is blocked from running (e.g., single-instance lock)
     * Use this to track blocked jobs, detect long-running jobs, etc.
     * 
     * @param Event $event Standard Yii event (data in $event->data)
     */
    public static function onJobBlocked($event)
    {
        $data = $event->data;
        $jobClass = $data['job_config']['class'];
        $reason = $data['reason'];
        $runningTime = $data['running_time'];
        
        Yii::warning("Job blocked: {$jobClass} - {$reason} (running for {$runningTime}s)", 'scheduler.monitor');
        
        // Example: Track blocked attempts
        // Yii::$app->monitoring->recordJobBlocked([
        //     'job_class' => $jobClass,
        //     'reason' => $reason,
        //     'running_time' => $runningTime,
        //     'status' => 'blocked',
        // ]);
        
        // Example: Alert on long-running jobs
        // $maxTime = $data['job_config']['max_running_time'] ?? 0;
        // if ($maxTime > 0 && $runningTime > ($maxTime * 0.8)) {
        //     Yii::warning("Job nearing timeout: {$jobClass} ({$runningTime}s / {$maxTime}s)", 'scheduler.monitor');
        // }
    }
    
    /**
     * Example: Get list of critical jobs that require immediate alerts
     * @return array
     */
    private static function getCriticalJobs(): array
    {
        return [
            'common\jobs\PaymentProcessingJob',
            'common\jobs\DataSyncJob',
            'common\jobs\SecurityAuditJob',
        ];
    }
    
    /**
     * Example: Integration with external monitoring service
     * 
     * public static function sendToMonitoringService($eventType, $data)
     * {
     *     $client = new \GuzzleHttp\Client();
     *     try {
     *         $client->post('https://monitoring.example.com/api/events', [
     *             'json' => [
     *                 'service' => 'scheduler',
     *                 'event_type' => $eventType,
     *                 'data' => $data,
     *                 'timestamp' => microtime(true),
     *                 'server' => gethostname(),
     *             ]
     *         ]);
     *     } catch (\Exception $e) {
     *         Yii::error("Failed to send monitoring event: " . $e->getMessage(), 'scheduler.monitor');
     *     }
     * }
     */
}
